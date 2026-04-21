<?php

class Am_Sms_Transport_Null implements Am_Sms_Transport_Interface
{
    function getId($oldStyle = true)
    {
        return 'disabled';
    }

    public function send(Am_Sms_Message $message): bool
    {
        return true;
    }

    public function getLastError(): ?string
    {
        return null;
    }

    public function checkLimits(): bool
    {
        return true;
    }
}