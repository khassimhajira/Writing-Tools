<?php

class Am_Form_Setup_Newsletter extends Am_Form_Setup
{
    
    function __construct()
    {
        parent::__construct('newsletter');
        $this->setTitle(___('Newsletter'));
    }
    
    function initElements()
    {
        $this->addElement('email_checkbox', 'newsletter.list_subscription_removed')->setLabel(___('List Unsubscribed Notification'));
        
    }

}