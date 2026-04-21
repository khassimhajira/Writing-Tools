<?php
/**
 * @table paysystems
 * @id jvzoo
 * @title JVZoo
 * @visible_link http://jvzoo.com
 * @logo_url jvzoo.png
 * @recurring paysystem
 * @am_payment_api 6.0
 */
//http://support.jvzoo.com/Knowledgebase/Article/View/17/2/jvzipn

class Am_Paysystem_Jvzoo extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '6.3.39';

    protected $defaultTitle = "JVZoo";
    protected $defaultDescription = "";
    protected $_canResendPostback = true;

    public $_isBackground = true;

    public function __construct(Am_Di $di, array $config, $id = false)
    {
        parent::__construct($di, $config, $id);
        foreach ($di->paysystemList->getList() as $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(
            new Am_CustomFieldText('jvzoo_prod_item',
                "JVZoo product number", "1-5 Characters"));
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key)
        {
            case 'testing': return false;
            case 'auto_create': return true;
            default: return parent::getConfig($key, $default);
        }
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addTextarea("secret", ['class'=>'one-per-line'])
            ->setLabel("JVZoo Secret Key\n" .
                "you can add several keys from different accounts if necessary");
    }

    public function supportsCancelPage()
    {
        return false;
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        return;
    }

    function _process($invoice, $request, $result)
    {
        // Nothing to do.
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Jvzoo($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_JvzooThanks($this, $request, $response, $invokeArgs);
    }

    public function canAutoCreate()
    {
        return true;
    }
}

class Am_Paysystem_Transaction_JvzooThanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function getUniqId()
    {
        return $this->request->get('cbreceipt');
    }

    public function findInvoiceId()
    {
        if ($invoice = $this->plugin->getDi()->invoiceTable->findByReceiptIdAndPlugin($this->request->get('cbreceipt'), $this->plugin->getId()))
            return $invoice->public_id;
        return $this->request->get('cbreceipt');
    }

    public function validateSource()
    {
        $keys = $this->getPlugin()->getConfig('secret');
        $rcpt = $this->request->getParam('cbreceipt');
        $time = $this->request->getParam('time');
        $item = $this->request->getParam('item');
        $cbpop = $this->request->getParam('cbpop');

        foreach(explode("\n", $keys) as $key) {
            $key = trim($key);

            $xxpop = sha1("$key|$rcpt|$time|$item");
            $xxpop = strtoupper(substr($xxpop, 0, 8));
            if (hash_equals($xxpop, $cbpop))
                return true;
        }

        return false;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }
    public function processValidated()
    {
        //
    }
}

class Am_Paysystem_Transaction_Jvzoo extends Am_Paysystem_Transaction_Incoming
{
    // payment
    const SALE = "SALE";
    const BILL = "BILL";

    // refund
    const RFND = "RFND";
    const CGBK = "CGBK";
    const INSF = "INSF";

    // cancel
    const CANCEL_REBILL = "CANCEL-REBILL";

    // uncancel
    const UNCANCEL_REBILL = "UNCANCEL-REBILL";

    protected $_autoCreateMap = [
        'name' => 'ccustname',
        'email' => 'ccustemail',
        'state' => 'ccuststate',
        'country' => 'ccustcc',
        'user_external_id' => 'ccustemail',
    ];

    public function generateInvoiceExternalId()
    {
        [$l,] = explode('-', $this->getUniqId());
        return $l;
    }

    public function autoCreateGetProducts()
    {
        $item_name = $this->request->get('cproditem');
        if (empty($item_name))
            return;

        foreach ($this->getPlugin()->getDi()->billingPlanTable->findBy() as $bp) {
            $list = array_map('trim', explode(',', $bp->data()->get('jvzoo_prod_item')));
            if (in_array($item_name, $list)) return $bp->getProduct();
        }
    }

    public function getReceiptId()
    {
        switch ($this->request->get('ctransaction'))
        {
            //refund
            case Am_Paysystem_Transaction_Jvzoo::RFND:
            case Am_Paysystem_Transaction_Jvzoo::CGBK:
            case Am_Paysystem_Transaction_Jvzoo::INSF:
                return $this->request->get('ctransreceipt').'-'.$this->request->get('ctransaction');
                break;
            default :
                return $this->request->get('ctransreceipt');
        }
    }

    public function getAmount()
    {
        return moneyRound($this->request->get('ctransamount'));
    }

    public function getUniqId()
    {
        return @$this->request->get('ctransreceipt');
    }

    public function validateSource()
    {
        $ipnFields = $this->request->getPost();
        $keys = $this->getPlugin()->getConfig('secret');

        foreach(explode("\n", $keys) as $key)
        {
            $key = trim($key);
            if (hash_equals($this->hash($ipnFields, $key), $this->request->get('cverify'))) {
                return true;
            }
        }
        return false;
    }

    function hash($ipnFields, $secret)
    {
        unset($ipnFields['cverify']);
        ksort($ipnFields);
        $pop = implode('|', $ipnFields) . '|' . $secret;
        if (function_exists('mb_convert_encoding'))
            $pop = mb_convert_encoding($pop, "UTF-8");
        return strtoupper(substr(sha1($pop), 0, 8));
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->request->get('ctransaction'))
        {
            //payment
            case Am_Paysystem_Transaction_Jvzoo::SALE:
            case Am_Paysystem_Transaction_Jvzoo::BILL:
                $this->invoice->addPayment($this);
                break;
            //refund
            case Am_Paysystem_Transaction_Jvzoo::RFND:
            case Am_Paysystem_Transaction_Jvzoo::CGBK:
            case Am_Paysystem_Transaction_Jvzoo::INSF:
                $this->invoice->addRefund($this, $this->plugin->getDi()->invoicePaymentTable->getLastReceiptId($this->invoice->pk()));
                //$this->invoice->stopAccess($this);
                break;
            //cancel
            case Am_Paysystem_Transaction_Jvzoo::CANCEL_REBILL:
                $this->invoice->setCancelled(true);
                break;
            //un cancel
            case Am_Paysystem_Transaction_Jvzoo::UNCANCEL_REBILL:
                $this->invoice->setCancelled(false);
                break;
        }
    }

    public function findInvoiceId()
    {
        return $this->request->get('ctransreceipt');
    }
}