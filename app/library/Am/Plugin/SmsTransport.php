<?php

abstract class Am_Plugin_SmsTransport extends Am_Plugin implements Am_Sms_Transport_Interface
{
    protected $_configPrefix = 'misc.';

    static $lastError;

    function setLastError(?string $error = null)
    {
        self::$lastError = $error;
    }

    function getLastError(): ?string
    {
        return self::$lastError;
    }

    function setupHooks()
    {
        parent::setupHooks();
        if ($this->isConfigured()) {
            $this->getDi()->hook->add(Am_Event::INIT_SMS_TRANSPORT, function (Am_Event $event)
            {
                $event->addReturn($this, $this->getId());
            });
        }
    }
}