<?php

class Am_Form_Setup_Webhooks extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('webhooks');
        $this->setTitle(___('Webhooks'));
    }

    function initElements()
    {
        $this->addElement('email_checkbox', 'webhooks.admin_failed')
            ->setLabel(___("Notify About Permanently Failed Webhooks"));
    }
}