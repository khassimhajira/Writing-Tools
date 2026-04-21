<?php

use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\StripeClient;
/**
 * @table paysystems
 * @id stripe
 * @title Stripe
 * @visible_link https://stripe.com/
 * @logo_url stripe.png
 * @recurring amember
 */
class Am_Paysystem_Stripe extends Am_Paysystem_CreditCard_Token implements Am_Paysystem_TokenPayment
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '6.3.39';

    const TOKEN = 'stripe_token';
    const CC_EXPIRES = 'stripe_cc_expires';
    const CC_MASKED = 'stripe_cc_masked';
    const CUSTOMER_ID = 'customer-id';
    const PAYMENT_METHOD = 'payment-method';
    const PAYMENT_INTENT = 'payment-intent';
    const SETUP_INTENT = 'setup-intent';
    const API_ENDPOINT = 'https://api.stripe.com/v1/';
    const API_VERSION = '2020-08-27';

    protected $defaultTitle = "Stripe";
    protected $defaultDescription = "Credit Card Payments";
    protected ?StripeClient $stripeClient;
    protected ?InvoiceLog $log = null;

    public function allowPartialRefunds()
    {
        return true;
    }

    function getOldStripeTokenKey()
    {
        return $this->getId()."_token";
    }

    function getStripeClient(): StripeClient
    {

        if (empty($this->stripeClient)) {
            $this->stripeClient = new StripeClient($this->getConfig('secret_key'));
        }
        return $this->stripeClient;
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    function isCCBrickSupported()
    {
        return true;
    }

    function addInvoiceLog(string $title, array $request, array $response, ?Invoice $invoice = null)
    {
        if (!$this->log) {
            if (!$invoice) {
                $invoice = $this->invoice;
            }
            $this->log = $this->getDi()->invoiceLogRecord;
            if ($invoice && $invoice->pk()) {
                $this->log->invoice_id = $invoice->invoice_id;
                $this->log->user_id = $invoice->user_id;
            }
            $this->log->paysys_id = $this->getId();
            $this->log->remote_addr = $_SERVER['REMOTE_ADDR'];
            foreach ($this->getConfig() as $k => $v)
                if (is_scalar($v) && (strlen($v) > 4))
                    $this->log->mask($v);
            $this->log->title = $title;
        }
        $this->log->add([
            'request' => $request,
            'response' => $response
        ]);
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('public_key', ['class' => 'am-el-wide'])
            ->setLabel('Publishable Key')
            ->addRule('required')
            ->addRule('regex', 'Publishable Key must start with "pk_"', '/^pk_.*$/');

        $form->addSecretText('secret_key', ['class' => 'am-el-wide'])
            ->setLabel("Restricted API Key\n
            Generate Restricted API key at Stripe Dashboard -> Developers -> API Keys -> Restricted Keys -> Create New key,\n
            required write permissions: Charges, Customers, Disputes, PaymentIntents, PaymentMethods, SetupIntents, Checkout Sessions
            ")
            ->addRule('required')
            ->addRule('regex', 'Secret Key must start with "rk_"', '/^(sk_)|(rk_).*$/');

        $fs = $this->getExtraSettingsFieldSet($form);

        $fs->addAdvCheckbox('only_card_method')->setLabel(___('Disable Automatic Payment Methods
            When checked, Stripe will display only Card Payment method
        '));
        $fs->addAdvCheckbox('efw_refund')->setLabel('Refund Transaction on Early Fraud Warning
        You need to configure Stripe to send radar.early_fraud_warning.created event notifications
        Plugin will refund transactions when EFW is created before receiving dispute, 
        also if subscription is recurring then it will be cancelled');

        $fs->addAdvCheckbox("checkout-mode")
            ->setLabel(___('Use Stripe Checkout to collect Payment information
            user will be redirected to Stripe to specify information first time.
            Recurrent payments will be handled by aMember
            '));
        $fs->addAdvCheckbox("create-mandates")
            ->setLabel(___('Support RBI e-mandates
            <a href="https://stripe.com/docs/india-recurring-payments">to support recurring payments for India</a>
            '));
        $fs->addAdvCheckbox("shipping-billing")
            ->setLabel(___('Ask for shipping and billing address
            <a href="https://support.stripe.com/questions/failed-international-payments-from-stripe-accounts-in-india">to support International Payments from Stripe Accounts in India</a>
            '));
        $fs->addAdvCheckbox('accept_pending')->setLabel(___("Activate Delayed Payments Immediately\n
            For bank payment systems like SEPA Direct Debit, payment processing could take a several days. 
            If that option is enabled, aMember will activate payment(add an access) immediately 
            once it gets payment processing notification from Stripe"));
        $fs->addSelect('theme')
            ->setLabel("Theme\nof stripe elements")
            ->loadOptions([
                'stripe' => 'Stripe',
                'night' => 'Night',
                'flat' => 'Flat',
            ]);
    }

    function _process($invoice, $request, $result)
    {
        if ($this->getConfig('checkout-mode'))
        {
            $tr = new Am_Paysystem_Transaction_Stripe_CreateCheckoutSession($this,$invoice);
            $tr->run($result);
            if ($result->isSuccess())
            {
                $result->reset();
                $session_id = $tr->getUniqId();
                $public_key = $this->getConfig('public_key');
                $cancel_url = $this->getCancelUrl();

                $a = new Am_Paysystem_Action_HtmlTemplate("pay.phtml");

                $a->invoice = $invoice;
                $msg = ___('Please wait while you are being redirected');
                $a->form  = <<<CUT
<div>{$msg}</div>
<script src="https://js.stripe.com/v3/"></script>
<script>
const stripe = Stripe('{$public_key}');
stripe.redirectToCheckout({
    sessionId: '{$session_id}'
}).then(function (result) {
    document.location.href = '{$cancel_url}';
});
</script>
CUT;
                $result->setAction($a);
            }
        } else {
            parent::_process($invoice, $request, $result);
        }
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        try {
            $token = $this->validateToken($invoice, $cc->getToken());
        } catch (Exception $e) {
            $result->setFailed($e->getMessage());
            return;
        }

        if ($doFirst && (doubleval($invoice->first_total) <= 0)) { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $tr = new Am_Paysystem_Transaction_Stripe($this, $invoice, $doFirst);
            $tr->run($result);
        }
    }

    public function loadCreditCard(Invoice $invoice)
    {
        if ($this->storesCcInfo()){
            $cc = $this->getDi()->ccRecordTable->findFirstBy(['invoice_id' => $invoice->pk(), 'paysys_id' => $invoice->paysys_id]);
            if (!$cc)
                $cc = $this->getDi()->ccRecordTable->findFirstByUserIdPaysysId($invoice->user_id, $invoice->paysys_id);

            return $cc;
        }
    }

    public function canReuseCreditCard(Invoice $invoice)
    {
        $x = $this->getDi()->CcRecordTable->findBy(['user_id' => $invoice->user_id, 'paysys_id' => $this->getId()]);
        $ret = [];
        foreach ($x as $cc) {
            $t = json_decode($cc->token, true);
            if (empty($t['customer-id'])) continue;

            if (!empty($t[self::PAYMENT_METHOD])) {
                $_ = new Am_Paysystem_Result();
                $tr = new Am_Paysystem_Transaction_Stripe_GetPaymentMethod($this, $invoice, $t);
                try {
                    $tr->run($_);
                } catch (Am_Exception_Paysystem $ex) {
                    continue;
                }
                if ($_->isSuccess()) {
                    $card = $tr->getCard();
                    if (!empty($card['last4'])) {
                        $cc->cc = $cc->cc_number =  "************".$card['last4'];
                        $cc->cc_expire = $card['exp_month'].($card['exp_year']-2000);
                    }
                }
            }

            $ret[] = $cc;
        }
        return $ret;
    }

    public function getUpdateCcLink($user)
    {
        if ($this->findInvoiceForTokenUpdate($user->pk())) {
            return $this->getPluginUrl('update');
        }
    }

    function getCardElementId()
    {
        return 'qfauto-0';
    }

    public function onDeletePersonalData(Am_Event $event)
    {
        $user = $event->getUser();
        $user->data()
            ->set(self::CC_EXPIRES, null)
            ->set(self::CC_MASKED, null)
            ->set($this->getOldStripeTokenKey(), null)
            ->update();
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        $tr = new Am_Paysystem_Transaction_Stripe_Refund($this, $payment->getInvoice(), $payment->receipt_id, ($amount == $payment->amount) ? 0 : $amount);
        $tr->run($result);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return Am_Paysystem_Transaction_Stripe_Webhook::create($this, $request, $response, $invokeArgs);
    }

    function directAction($request, $response, $invokeArgs)
    {
        $action = $request->getActionName();
        if ($action != 'ipn') {
            $this->getDi()->rateLimit->check('cc', [$this->getDi()->request->getClientIp()], [
                3600 => AM_CC_RATE_LIMIT_HOURLY * 2,
                86400 => AM_CC_RATE_LIMIT_DAILY * 2,
                2592000 => AM_CC_RATE_LIMIT_MONTHLY * 2,

            ]); // attemps per ip
            if($this->getDi()->auth->getUserId())
                $this->getDi()->rateLimit->check('cc', [$this->getDi()->auth->getUserId()], [
                    3600 => AM_CC_RATE_LIMIT_HOURLY * 2,
                    86400 => AM_CC_RATE_LIMIT_DAILY * 2,
                    2592000 => AM_CC_RATE_LIMIT_MONTHLY * 2,

                ]); // attempts per user
        }
        if (in_array($action, ['create', 'confirm'])) {
            $result = new Am_Paysystem_Result();

            try {
                $payload = @json_decode($request->getRawBody(), true);
                if (empty($payload)) {
                    throw new Am_Exception_InternalError(___('Access denied'));
                }

                $invoice = $this->getDi()->invoiceTable->findBySecureId($payload['invoice_id'], $this->getId());

                if (empty($invoice)) {
                    throw new Am_Exception_Security(___("Unable to fetch invoice record"));
                }

                switch ($action) {
                    case 'create' :
                        if (empty($payload['payment_method_id'])) {
                            throw new Am_Exception_InternalError(___("Payment method is empty"));
                        }

                        $token = $this->updateToken($invoice, [self::PAYMENT_METHOD => $payload['payment_method_id']]);

                        if (empty($token[self::CUSTOMER_ID])) {
                            $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this,$invoice);
                            $tr->run($result);
                            if ($result->isFailure()) {
                                break;
                            } else {
                                $result->reset();
                            }
                        }

                        $tr = new Am_Paysystem_Transaction_Stripe_PaymentIntentsCreate($this, $invoice, $invoice->isFirstPayment());
                        $tr->run($result);
                        break;
                    case 'confirm' :
                        if (empty($payload['payment_intent_id'])) {
                            throw new Am_Exception_InternalError(___("Payment intent is empty"));
                        }
                        $this->updateToken($invoice, [self::PAYMENT_INTENT => $payload['payment_intent_id']]);

                        $tr = new Am_Paysystem_Transaction_Stripe_PaymentIntentsConfirm($this, $invoice, $invoice->isFirstPayment());
                        $tr->run($result);
                        break;
                }

                $intent = $tr->getObject();

                if ($result->isFailure()) {
                    if (!empty($intent['status']) && in_array($intent['status'],
                            ['requires_action', 'requires_source_action'])) {
                        $response->setBody(json_encode(['requires_action' => 1, 'paymentIntent' => $intent]));
                    } else {
                        $response->setBody(json_encode(['error' => ['message' => $result->getLastError()]]));
                    }
                } else {
                    $token = $this->getToken($invoice);
                    $result = new Am_Paysystem_Result();
                    if (!empty($token[self::CUSTOMER_ID])) {
                        $tr = new Am_Paysystem_Transaction_Stripe_GetPaymentMethod($this, $invoice);
                        $tr->run($result);
                        if ($result->isSuccess() && empty($tr->getCustomer())) {
                            $result->reset();

                            $tr = new Am_Paysystem_Transaction_Stripe_AttachCustomer($this, $invoice,
                                $token[self::PAYMENT_METHOD], $token[self::CUSTOMER_ID]);
                            $tr->run($result);
                        }
                    } else {
                        $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this, $invoice,
                            $token[self::PAYMENT_METHOD], 'payment_method');
                        $tr->run($result);
                    }

                    $response->setBody(json_encode($intent));
                }
            } catch (Exception $e) {
                $response->setBody(json_encode(['error' => ['message' => $e->getMessage()]]));
            }
        } else {
            parent::directAction($request, $response, $invokeArgs);
        }
    }

    public function validateToken(Invoice $invoice, $token = null)
    {
        if (empty($token)) {
            return parent::validateToken($invoice);
        }

        if (is_string($token)) {
            if (strpos($token, 'tok_') === 0) {
                $token = $this->_legacySaveToken($invoice, $token);
            } else {
                $token = @json_decode($token, true);
                if (empty($token)) {
                    throw new Am_Exception_Paysystem_TransactionInvalid('Token is empty in incoming request');
                }
            }
        }

        if (!empty($token['setupIntent'])) {
            $token = $this->convertPaymentTokenToLifetime($token, $invoice);
        }

        // Legacy token, need to query for default payment method.
        $savedToken = $this->getToken($invoice);
        if (!empty($token[self::CUSTOMER_ID]) && empty($token[self::PAYMENT_METHOD]) && empty($savedToken[self::PAYMENT_METHOD])) {
            $result = new Am_Paysystem_Result();
            $tr = new Am_Paysystem_Transaction_Stripe_GetCustomerPaymentMethod($this, $invoice,
                $token[self::CUSTOMER_ID]);
            $tr->run($result);
            if ($result->isSuccess()) {
                $token[self::PAYMENT_METHOD] = $tr->getUniqId();
            } else {
                throw new Am_Exception_Paysystem_TransactionInvalid($result->getLastError());
            }
        }

        $this->saveToken($invoice, $token);
        return $token;
    }

    public function getToken(Invoice $invoice)
    {
        if ($customer_id = $invoice->getUser()->data()->get('stripe-customer-id')) {
            $tkn = [self::CUSTOMER_ID => $customer_id];

            if ($invoice->data()->get('stripe-source-id')) {
                $tkn[self::PAYMENT_METHOD] = $invoice->data()->get('stripe-source-id');
            }
            $this->getDi()->CcRecordTable->getRecordByUser($invoice->getUser(),
                $invoice->paysys_id)->updateToken($tkn)->save();

            $invoice->getUser()->data()->set('stripe-customer-id', null)->update();
        } elseif ($customer_id = $invoice->getUser()->data()->get($this->getOldStripeTokenKey())) {
            $tkn = [self::CUSTOMER_ID => $customer_id];
            // Convert to new token format
            $this->getDi()->CcRecordTable->getRecordByUser($invoice->getUser(),
                $invoice->paysys_id)->updateToken($tkn)->save();
            $invoice->getUser()->data()->set($this->getOldStripeTokenKey(), null)->update();
        }

        $userToken = $this->getDi()->CcRecordTable->getRecordByUser($invoice->getUser(), $invoice->paysys_id)->getToken();
        $invoiceToken = $this->getDi()->CcRecordTable->getRecordByInvoice($invoice)->getToken();
        return array_merge($userToken, $invoiceToken);
    }

    public function processTokenPayment(Invoice $invoice, $token = null)
    {
        $result = new Am_Paysystem_Result();
        try {
            $token = $this->validateToken($invoice, $token);
        } catch (Exception $e) {
            $result->setFailed($e->getMessage());
            return $result;
        }
        if ($invoice->isFirstPayment() && (doubleval($invoice->first_total) <= 0)) { // free trial
            $tr = new Am_Paysystem_Transaction_Free($this);
            $tr->setInvoice($invoice);
            $tr->process();
            $result->setSuccess($tr);
        } else {
            $tr = new Am_Paysystem_Transaction_Stripe($this, $invoice, $invoice->isFirstPayment());
            $tr->run($result);
        }

        return $result;
    }

    function _legacySaveToken(Invoice $invoice, $token)
    {
        $result = new Am_Paysystem_Result();

        $tr = new Am_Paysystem_Transaction_Stripe_CreateCardSource($this, $invoice, $token);
        $tr->run($result);

        if ($result->isFailure()) {
            throw new Am_Exception_Paysystem_TransactionInvalid($result->getLastError());
        }

        $source = $tr->getInfo();

        $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this, $invoice, $source['id']);
        $tr->run($result);

        if ($result->isSuccess()) {
            $ccRecord = $this->getDi()->CcRecordTable->getRecordByInvoice($invoice);
            if ($card = $tr->getCard()) {
                $card = $card[0]['card'];
                $ccRecord->cc = str_pad($card['last4'], 12, "*", STR_PAD_LEFT);
                $ccRecord->cc_expire = sprintf('%02d%02d', $card['exp_month'], $card['exp_year'] - 2000);
            }
            $ccRecord->save();
        } else {
            throw new Am_Exception_Paysystem_TransactionInvalid(implode("\n", $result->getErrorMessages()));
        }
        return $ccRecord->getToken();
    }

    function _addStylesToForm(HTML_QuickForm2_Container $form)
    {
        $f = $form;
        while ($container = $f->getContainer())
            $f = $container;

        $f->addProlog(<<<CUT
<script src="https://js.stripe.com/v3/"></script>
<style>
.StripeElement {
    box-sizing: border-box;
    padding: .5em .75em;
    border: 1px solid #ced4da;;
    border-radius: 3px;
    background-color: white;
}

.StripeElement--focus {
    background-color: #ffffcf;
    border-color: #c1def5;
    box-shadow: 0 0 2px #c1def5;
}

.StripeElement--invalid {
    border-color: #fa755a;
}

.StripeElement--webkit-autofill {
    background-color: #fefde5 !important;
}
</style>
CUT
        );
    }

    function apiCreateCustomer($data, ?Invoice $invoice = null)
    {
        $customer = $this->getStripeClient()->customers->create($data);
        $this->addInvoiceLog("Create Customer", $data, $customer->toArray(), $invoice);
        return $customer;
    }

    function apiRetrieveCharge($chargeId, $options, ?Invoice $invoice = null)
    {
        $charge = $this->getStripeClient()->charges->retrieve(
            $chargeId,
            $options
        );
        $this->addInvoiceLog('Retrieve charge', [
            'chargeId' => $chargeId,
            'options' => $options
        ], $charge->toArray(), $invoice);
        return $charge;
    }

    function apiRetrievePaymentIntent($paymentIntentId, ?Invoice $invoice = null)
    {
        $intent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        $this->addInvoiceLog("Retrieve Payment Intent", ['paymentIntentId' => $paymentIntentId], $intent->toArray(), $invoice);
        return $intent;
    }

    function apiRetrieveSetupIntent($setupIntentId, ?Invoice $invoice = null)
    {
        $intent = $this->getStripeClient()->setupIntents->retrieve($setupIntentId);
        $this->addInvoiceLog("Retrieve Setup Intent", ['setupIntentId' => $setupIntentId], $intent->toArray(), $invoice);
        return $intent;
    }

    function apiRetrievePaymentMethod($paymentMethodId, ?Invoice $invoice = null)
    {
        $paymentMethod = $this->getStripeClient()->paymentMethods->retrieve($paymentMethodId);
        $this->addInvoiceLog("Retrieve Payment Method", ['paymentMethodId' => $paymentMethodId], $paymentMethod->toArray(), $invoice);
        return $paymentMethod;
    }

    function apiAttachCustomer($paymentMethod, $customerId, ?Invoice $invoice = null)
    {
        /**
         * @var \Stripe\PaymentMethod $paymentMethod
         */
        $resp = $paymentMethod->attach([
            'customer' => $customerId
        ]);
        $this->addInvoiceLog("Attach Payment Method To Customer", [
            'paymentMethod' => $paymentMethod->toArray(),
            'customerId' => $customerId,
        ], $resp->toArray(), $invoice);
    }

    function insertPaymentFormBrick(HTML_QuickForm2_Container $form)
    {
        if ($this->invoice->isFirstPayment() && doubleval($this->invoice->first_total) == 0) {
            return $this->insertUpdateFormBrick($form);
        }

        $id = $this->getId();

        $title = ___('Credit Card Info');

        $result = new Am_Paysystem_Result();

        $token = $this->getToken($this->invoice);
        if (empty($token[self::CUSTOMER_ID])) {
            $customer = $this->apiCreateCustomer([
                'email' => $this->invoice->getEmail(),
                'name' => $this->invoice->getName(),
                'description' => 'Username:' . $this->invoice->getUser()->login
            ]);
            $token[self::CUSTOMER_ID] = $customer->id;
            $this->updateToken($this->invoice, $token);
        }
        $transaction = new Am_Paysystem_Transaction_Stripe_CreatePaymentIntent($this, $this->invoice, $this->invoice->isFirstPayment());
        $transaction->run($result);

        if ($result->isFailure()) {
            return $form->addHTML()->setHTML(sprintf("<div class='am-error'>%s</div>",
                implode(", ", $result->getErrorMessages())));
        }

        $clientSecret = $transaction->getClientSecret();

        $gr = $form->addGroup('', ['class' => 'am-no-label'])->setLabel($title);
        if ($this->getConfig('shipping-billing')) {
            $gr->addHtml()->setHtml("<div id='{$id}-address-element'></div><br/>");
            $addressElement = <<<CUT
var addressElement = elements.create('address', { mode: 'shipping' });
addressElement.mount('#{$id}-address-element');
CUT;
        } else {
            $addressElement = '';
        }
        $gr->addHtml()->setHtml("<div id='{$id}-card-element'></div>");

        $jsTokenFieldName = $this->getJsTokenFieldName();

        $gr->addText($jsTokenFieldName, "id='{$jsTokenFieldName}' style='display:none; visibility:hidden;'");

        $gr->addHidden("{$id}_validator_enable", ['value' =>1])->addRule('required');

        $this->_addStylesToForm($form);

        $jsTokenFieldName = $this->getJsTokenFieldName();
        $returnUrl = $this->getPluginUrl('thanks', [
            'invoice' => $this->invoice->getSecureId('THANKS'),
            'payment_intent'=>$transaction->getUniqId()
        ]);

        $locale = json_encode($this->getDi()->locale->getLanguage());
        $pubkey = json_encode($this->getConfig('public_key'));
        $defaultBillingDetails = [
            'name' => $this->invoice->getName(),
            'email' => $this->invoice->getEmail(),
        ];
        if ($this->invoice->getStreet()) {
            $defaultBillingDetails['address'] = [
                'city' => $this->invoice->getCity(),
                'country' => $this->invoice->getCountry(),
                'line1' => $this->invoice->getStreet(),
                'postal_code' => $this->invoice->getZip(),
                'state' => $this->invoice->getState()
            ];
        }
        $defaultBillingDetails = json_encode($defaultBillingDetails);
        $theme = json_encode($this->getConfig('theme') ?: 'stripe');
        $form->addScript()->setScript(<<<CUT
var stripe = Stripe($pubkey, {locale: $locale});
var elements = stripe.elements({clientSecret: '{$clientSecret}', appearance: window.amStripeAppearance ?? {theme:$theme}});
var card = elements.create('payment', {
defaultValues: {
    billingDetails: {$defaultBillingDetails},
}
});
card.mount('#{$id}-card-element');
$addressElement
jQuery("#{$id}-card-element").closest('.am-row')
    .addClass('paysystem-toggle')
    .addClass('paysystem-toggle-{$id}');

card.addEventListener('change', function(event) {
    const frm = jQuery('#{$id}-card-element').closest('form');
    const validator = frm.validate();
    if (event.error) {
        validator.showErrors({
            '{$jsTokenFieldName}': event.error.message
        });
    } else {
        validator.showErrors({
           '{$jsTokenFieldName}': null
        });
    }
});
jQuery('#{$id}-card-element').closest('form').on("amFormSubmit", function(event){
    const frm = jQuery(event.target);
    const callback = event.callback;
    const validator = frm.validate();
    event.callback = null; // wait for completion
    stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: '{$returnUrl}'
        },
        redirect: "if_required"
    }).then((result) => {
        if (result.error) {
            validator.showErrors({
                '{$jsTokenFieldName}': result.error.message
            });
            frm.trigger('unlockui');
        }else if (result.paymentIntent) {
            document.location.href='{$returnUrl}'
        }
    })
});
CUT
        );
    }

    function insertUpdateFormBrick(HTML_QuickForm2_Container $form)
    {
        $id = $this->getId();
        $jsTokenFieldName = $this->getJsTokenFieldName();

        $invoice = !empty($this->invoice) ? $this->invoice : $this->getDi()->invoiceRecord;

        $this->invoice = $invoice;

        $title = ___('Credit Card Info');
        $gr = $form->addGroup('')->setLabel($title);
        $gr->addHtml()->setHtml("<div id='{$id}-card-element'></div>");

        $gr->addText($jsTokenFieldName,
            "id='{$jsTokenFieldName}' style='display:none; visibility:hidden;'");
        $gr->addHidden("{$id}_validator_enable", ['value' =>1])->addRule('required');

        $this->_addStylesToForm($form);
        $result = new Am_Paysystem_Result();

        $transaction = new Am_Paysystem_Transaction_Stripe_CreateSetupIntent($this, $invoice);
        $transaction->run($result);
        if ($result->isFailure()) {
            return $form->addHTML()->setHTML(sprintf("<div class='am-error'>%s</div>",
                implode(", ", $result->getErrorMessages())));
        }
        $clientSecret = $transaction->getClientSecret();
        $locale = json_encode($this->getDi()->locale->getLanguage());
        $pubkey = json_encode($this->getConfig('public_key'));
        $theme = json_encode($this->getConfig('theme') ?: 'stripe');
        $form->addScript()->setScript(<<<CUT
var stripe = Stripe($pubkey, {locale: $locale});
var elements = stripe.elements({clientSecret: '{$clientSecret}', appearance: window.amStripeAppearance ?? {theme:$theme}});
var style = {
    base: {
        color: '#32325d',
        fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
        fontSmoothing: 'antialiased',
        fontSize: '16px',
        '::placeholder': {
            color: '#aab7c4'
        }
    },
    invalid: {
        color: '#fa755a',
        iconColor: '#fa755a'
    }
};
CUT
        );

        if (!$invoice->pk()) {
            $form->addScript()->setScript(<<<CUT
var card = elements.create('card', {style: style});
card.mount('#{$id}-card-element');
jQuery("#{$id}-card-element").closest('.am-row')
    .addClass('paysystem-toggle')
    .addClass('paysystem-toggle-{$id}');

card.addEventListener('change', function(event) {
    const frm = jQuery('#{$id}-card-element').closest('form');
    if (jQuery("#{$id}-card-element").closest('.am-row').is(':visible')) {
        const validator = frm.validate();
        if (event.error) {
            validator.showErrors({
               '{$jsTokenFieldName}' : event.error.message
            });
        } else {
            validator.showErrors({
               '{$jsTokenFieldName}' : null
            });
        }
    }
});
jQuery('#{$id}-card-element').closest('form').on("amFormSubmit", function(event){
    const frm = jQuery(event.target);
    const callback = event.callback;
    event.callback = null; // wait for completion
    if(jQuery("#{$id}-card-element").closest('.am-row').is(':visible')){
        stripe.confirmCardSetup('{$clientSecret}', {payment_method: {card: card}}).then(function(result) {
            if (result.error) {
                // Inform the user if there was an error.
                const validator = frm.validate();
                    validator.showErrors({
                   '{$jsTokenFieldName}' : result.error.message
                });
                frm.trigger('unlockui');
            } else {
               document.getElementById('{$jsTokenFieldName}').value = JSON.stringify(result);
               callback();
            }
        });
    } else {
        callback();
    }
});
CUT
            );

        } else {

            $returnUrl = $this->getPluginUrl('thanks', [
                'invoice' => $this->invoice->getSecureId('THANKS'),
                'setup_intent' => $transaction->getUniqId()
            ]);

            $form->addScript()->setScript(<<<CUT
var card = elements.create('payment', {style: style});
card.mount('#{$id}-card-element');
jQuery("#{$id}-card-element").closest('.am-row')
    .addClass('paysystem-toggle')
    .addClass('paysystem-toggle-{$id}');

card.addEventListener('change', function(event) {
    const frm = jQuery('#{$id}-card-element').closest('form');
    if (jQuery("#{$id}-card-element").closest('.am-row').is(':visible')) { 
        const validator = frm.validate();
        if (event.error) {
            validator.showErrors({
               '{$jsTokenFieldName}' : event.error.message
            });
        } else {
            validator.showErrors({
               '{$jsTokenFieldName}' : null
            });
        }
    }
});
jQuery('#{$id}-card-element').closest('form').on("amFormSubmit", function(event){
    const frm = jQuery(event.target);
    const callback = event.callback;
    event.callback = null; // wait for completion
    if(jQuery("#{$id}-card-element").closest('.am-row').is(':visible')){
            stripe.confirmSetup({
                elements,
                confirmParams: {
                    return_url: "{$returnUrl}"
                },
                redirect: "if_required"
            }).then((result) => {
                if (result.error) {
                    const validator = frm.validate();
                        validator.showErrors({
                       '{$jsTokenFieldName}' : result.error.message
                    });
                
                } else  if (result.setupIntent) {
                        document.getElementById('{$jsTokenFieldName}').value = JSON.stringify(result);
                        callback();                
                }
            })
    } else {
        callback();
    }
});
CUT
            );
        }
    }

    /**
     * @param $shortTimeToken
     * @return array|mixed
     * @throws Am_Exception_Paysystem_TransactionInvalid
     */
    function convertPaymentTokenToLifetime($shortTimeToken, Invoice $invoice)
    {
        if ($shortTimeToken['setupIntent']['status'] !== 'succeeded') {
            throw new Am_Exception_Paysystem_TransactionInvalid(___('Payment Declined'));
        }

        $savedToken = $this->getToken($invoice);
        $result = new Am_Paysystem_Result();
        if (!empty($savedToken[self::CUSTOMER_ID])) {
            $tr = new Am_Paysystem_Transaction_Stripe_AttachCustomer(
                $this, $invoice, $shortTimeToken['setupIntent']['payment_method'], $savedToken[self::CUSTOMER_ID]
            );
            $tr->run($result);
            if ($result->isFailure() && (strpos($result->getLastError(), 'No such customer') !== false)) {
                $result = new Am_Paysystem_Result();
                $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this, $invoice,
                    $shortTimeToken['setupIntent']['payment_method'], 'payment_method');
                $tr->run($result);
                $customer_id = $tr->getUniqId();
                if ($result->isFailure()) {
                    throw new Am_Exception_Paysystem_TransactionInvalid(___('Payment Declined'));
                }
            }
            else
                $customer_id = $savedToken[self::CUSTOMER_ID];

        } else {
            $tr = new Am_Paysystem_Transaction_Stripe_CreateCustomer($this, $invoice,
                $shortTimeToken['setupIntent']['payment_method'], 'payment_method');
            $tr->run($result);
            $customer_id = $tr->getUniqId();
            if ($result->isFailure()) {
                throw new Am_Exception_Paysystem_TransactionInvalid(___('Payment Declined'));
            }
        }

        return [
            self::CUSTOMER_ID => $customer_id,
            self::PAYMENT_METHOD => $shortTimeToken['setupIntent']['payment_method']
        ];
    }

    function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return Am_Paysystem_Transaction_Stripe_Thanks::factory($this, $request, $response, $invokeArgs);
    }

    function updateToken(Invoice $invoice, $values)
    {
        $this->saveToken($invoice, $values);
        return $this->getToken($invoice);
    }

    function saveToken(Invoice $invoice, $token)
    {
        $invoiceTokenKeys =[self::PAYMENT_INTENT, self::PAYMENT_METHOD, self::SETUP_INTENT];

        $this->getDi()
            ->CcRecordTable
            ->getRecordByUser($invoice->getUser(), $invoice->paysys_id)
            ->updateToken(array_filter($token, fn($key) => !in_array($key, $invoiceTokenKeys), ARRAY_FILTER_USE_KEY))
            ->save();
        $this->getDi()
            ->CcRecordTable
            ->getRecordByInvoice($invoice)
            ->updateToken(array_filter($token, fn($key) => in_array($key, $invoiceTokenKeys), ARRAY_FILTER_USE_KEY))
            ->save();
    }
}

abstract class Am_Paysystem_Transaction_Stripe_Abstract extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $plugin->getDi()->store->set(sprintf('%s-%s-api-request', $plugin->getId(), $invoice->pk()), 1, "+2 minutes");

        $request = $this->_createRequest($plugin, $invoice, $doFirst);
        $request
            ->setAuth($plugin->getConfig('secret_key'), '');

        parent::__construct($plugin, $invoice, $request, $doFirst);
    }

    abstract function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true);

    function getObject()
    {
        return $this->parsedResponse;
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }
}

class Am_Paysystem_Transaction_Stripe_CreateSetupIntent extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function _createRequest(Am_Paysystem_Abstract $plugin, ?Invoice $invoice = null, $doFirst = true)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'setup_intents', 'POST');
        $request->addPostParameter('usage', 'off_session');
        return $request;
    }

    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function getClientSecret()
    {
        return $this->parsedResponse['client_secret'];
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            if (empty($this->parsedResponse['client_secret'])) {
                $this->result->setFailed(___('Unable to initialize payment'));
            } else {
                $this->result->setSuccess($this);
            }
        }
    }

    function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_CreatePaymentIntent extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_intents', 'POST');
        $request->setHeader('Stripe-Version', Am_Paysystem_Stripe::API_VERSION);
        $token = $plugin->getToken($invoice);

        $amount = $doFirst ? $invoice->first_total : $invoice->second_total;

        $vars = [
            'amount' => $amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])),
            'currency' => $invoice->currency,
            'description' => "Invoice #{$invoice->public_id}: {$invoice->getLineDescription()}",
            'metadata[invoice]' => $invoice->public_id,
            'metadata[Full Name]'=> $invoice->getName(),
            'metadata[Email]' =>  $invoice->getEmail(),
            'metadata[Username]'=> $invoice->getLogin(),
            'metadata[Address]' => "{$invoice->getCity()} {$invoice->getState()} {$invoice->getZip()} {$invoice->getCountry()}",
            'metadata[Order Date]' => $invoice->tm_added,
            'metadata[Purchase Type]' => $invoice->rebill_times ? "Recurring" : "Regular",
            'metadata[Total]' => Am_Currency::render($doFirst ? $invoice->first_total : $invoice->second_total, $invoice->currency)
        ];
        if($plugin->getConfig('only_card_method')){
            $vars['payment_method_types[]'] = 'card';
        }else{
            $vars['automatic_payment_methods[enabled]'] = 'true';
        }
        if ($invoice->rebill_times) {
            $vars['setup_future_usage'] = 'off_session';
        }
        if ($invoice->rebill_times && $plugin->getConfig('create-mandates'))
            $vars += [
                'payment_method_options[card][mandate_options][reference]' => $invoice->public_id,
                'payment_method_options[card][mandate_options][description]' => $invoice->getLineDescription(),
                'payment_method_options[card][mandate_options][amount]' =>
                    $invoice->second_total * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])),
                'payment_method_options[card][mandate_options][amount_type]' => 'fixed',
                'payment_method_options[card][mandate_options][start_date]' => Am_Di::getInstance()->time,
                'payment_method_options[card][mandate_options][interval]' => 'sporadic',
                'payment_method_options[card][mandate_options][supported_types][]' => 'india'
            ];
        if (isset($token[Am_Paysystem_Stripe::CUSTOMER_ID])){
            $vars['customer'] = $token[Am_Paysystem_Stripe::CUSTOMER_ID];
        }
        $request->addPostParameter($vars);
        return $request;
    }

    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function getClientSecret()
    {
        return $this->parsedResponse['client_secret'];
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            if (empty($this->parsedResponse['client_secret'])) {
                $this->result->setFailed(___('Unable to initialize payment'));
            } else {
                $this->result->setSuccess($this);
            }
        }
    }

    function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_PaymentIntentsCreate extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_intents', 'POST');
        $request->setHeader('Stripe-Version', Am_Paysystem_Stripe::API_VERSION);
        $token = $plugin->getToken($invoice);

        $amount = $doFirst ? $invoice->first_total : $invoice->second_total;

        $vars = [
            'amount' => $amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])),
            'payment_method' => $token[Am_Paysystem_Stripe::PAYMENT_METHOD],
            'currency' => $invoice->currency,
            'confirmation_method' => 'manual',
            'confirm' => 'true',
            'setup_future_usage' => 'off_session',
            'description' => "Invoice #{$invoice->public_id}: {$invoice->getLineDescription()}",
            'payment_method_types[]'=> 'card',
            'metadata[invoice]' => $invoice->public_id,
            'metadata[Full Name]'=> $invoice->getName(),
            'metadata[Email]' =>  $invoice->getEmail(),
            'metadata[Username]'=> $invoice->getLogin(),
            'metadata[Address]' => "{$invoice->getCity()} {$invoice->getState()} {$invoice->getZip()} {$invoice->getCountry()}",
            'metadata[Order Date]' => $invoice->tm_added,
            'metadata[Purchase Type]'=>$invoice->rebill_times ? "Recurring" : "Regular",
            'metadata[Total]' => Am_Currency::render($doFirst ? $invoice->first_total : $invoice->second_total, $invoice->currency)
        ];
        if ($plugin->getConfig('create-mandates'))
            $vars += [
                'payment_method_options[card][mandate_options][reference]' => $invoice->public_id,
                'payment_method_options[card][mandate_options][description]' => $invoice->getLineDescription(),
                'payment_method_options[card][mandate_options][amount]' =>
                    $invoice->second_total * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])),
                'payment_method_options[card][mandate_options][amount_type]' => 'fixed',
                'payment_method_options[card][mandate_options][start_date]' => Am_Di::getInstance()->time,
                'payment_method_options[card][mandate_options][interval]' => 'sporadic',
                'payment_method_options[card][mandate_options][supported_types][]' => 'india'
            ];
        if (isset($token[Am_Paysystem_Stripe::CUSTOMER_ID])){
            $vars['customer'] = $token[Am_Paysystem_Stripe::CUSTOMER_ID];
        }
        $request->addPostParameter($vars);
        return $request;
    }

    function getUniqId()
    {
        return $this->parsedResponse['latest_charge'] ??
            (@$this->parsedResponse['charges']['data'][0]['id'] ?: $this->parsedResponse['id']);
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            if ($this->parsedResponse['status'] != 'succeeded') {
                $this->result->setFailed(___('Status is not succeeded'));
            } else {
                $this->result->setSuccess($this);
            }
        }
    }
}

