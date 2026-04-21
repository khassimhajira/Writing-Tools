<?php

class Am_Otp_Adapter_Email extends Am_Otp_Adapter_Abstract
{
    function isVerified(): bool
    {
        return $this->getUser()->email_confirmed;
    }

    function setVerified(): Am_Otp_Adapter_Interface
    {
        $this->getUser()
            ->set('email_confirmed', true)
            ->set('email_confirmation_date', Am_Di::getInstance()->sqlDateTime)
            ->update();
        return $this;
    }

    function sendCode(Otp $otp)
    {
        $et = Am_Mail_Template::load('otp-email-message');
        $et->code = $otp->code;
        $et->user = $this->getUser();
        $et->lifetime = Am_Di::getInstance()->config->get('otp-lifetime', 30);
        $et->send($this->getUser());
    }

    function getMaskedAddress(): string
    {
        $email = $this->getUser()->email;
        [$id, $domain] = explode("@", $email);
        return sprintf("%s@%s", substr($id, 0, 1) . str_pad("", strlen($id) - 2, "*") . substr($id, -1), $domain);
    }
}