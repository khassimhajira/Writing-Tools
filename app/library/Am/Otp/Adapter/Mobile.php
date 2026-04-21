<?php

class Am_Otp_Adapter_Mobile extends Am_Otp_Adapter_Abstract
{
    function isVerified(): bool
    {
        return (bool)$this->getUser()->mobile_confirmed;
    }
    
    function setVerified(): Am_Otp_Adapter_Interface
    {
        $this->getUser()->set('mobile_confirmed', true)->set('mobile_confirmation_date',
                Am_Di::getInstance()->sqlDateTime)->update();
        return $this;
    }
    
    function sendCode(Otp $otp)
    {
        $di = Am_Di::getInstance();
        
        $msg = $di->config->get('otp-sms-message', ___("Your %site_title% PIN code is %code%"));
        
        $tmpl = new Am_SimpleTemplate();
        $tmpl->assign('site_title', $di->config->get('site_title'));
        $tmpl->assign('code', $otp->code);
        
        $message = new Am_Sms_Message();
        $message->setTo($this->getUser());
        $message->setBody($tmpl->render($msg));
        $message->setPriority(Am_Sms_Message::PRIORITY_ONETIME);
        $message->send();
    }
    
    function getMaskedAddress(): string
    {
        $phone = $this->getUser()->getMobile();
        
        return preg_replace("/\d/", "*", substr($phone, 0, strlen($phone) - 2)) . substr($phone, -2);
    }
}