class Am_Paysystem_Transaction_Stripe_ListPaymentMethods extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function getData()
    {
        return $this->parsedResponse['data'];
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            $this->result->setSuccess($this);
        }
    }

    function processValidated()
    {
    }

    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $token = $plugin->getToken($invoice);

        return new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_methods?' . http_build_query([
                'customer' => $token[Am_Paysystem_Stripe::CUSTOMER_ID],
                'type' => 'card'
            ]), Am_HttpRequest::METHOD_GET);
    }
}

class Am_Paysystem_Transaction_Stripe_GetPaymentMethod extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function getCard()
    {
        return $this->parsedResponse['card'];
    }

    function getCustomer()
    {
        return $this->parsedResponse['customer'];
    }

    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $token = $plugin->getToken($invoice);
        return new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_methods/' . $token[Am_Paysystem_Stripe::PAYMENT_METHOD], 'GET');
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            $this->result->setSuccess($this);
        }
    }

    function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_GetPaymentIntent extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function getCustomer()
    {
        return $this->parsedResponse['customer'];
    }

    function getLastChargeId()
    {
        return $this->parsedResponse['charges']['data'][0]['id']??$this->parsedResponse['latest_charge'];
    }

    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $token = $plugin->getToken($invoice);
        return new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_intents/' . $token[Am_Paysystem_Stripe::PAYMENT_INTENT], 'GET');
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            $this->result->setSuccess($this);
        }
    }

    function processValidated()
    {
        $this->getPlugin()->updateToken($this->invoice, [
            Am_Paysystem_Stripe::CUSTOMER_ID => $this->getCustomer(),
            Am_Paysystem_Stripe::PAYMENT_METHOD => $this->parsedResponse['payment_method']
        ]);
    }
}

