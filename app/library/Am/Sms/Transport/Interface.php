<?php

interface Am_Sms_Transport_Interface
{
    public function getId($oldStyle = true);

    /**
     * @param Am_Sms_Message $message
     * @return bool truw if message was sent
     */
    public function send(Am_Sms_Message $message): bool;

    public function getLastError(): ?string;

    /**
     * @return bool true if Limits were not reached
     */
    public function checkLimits(): bool;
}