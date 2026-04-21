<?php
class Am_Paysystem_ManualRebill extends Am_Paysystem_CreditCard
{
    protected $_pciDssNotRequired = true;

    function storesCcInfo()
    {
        return false;
    }

    public function supportsCancelPage()
    {
        return true;
    }

    public function _doBill(Invoice $invoice, $doFirst, CcRecord $cc, Am_Paysystem_Result $result)
    {
        // To trigger rebill on failure mechanism.
        $result->setFailed('Failed');
    }

    function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $fs = $this->getExtraSettingsFieldSet($form);
        $id = $this->getId();

        $fs->addElement('email_link', "payment.{$id}.email_before_rebill")
            ->setLabel(___("Before Rebill Email
            Message will be sent before rebill date"));

        $fs->addElement('email_link', "payment.{$id}.email_failed_rebill")
            ->setLabel(___("Manual Payment Required Email
            Message will be sent on rebill date and later according to Reattempt on failure settings"));
    }

    static function getEtXml()
    {
        $id = func_get_arg(0);

        return <<<CUT
<table_data name="email_template">
    <row type="email_template">
        <field name="name">payment.{$id}.email_failed_rebill</field>
        <field name="email_template_layout_id">1</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Subscription Renewal Failed!</field>
        <field name="txt"><![CDATA[
Your subscription was not renewed in time and was suspended by our membership system

Please use this link to complete your subscription payment manually:
%manual_rebill_link%

Thank you for your attention!
        ]]></field>
    </row>
    <row type="email_template">
        <field name="name">payment.{$id}.email_before_rebill</field>
        <field name="email_template_layout_id">1</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Please Complete Subscription Renewal!</field>
        <field name="txt"><![CDATA[
Your subscription is about to expire.

Please use this link to complete your subscription payment manually:
%manual_rebill_link%

Thank you for your attention!
        ]]></field>
    </row>

</table_data>
CUT;
    }

    function onSetupEmailTemplateTypes(Am_Event $e)
    {
        $id = $this->getId();
        $title = $this->getTitle();
        $e->addReturn([
            'id' => "payment.{$id}.email_before_rebill",
            'title' => "{$title} Rebill Notificaton",
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => [
                'user',
                'invoice',
                'product_title' => ___('Product(s) Title'),
                'prorate' => ___('Information about next billing attempt, if applicable'),
                'manual_rebill_link' => ___('Information about manual rebill link, if applicable')
            ],
        ], "payment.{$id}.email_before_rebill");

        $e->addReturn([
            'id' => "payment.{$id}.email_failed_rebill",
            'title' => "{$title} Rebill Failed Notificaton",
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => [
                'user',
                'invoice',
                'product_title' => ___('Product(s) Title'),
                'prorate' => ___('Information about next billing attempt, if applicable'),
                'manual_rebill_link' => ___('Information about manual rebill link, if applicable')
            ],

        ], "payment.{$id}.email_failed_rebill");

    }
    function supportsManualRebill()
    {
        return true;
    }

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    function onDaily()
    {
        foreach ($this->getDi()->invoiceTable->findForRebill(sqlDate('+3 days'), $this->getId()) as $invoice) {
            $this->sendMessageBeforeRebill($invoice);
        }
    }

    function isManualRebillPossible(Invoice $invoice)
    {
        return
            parent::isManualRebillPossible($invoice)
            || (
                $this->supportsManualRebill()
                && $this->getConfig('manual_rebill_enabled')
                && in_array($invoice->status, [Invoice::RECURRING_ACTIVE, Invoice::RECURRING_FAILED])
                && $invoice->rebill_date <= sqlDate('+3 days')
            );
    }

    function sendMessageBeforeRebill($invoice)
    {
        $id = $this->getId();
        try {
            if ($et = Am_Mail_Template::load("payment.{$id}.email_before_rebill")) {
                $et->setUser($invoice->getUser());
                $et->setInvoice($invoice);
                $products = [];
                foreach ($invoice->getProducts() as $product) {
                    $products[] = $product->getTitle();
                }
                $et->setProduct_title(implode(", ", $products));
                $et->setProduct_title_html(sprintf('<ul>%s</ul>', implode("\n", array_map(fn($_) => sprintf('<li>%s</li>', Am_Html::escape($_)), $products))));

                $et->setManual_rebill_link(
                    $this->isManualRebillPossible($invoice) ? $this->getDi()->surl('login', ['_amember_redirect_url' => base64_encode($this->getManualRebillUrl($invoice))]) : ""
                );
                $et->setMailPeriodic(Am_Mail::USER_REQUESTED);
                $et->send($invoice->getUser());
            }
        } catch (Exception $e) {
            // No mail exceptions when  rebilling;
            $this->getDi()->logger->error("Could not send renewal message for  invoice#{$invoice->public_id}",
                ["exception" => $e]);
        }
    }

    public function onRebillFailure(Invoice $invoice, CcRecord $cc, Am_Paysystem_Result $result, $date)
    {
        $this->prorateInvoice($invoice, $cc, $result, $date);
        $this->sendRebillFailedToUser($invoice, $result->getLastError(), $invoice->rebill_date);
    }

    function sendRebillFailedToUser(Invoice $invoice, $failedReason, $nextRebill)
    {
        $id = $this->getId();
        try {
            if ($et = Am_Mail_Template::load("payment.{$id}.email_failed_rebill")) {
                $et->setUser($invoice->getUser());
                $et->setInvoice($invoice);
                $products = [];
                foreach ($invoice->getProducts() as $product) {
                    $products[] = $product->getTitle();
                }
                $et->setProduct_title(implode(", ", $products));
                $et->setProduct_title_html(sprintf('<ul>%s</ul>', implode("\n", array_map(fn($_) => sprintf('<li>%s</li>', Am_Html::escape($_)), $products))));

                $et->setProrate(
                    ($nextRebill > $this->getDi()->sqlDate) ?
                        sprintf(___('Our system will try to charge your card again on %s'), amDate($nextRebill)) : ""
                );

                $et->setManual_rebill_link(
                    $this->isManualRebillPossible($invoice) ? $this->getDi()->surl('login', ['_amember_redirect_url' => base64_encode($this->getManualRebillUrl($invoice))]) : ""
                );
                $et->setMailPeriodic(Am_Mail::USER_REQUESTED);
                $et->send($invoice->getUser());
            }
        } catch (Exception $e) {
            // No mail exceptions when  rebilling;
            $this->getDi()->logger->error("Could not sendRebillFailedToUser invoice#{$invoice->public_id}",
                ["exception" => $e]);
        }
    }

    function sendRebillSuccessToUser(Invoice $invoice)
    {
        // Disabled;
    }

    function sendStatisticsEmail()
    {
        // Disabled
    }
}