class Am_Paysystem_Transaction_Stripe_GetSetupIntent extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $token = $plugin->getToken($invoice);
        return new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'setup_intents/' . $token[Am_Paysystem_Stripe::SETUP_INTENT], 'GET');
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            $this->result->setSuccess($this);
        }
    }

    function processValidated()
    {
        $this->getPlugin()->validateToken($this->invoice, ['setupIntent' => $this->parsedResponse]);
    }
}

class Am_Paysystem_Transaction_Stripe_PaymentIntentsConfirm extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $token = $plugin->getToken($invoice);
        $request = new Am_HttpRequest(sprintf(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_intents/%s/confirm',
            $token[Am_Paysystem_Stripe::PAYMENT_INTENT]), 'POST');

        $request->addPostParameter([
            'payment_method' => $token[Am_Paysystem_Stripe::PAYMENT_METHOD]
        ]);
        return $request;
    }

    function getUniqId()
    {
        return @$this->parsedResponse['charges']['data'][0]['id']?:$this->parsedResponse['id'];
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            if ($this->parsedResponse['status'] != 'succeeded') {
                $this->result->setFailed(___('Status is not succeeded'));
            } else {
                $this->result->setSuccess($this);
            }
        }
    }
}

class Am_Paysystem_Transaction_Stripe extends Am_Paysystem_Transaction_Stripe_Abstract
{
    public function _createRequest(Am_Paysystem_Abstract $plugin, Invoice $invoice, $doFirst = true)
    {
        $token = $plugin->getToken($invoice);
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_intents', 'POST');
        $request->setHeader('Stripe-Version', Am_Paysystem_Stripe::API_VERSION);
        $amount = $doFirst ? $invoice->first_total : $invoice->second_total;
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('amount',
                $amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])))
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('customer', $token[Am_Paysystem_Stripe::CUSTOMER_ID])
            ->addPostParameter('description', "Invoice #{$invoice->public_id}: {$invoice->getLineDescription()}")
            ->addPostParameter('payment_method', $token[Am_Paysystem_Stripe::PAYMENT_METHOD])
            ->addPostParameter('off_session', 'true')
            ->addPostParameter('confirm', 'true')
            ->addPostParameter('metadata[invoice]', $invoice->public_id)
            ->addPostParameter('metadata[Full Name]', $invoice->getName())
            ->addPostParameter('metadata[Email]', $invoice->getEmail())
            ->addPostParameter('metadata[Username]', $invoice->getLogin())
            ->addPostParameter('metadata[Address]',
                "{$invoice->getCity()} {$invoice->getState()} {$invoice->getZip()} {$invoice->getCountry()}")
            ->addPostParameter('metadata[Order Date]', $invoice->tm_added)
            ->addPostParameter('metadata[Purchase Type]', $invoice->rebill_times ? "Recurring" : "Regular")
            ->addPostParameter('metadata[Total]',
                Am_Currency::render($doFirst ? $invoice->first_total : $invoice->second_total, $invoice->currency));
        if($plugin->getConfig('only_card_method')){
            $request->addPostParameter('payment_method_types[]', 'card');
        }else{
            $request->addPostParameter('automatic_payment_methods[enabled]', 'true');
        }
        return $request;
    }

    public function getUniqId()
    {
        return $this->parsedResponse['latest_charge']??(@$this->parsedResponse['charges']['data'][0]['id']?:$this->parsedResponse['id']);
    }

    public function validate()
    {
        $acceptedStatus = ['succeeded'];
        if($this->getPlugin()->getConfig('accept_pending')){
            $acceptedStatus[] = 'processing';
        }
        if (!in_array(@$this->parsedResponse['status'], $acceptedStatus)) {
            if ($this->parsedResponse['error']['type'] == 'card_error') {
                $this->result->setFailed($this->parsedResponse['error']['message']);
            } else {
                $this->result->setFailed(___('Payment failed'));
            }
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }
}

class Am_Paysystem_Transaction_Stripe_Charge extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $source)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'charges', 'POST');
        $amount = $invoice->first_total;
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('amount',
                $amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])))
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('source', $source)
            ->addPostParameter('metadata[invoice]', $invoice->public_id)
            ->addPostParameter('description',
                'Invoice #' . $invoice->public_id . ': ' . $invoice->getLineDescription());
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['paid']) || $this->parsedResponse['paid'] != 'true') {
            if ($this->parsedResponse['error']['type'] == 'card_error') {
                $this->result->setFailed($this->parsedResponse['error']['message']);
            } else {
                $this->result->setFailed(___('Payment failed'));
            }
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }
}

class Am_Paysystem_Transaction_Stripe_AttachCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $paymentMethod, $customer)
    {
        $request = new Am_HttpRequest(sprintf(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_methods/%s/attach', $paymentMethod),
            'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('customer', $customer);
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function getObject()
    {
        return $this->parsedResponse;
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $code = @$this->parsedResponse['error']['code'];
            $message = @$this->parsedResponse['error']['message'];
            $error = "Error storing customer profile";
            if ($code) {
                $error .= " [{$code}]";
            }
            if ($message) {
                $error .= " ({$message})";
            }
            $this->result->setFailed($error);
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_CreateCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token = null, $tokenName = 'card')
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'customers', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('email', $invoice->getEmail())
            ->addPostParameter('name', $invoice->getUser()->getName())
            ->addPostParameter('description', 'Username:' . $invoice->getUser()->login);
        if(!is_null($token)){
            $request->addPostParameter($tokenName, $token);
        }
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function getCard()
    {
        switch (true) {
            case isset($this->parsedResponse['cards']) :
                return $this->parsedResponse['cards']['data'];
            case isset($this->parsedResponse['sources']) :
                return $this->parsedResponse['sources']['data'];
            default:
                return null;
        }
    }

    function getObject()
    {
        return $this->parsedResponse;
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $code = $this->parsedResponse['error']['code'] ?? null;
            $message = $this->parsedResponse['error']['message'] ?? null;
            $error = "";

            if (!empty($message)) {
                $error .= $message;
            }

            if (empty($error)){
                $error = ___('Declined');
                if (!empty($code)) {
                    $error .= " [{$code}]";
                }
            }
            $this->result->setFailed($error);
            return false;
        }
        $this->result->setSuccess($this);
        return true;
    }

    public function processValidated()
    {
        $this->getPlugin()->updateToken($this->invoice, [Am_Paysystem_Stripe::CUSTOMER_ID => $this->getUniqId()]);
    }
}

class Am_Paysystem_Transaction_Stripe_CreateCardSource extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'sources', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('type', 'card')
            ->addPostParameter('token', $token);
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed(
                !empty($this->parsedResponse['error']['message']) ?
                    $this->parsedResponse['error']['message'] :
                    'Unable to fetch payment profile'
            );
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_Create3dSecureSource extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $card)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'sources', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->addPostParameter('type', 'three_d_secure')
            ->addPostParameter('amount', $invoice->first_total * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])))
            ->addPostParameter('currency', $invoice->currency)
            ->addPostParameter('three_d_secure[card]', $card)
            ->addPostParameter('redirect[return_url]',
                $plugin->getPluginUrl('3ds', ['id' => $invoice->getSecureId("{$plugin->getId()}-3DS")]));
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed(
                !empty($this->parsedResponse['error']['message']) ?
                    $this->parsedResponse['error']['message'] :
                    'Unable to fetch payment profile'
            );
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_GetCustomer extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'customers/' . $token, 'GET');
        $request->setAuth($plugin->getConfig('secret_key'), '');
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed('Unable to fetch payment profile');
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_GetCustomerPaymentMethod extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $token)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'payment_methods?' . http_build_query([
                'limit' => 1,
                'type' => 'card',
                'customer' => $token
            ]), 'GET');
        $request->setAuth($plugin->getConfig('secret_key'), '');
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['data'][0]['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {

        if (empty($this->parsedResponse['data'][0]['id'])) {
            $this->result->setFailed('Unable to fetch payment method');
        } else {
            $this->result->setSuccess($this);
        }
    }

    public function getInfo()
    {
        return $this->parsedResponse;
    }

    public function processValidated()
    {
    }
}

class Am_Paysystem_Transaction_Stripe_Refund extends Am_Paysystem_Transaction_CreditCard
{
    protected $parsedResponse = [];
    protected $charge_id;
    protected $amount;

    public function __construct(Am_Paysystem_Abstract $plugin, Invoice $invoice, $charge_id, $amount = null)
    {
        $this->charge_id = $charge_id;
        $this->amount = $amount > 0 ? $amount : null;
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'refunds', 'POST');
        $request->setAuth($plugin->getConfig('secret_key'), '')
            ->setHeader('Stripe-Version', Am_Paysystem_Stripe::API_VERSION)
            ->addPostParameter((strpos($charge_id, 'ch_') === 0 || strpos($charge_id, 'py_') === 0) ? 'charge' : 'payment_intent', $charge_id);
        if ($this->amount > 0) {
            $request->addPostParameter('amount',
                $this->amount * (pow(10, Am_Currency::$currencyList[$invoice->currency]['precision'])));
        }
        parent::__construct($plugin, $invoice, $request, true);
    }

    public function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    public function parseResponse()
    {
        $this->parsedResponse = json_decode($this->response->getBody(), true);
    }

    public function validate()
    {
        if (empty($this->parsedResponse['id'])) {
            $this->result->setFailed('Unable to fetch payment profile');
        } else {
            $this->result->setSuccess();
        }
    }

    public function processValidated()
    {
        $this->invoice->addRefund($this, $this->charge_id, $this->amount);
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook extends Am_Paysystem_Transaction_Incoming
{
    protected $event;

    function __construct(
        Am_Paysystem_Abstract $plugin,
        Am_Mvc_Request $request,
        Am_Mvc_Response $response,
        $invokeArgs
    ) {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->event = json_decode($request->getRawBody(), true);
    }

    static function create(
        Am_Paysystem_Abstract $plugin,
        Am_Mvc_Request $request,
        Am_Mvc_Response $response,
        $invokeArgs
    ) {
        $event = json_decode($request->getRawBody(), true);
        switch ($event['type']) {
            case "charge.refunded" :
                return new Am_Paysystem_Transaction_Stripe_Webhook_Refund($plugin, $request, $response, $invokeArgs);
                break;
            case "charge.succeeded" :
                return new Am_Paysystem_Transaction_Stripe_Webhook_Charge($plugin, $request, $response, $invokeArgs);
                break;
            case "charge.failed" :
                return new Am_Paysystem_Transaction_Stripe_Webhook_ChargeFailed($plugin, $request, $response, $invokeArgs);
                break;
            case "radar.early_fraud_warning.created" :
                return new Am_Paysystem_Transaction_Stripe_Webhook_Efw($plugin, $request, $response,$invokeArgs);
                break;
            case "checkout.session.completed" :
                return new Am_Paysystem_Transaction_Stripe_Webhook_CheckoutSessionCompleted($plugin, $request, $response, $invokeArgs);
        }
    }

    public function validateSource()
    {
        return (bool)$this->event;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    function getUniqId()
    {
    }

    /**
     * @return Am_Paysystem_Stripe
     */
    function getPlugin()
    {
        return parent::getPlugin();
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook_Efw extends Am_Paysystem_Transaction_Stripe_Webhook
{
    function getUniqId()
    {
        return $this->event['data']['object']['id'];
    }

    function getChargeId()
    {
        return $this->event['data']['object']['charge'];
    }

    public function findInvoiceId()
    {
        $invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin($this->getChargeId(),
            $this->getPlugin()->getId());
        return ($invoice ? $invoice->public_id : null);
    }

    function processValidated()
    {
        if ($this->getPlugin()->getConfig('efw_refund') && $this->event['data']['object']['actionable']) {
            /** @var InvoicePayment $payment */
            $payment = $this->getPlugin()->getDi()->invoicePaymentTable->findFirstBy([
                'invoice_public_id' => $this->findInvoiceId(),
                'receipt_id' => $this->getChargeId()
            ]);
            if ($payment) {
                $result = new Am_Paysystem_Result();

                $this->getPlugin()->processRefund($payment, $result, $payment->amount);

                if ($result->isFailure()) {
                    $this->getPlugin()->getDi()->logger->warning("Stripe EWF: Unable to process refund for {charge}. Got {error} from stripe",
                        [
                            'charge' => $this->getChargeId(),
                            'error' => $result->getLastError()
                        ]);
                } else {
                    $note = $this->getPlugin()->getDi()->userNoteRecord;
                    $note->user_id = $payment->user_id;
                    $note->dattm = $this->getPlugin()->getDi()->sqlDateTime;
                    $note->content = ___('Stripe EFW notification received for user payment  %s. Payment refunded. User disabled', $this->getChargeId());
                    $note->insert();

                    $payment->getUser()->lock();

                    $invoice = $payment->getInvoice();
                    if ($invoice->status == Invoice::RECURRING_ACTIVE) {
                        $invoice->setCancelled(true);
                    }

                    if ($this->getPlugin()->getDi()->plugins_misc->isEnabled('user-signup-disable')) {
                        $payment->getUser()->updateQuick('signup_disable', 1);
                    }
                }
            }
        }
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook_Refund extends Am_Paysystem_Transaction_Stripe_Webhook
{
    public function process()
    {
        $r = null;
        $refundsList = ($this->event['data']['object']['refunds']['data'] ?? $this->event['data']['object']['refunds']);
        if (!$refundsList) {
            $charge = $this->getPlugin()->apiRetrieveCharge(
                $this->event['data']['object']['id'],
                ['expand' => ['refunds']],
                $this->invoice ?? null
            );
            $refundsList = $charge['refunds']['data'];
        }
        foreach ($refundsList as $refund) {
            if (is_null($r) || $refund['created'] > $r['created']) {
                $r = $refund;
            }
        }
        $this->refund = $r;
        return parent::process();
    }

    public function getUniqId()
    {
        return $this->refund['id'];
    }

    public function findInvoiceId()
    {
        if (!empty($this->event['data']['object']['metadata']['invoice']))
            return $this->event['data']['object']['metadata']['invoice'];
        if ($invoice = Am_Di::getInstance()->invoiceTable->findByReceiptIdAndPlugin($this->event['data']['object']['id'], $this->getPlugin()->getId()))
            return $invoice->public_id;
    }

    public function processValidated()
    {
        try {
            $this->invoice->addRefund($this, $this->event['data']['object']["id"],
                $this->refund['amount'] / (pow(10, Am_Currency::$currencyList[$this->invoice->currency]['precision'])));
        } catch (Am_Exception_Db_NotUnique $e) {
            //nop, refund is added from aMemeber admin interface
        }
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook_Charge extends Am_Paysystem_Transaction_Stripe_Webhook
{
    protected $_qty = [];

    function getUniqId()
    {
        return $this->event['data']['object']['id'];
    }

    function  generateInvoiceExternalId()
    {
        return $this->event['data']['object']['invoice'];
    }

    function generateUserExternalId(array $userInfo)
    {
        return $this->event['data']['object']['customer'];
    }

    function fetchUserInfo()
    {
        $req = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'customers/' . $this->generateUserExternalId([]), 'GET');
        $req->setAuth($this->getPlugin()->getConfig('secret_key'), '');

        $resp = $req->send();

        $parsedResponse = json_decode($resp->getBody(), true);
        [$name_f, $name_l] = explode(" ", $parsedResponse['name']);
        return [
            'email' => $parsedResponse['email'],
            'name_f' => $name_f,
            'name_l' => $name_l,
            'phone' => $parsedResponse['phone']
        ];
    }

    function findInvoiceId()
    {
        $public_id = @$this->event['data']['object']['metadata']['invoice'];
        if (empty($public_id)) {
            $public_id = $this->getPlugin()->getDi()->db->selectCell("
            SELECT invoice_public_id FROM ?_invoice_payment WHERE transaction_id=?
            ", $this->getUniqId());
        }
        return $public_id;
    }

    function autoCreateGetProducts()
    {
        $req = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'invoices/' . $this->generateInvoiceExternalId(), 'GET');
        $req->setAuth($this->getPlugin()->getConfig('secret_key'), '');

        $resp = $req->send();

        $parsedResponse = json_decode($resp->getBody(), true);
        if (empty($parsedResponse)) {
            return [];
        }
        $products = [];
        foreach ($parsedResponse['lines']['data'] as $line) {
            $bp = $this->getPlugin()->getDi()->billingPlanTable->findFirstByData('stripe_product_id',
                $line['plan']['product']);
            if ($bp) {
                $product = $bp->getProduct();
                $products[] = $product->setBillingPlan($bp);
                $this->_qty[$product->pk()] = @$line['quantity'] ?: 1;
            }
        }
        return $products;
    }

    function autoCreateGetProductQuantity(Product $pr)
    {
        return $this->_qty[$pr->pk()];
    }

    function validateStatus()
    {
        if ($this->getPlugin()->getDi()->store->get(sprintf('%s-%s-api-request', $this->plugin->getId(), $this->invoice->pk()))) {
            throw new Am_Exception_Paysystem_TransactionInvalid(___('Transaction is being processed by API at the moment'));
        }
        return true;
    }

    function processValidated()
    {
        if (
            $this->getPlugin()->getConfig('accept_pending')
            && (@$this->event['data']['object']['payment_method_details']['type'] == 'sepa_debit')
            && count($this->invoice->getAccessRecords())
            && !count($this->invoice->getPaymentRecords())
        ) {
            $p = $this->invoice->addPaymentWithoutAccessPeriod($this);
            Am_Di::getInstance()->hook->call(new Am_Event_PaymentWithAccessAfterInsert(null,
                [
                    'payment' => $p,
                    'invoice' => $this->invoice,
                    'user' => $this->invoice->getUser()
                ]));
        } else {
            parent::processValidated();
        }
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook_ChargeFailed extends Am_Paysystem_Transaction_Stripe_Webhook
{
    protected $_qty = [];

    function getUniqId()
    {
        return $this->event['data']['object']['id'];
    }

    function findInvoiceId()
    {
        $public_id = @$this->event['data']['object']['metadata']['invoice'];
        if (empty($public_id)) {
            $public_id = $this->getPlugin()->getDi()->db->selectCell("
            SELECT invoice_public_id FROM ?_invoice_payment WHERE transaction_id=?
            ", $this->getUniqId());
        }
        return $public_id;
    }

    function validateStatus()
    {
        if ($this->getPlugin()->getDi()->store->get(sprintf('%s-%s-api-request', $this->plugin->getId(), $this->invoice->pk()))) {
            throw new Am_Exception_Paysystem_TransactionInvalid(___('Transaction is being processed by API at the moment'));
        }
        return true;
    }

    function processValidated()
    {
        $this->invoice->stopAccess($this);
        if($this->invoice->status === Invoice::RECURRING_ACTIVE){
            $haveTrial = $this->invoice->first_total == 0;
            $isRecurringPayment = $this->invoice->getPaymentsCount()>=($haveTrial?0:1);
            if($isRecurringPayment){
                $this->getPlugin()->onRebillFailure($this->invoice, $this->getPlugin()->loadCreditCard($this->invoice), new Am_Paysystem_Result(), $this->getPlugin()->getDi()->sqlDate);
            }else{
                $this->invoice->status = Invoice::RECURRING_FAILED;
            }
        }
        $this->invoice->update();
    }
}

class Am_Paysystem_Transaction_Stripe_Webhook_CheckoutSessionCompleted extends Am_Paysystem_Transaction_Stripe_Webhook
{
    protected $_qty = [];
    protected $charge_id;

    function getUniqId()
    {
        return $this->charge_id;
    }

    function validateStatus()
    {
        return true;
    }

    function findInvoiceId()
    {
        return $this->event['data']['object']['metadata']['invoice'];
    }

    function processValidated()
    {
        if (!empty($this->event['data']['object']['setup_intent']) && ($this->invoice->first_total == 0)) {
            $this->getPlugin()->updateToken($this->invoice, [Am_Paysystem_Stripe::SETUP_INTENT => $this->event['data']['object']['setup_intent']]);

            $tr = new Am_Paysystem_Transaction_Stripe_GetSetupIntent($this->getPlugin(), $this->invoice);
            $result = new Am_Paysystem_Result();
            $tr->run($result);

            if ($result->isSuccess()) {
                $this->invoice->addAccessPeriod($this);
            }
        } elseif (!empty($this->event['data']['object']['payment_intent'])) {
            $this->getPlugin()->updateToken($this->invoice, [Am_Paysystem_Stripe::PAYMENT_INTENT => $this->event['data']['object']['payment_intent']]);

            $tr = new Am_Paysystem_Transaction_Stripe_GetPaymentIntent($this->getPlugin(), $this->invoice);
            $result = new Am_Paysystem_Result();
            $tr->run($result);
            if ($result->isSuccess()) {
                $this->charge_id = $tr->getLastChargeId();
                $this->invoice->addPayment($this);
            }
        }
    }
}

class Am_Paysystem_Transaction_Stripe_CreateCheckoutSession extends Am_Paysystem_Transaction_Stripe_Abstract
{
    function _createRequest(Am_Paysystem_Abstract $plugin, ?Invoice $invoice = null, $doFirst = true)
    {
        $request = new Am_HttpRequest(Am_Paysystem_Stripe::API_ENDPOINT . 'checkout/sessions', 'POST');
        $data = [
            'cancel_url' => $plugin->getCancelUrl(),
            'mode' => ($invoice->first_total>0) ? 'payment' : 'setup',
            'success_url' => $plugin->getReturnUrl(),
            'metadata' => [
                'invoice' => $invoice->public_id
            ]
        ];

        if ($plugin->getConfig('only_card_method')) {
            $data['payment_method_types'] = ['card'];
        } elseif ($data['mode'] == 'setup') {
            $data['currency'] = $invoice->currency;
        }

        if ($plugin->getConfig('create-mandates') || $plugin->getConfig('shipping-billing'))
            $data['billing_address_collection'] = 'required';
        if ($plugin->getConfig('shipping-billing'))
            $data['shipping_address_collection[allowed_countries]'] = [
                'AC', 'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA',
                'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV',
                'BW', 'BY', 'BZ', 'CA', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CV', 'CW',
                'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ',
                'FK', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR',
                'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ',
                'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KR', 'KW', 'KY', 'KZ', 'LA',
                'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MK',
                'ML', 'MM', 'MN', 'MO', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE',
                'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM',
                'PN', 'PR', 'PS', 'PT', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SE', 'SG', 'SH',
                'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SZ', 'TA', 'TC', 'TD', 'TF',
                'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'US', 'UY',
                'UZ', 'VA', 'VC', 'VE', 'VG', 'VN', 'VU', 'WF', 'WS', 'XK', 'YE', 'YT', 'ZA', 'ZM', 'ZW', 'ZZ'
            ];

        if ($data['mode'] == 'payment') {
            $data['payment_intent_data']['setup_future_usage'] ='off_session';
            $token = $plugin->getToken($invoice);

            if ($customer_id = @$token[Am_Paysystem_Stripe::CUSTOMER_ID]) {
                $data['customer'] = $customer_id;
            }

            $data['payment_intent_data']['description'] = "Invoice #{$invoice->public_id}: {$invoice->getLineDescription()}";
            $data['payment_intent_data']['metadata']['Country Code'] = $invoice->getCountry();

            $data['line_items'][] = [
                'amount' => ($invoice->isFirstPayment() ? $invoice->first_total : $invoice->second_total) * (pow(10,
                        Am_Currency::$currencyList[$invoice->currency]['precision'])),
                'currency' => $invoice->currency,
                'name' => $invoice->getLineDescription(),
                'description' => $invoice->getTerms(),
                'quantity' => 1
            ];
            //2022-08-01
            /*
            $data['line_items'][] = [
                'price_data' => [
                    'product_data' => [
                        'name' => $invoice->getLineDescription(),
                    ],
                    'unit_amount' => $invoice->first_total * (pow(10,
                            Am_Currency::$currencyList[$invoice->currency]['precision'])),
                    'currency' => $invoice->currency,
                ],
                'quantity' => 1
            ];
             */
        }
        if (empty($data['customer'])) {
            $data['customer_email'] = $invoice->getEmail();
        }
        $request->addPostParameter($data);
        $request->setHeader('Stripe-Version', Am_Paysystem_Stripe::API_VERSION);
        return $request;
    }

    function getUniqId()
    {
        return $this->parsedResponse['id'];
    }

    function validate()
    {
        if (!empty($this->parsedResponse['error'])) {
            $this->result->setFailed($this->parsedResponse['error']['message']);
        } else {
            $this->result->setSuccess($this);
        }
    }

    function processValidated()
    {
    }
}

abstract class Am_Paysystem_Transaction_Stripe_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    protected PaymentIntent $intent;

    function findInvoiceId()
    {
        [$publicId,] = explode("-", $this->request->getParam('invoice'));
        return $publicId;
    }

    /**
     * @return Am_Paysystem_Stripe
     */
    function getPlugin()
    {
        return $this->plugin;
    }

    static function factory(
        Am_paysystem_Abstract $plugin,
        Am_Mvc_Request $request,
        Am_Mvc_Response $response,
        array $invokeArgs
    ) {
        if ($request->getParam('payment_intent')) {
            return new Am_Paysystem_Transaction_Stripe_PaymentIntent_Thanks($plugin, $request, $response,
                $invokeArgs);
        } else {
            if ($request->getParam('setup_intent')) {
                return new Am_Paysystem_Transaction_Stripe_SetupIntent_Thanks($plugin, $request, $response,
                    $invokeArgs);
            }
        }
        throw new Am_Exception_Paysystem_TransactionInvalid(___("Unknown transaction type"));
    }

    public function validateSource()
    {
        $invoiceId = $this->request->getParam('invoice');

        if (empty($invoiceId)) {
            throw new Am_Exception_Paysystem_TransactionSource(___('Invoice ID is missing in request'));
        }

        $invoice = $this->getPlugin()->getDi()->invoiceTable->findBySecureId($invoiceId, "THANKS");

        if (empty($invoice)) {
            throw new Am_Exception_Paysystem_TransactionSource(___('Unable to find invoice'));
        }

        $user = $this->getPlugin()->getDi()->auth->getUser();

        if (!empty($user) && $invoice->user_id != $user->user_id) {
            throw new Am_Exception_Paysystem_TransactionSource(___('Invoice created for another user'));
        }

        return true;
    }

    public function validateStatus()
    {
        if ($this->intent->status == 'requires_action' && isset($this->intent->next_action)) {
            /**
             * @var \Stripe\StripeObject $nextAction
             */
            $nextAction = $this->intent->next_action;
            if (!empty($nextAction->display_bank_transfer_instructions) && !empty($nextAction->display_bank_transfer_instructions->hosted_instructions_url)) {
                Am_Mvc_Response::redirectLocation($nextAction->display_bank_transfer_instructions->hosted_instructions_url);
            }
        }
        return $this->intent->status == 'succeeded' || $this->intent->status == 'processing';
    }

    function getUniqId()
    {
        return $this->intent->id;
    }

    function processValidated()
    {

        $tr = $this->createTransaction();
        $result = $tr->process();
        if ($result->isSuccess()) {
        } else {
            throw new Am_Exception_Paysystem_TransactionInvalid($result->getLastError());
        }
    }

    abstract function createTransaction();
}

class Am_Paysystem_Transaction_Stripe_PaymentIntent_Thanks extends Am_Paysystem_Transaction_Stripe_Thanks
{
    protected PaymentIntent $intent;

    function validateSource()
    {
        if (!parent::validateSource()) {
            return false;
        }
        $paymentIntentId = $this->request->getParam('payment_intent');
        if (empty($paymentIntentId)) {
            throw new Am_Exception_Paysystem_TransactionSource(___('Payment Intent is empty in request'));
        }

        $this->intent = $this->getPlugin()->apiRetrievePaymentIntent($paymentIntentId);
        return true;
    }

    public function validateTerms()
    {
        $received = sprintf("%.2f", $this->intent->amount / 100);

        return $received == ($this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total);
    }

    function createTransaction()
    {
        return new Am_Paysystem_Transaction_Stripe_PaymentIntent($this->getPlugin(), $this->invoice, $this->intent);
    }
}

class Am_Paysystem_Transaction_Stripe_SetupIntent_Thanks extends Am_Paysystem_Transaction_Stripe_Thanks
{
    protected PaymentIntent $intent;

    function validateSource()
    {
        if (!parent::validateSource()) {
            return false;
        }
        $setupIntentId = $this->request->getParam('setup_intent');

        if (empty($setupIntentId)) {
            throw new Am_Exception_Paysystem_TransactionSource(___('Setup Intent is empty in request'));
        }

        $this->intent = $this->getPlugin()->apiRetrieveSetupIntent($setupIntentId);
        return true;
    }

    public function validateTerms()
    {
        return $this->invoice->isFirstPayment() && ($this->invoice->first_total == 0);
    }

    function createTransaction()
    {
        return new Am_Paysystem_Transaction_Stripe_SetupIntent($this->getPlugin(), $this->invoice, $this->intent);
    }
}

abstract class Am_Paysystem_Transaction_Stripe_Intent extends Am_Paysystem_Transaction_Abstract
{
    function process(?Am_Paysystem_Result $result = null): Am_Paysystem_Result
    {
        $errors = $this->validate();
        $result = $result?: new Am_Paysystem_Result();

        if (empty($errors)) {
            $this->processValidated();
            $result->setSuccess($this);
        } else {
            $result->setFailed($errors);
        }
        return $result;
    }

    function getPlugin(): Am_Paysystem_Stripe
    {
        return $this->plugin;
    }

    function saveToken(Invoice $invoice, $intent)
    {
        $token = $this->getPlugin()->getToken($invoice);
        if ($intent->customer) {
            $token[Am_Paysystem_Stripe::CUSTOMER_ID] = $intent->customer;
        } elseif (!empty($intent->setup_future_usage)) {
            if (empty($token[Am_Paysystem_Stripe::CUSTOMER_ID])) {
                $customer = $this->getPlugin()->apiCreateCustomer([
                    'email' => $invoice->getEmail(),
                    'name' => $invoice->getName(),
                    'description' => 'Username:' . $invoice->getUser()->login
                ], $invoice);
                $token[Am_Paysystem_Stripe::CUSTOMER_ID] = $customer->id;
            }
            $paymentMethod = $this->getPlugin()->apiRetrievePaymentMethod($intent->payment_method, $invoice);

            $this->getPlugin()->apiAttachCustomer($paymentMethod, $token[Am_Paysystem_Stripe::CUSTOMER_ID]);
        }

        if (!empty($intent->payment_method)) {
            $token[Am_Paysystem_Stripe::PAYMENT_METHOD] = $intent->payment_method;
        }

        $this->getPlugin()->saveToken($invoice, $token);
    }
}

class Am_Paysystem_Transaction_Stripe_PaymentIntent extends Am_Paysystem_Transaction_Stripe_Intent
{
    var PaymentIntent $paymentIntent;

    function __construct(Am_Paysystem_Stripe $plugin, Invoice $invoice, PaymentIntent $paymentIntent)
    {
        parent::__construct($plugin);
        $this->paymentIntent = $paymentIntent;
        $plugin->logOther("PaymentIntent", $paymentIntent);
        $this->setInvoice($invoice);
    }

    function getUniqId()
    {
        if (isset($this->paymentIntent->latest_charge)) {
            return $this->paymentIntent->latest_charge;
        } elseif (isset($this->paymentIntent->charges)) {
            /** @var \Stripe\Charge $charge */
            $charge = $this->paymentIntent->charges->last();
        }
        return isset($charge) ? $charge->id : $this->paymentIntent->id;
    }

    function getAmount()
    {
        return sprintf("%.2f", $this->paymentIntent->amount / 100);
    }

    function validate()
    {
        if (!in_array($this->paymentIntent->status, ['succeeded', 'processing'])) {
            return ___('Payment failed');
        }
        $expectedAmount = $this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total;
        if ($expectedAmount != $this->getAmount()) {
            return ___('Amount received is not correct. Expected %s, got %s', $expectedAmount, $this->getAmount());
        }
    }

    function processValidated()
    {
        $this->saveToken($this->invoice, $this->paymentIntent);
        if ($this->getPlugin()->getConfig('accept_pending') && $this->paymentIntent->status === 'processing') {
            $this->invoice->addAccessPeriod($this);
            $this->plugin->getDi()->store->delete(sprintf('%s-%s-api-request', $this->plugin->getId(), $this->invoice->pk()));
        } elseif ($this->paymentIntent->status === 'succeeded') {
            parent::processValidated();
        }
    }
}

class Am_Paysystem_Transaction_Stripe_SetupIntent extends Am_Paysystem_Transaction_Stripe_Intent
{
    var SetupIntent $setupIntent;

    function __construct(Am_Paysystem_Stripe $plugin, Invoice $invoice, SetupIntent $setupIntent)
    {
        parent::__construct($plugin);
        $this->setupIntent = $setupIntent;
        $plugin->logOther("SetupIntent", $setupIntent);

        $this->setInvoice($invoice);
    }

    function getUniqId()
    {
        return $this->setupIntent->id;
    }

    function getAmount()
    {
        return null;
    }

    function validate()
    {
        if (!in_array($this->setupIntent->status, ['succeeded', 'processing'])) {
            return ___('Payment failed');
        }

        $expectedAmount = $this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total;
        if ($expectedAmount > 0) {
            return ___('Amount received is not correct. Expected %s, got %s', $expectedAmount, $this->getAmount());
        }
    }

    function processValidated()
    {
        $this->saveToken($this->invoice, $this->setupIntent);
        $acceptedStatus = ['succeeded'];
        if ($this->getPlugin()->getConfig('accept_pending')) {
            $acceptedStatus[] = 'processing';
        }
        if (in_array($this->setupIntent->status, $acceptedStatus)) {
            $this->invoice->addAccessPeriod($this);
        }
    }
}