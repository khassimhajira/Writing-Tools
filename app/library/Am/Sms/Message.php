<?php

class Am_Sms_Message
{
    const PRIORITY_ONETIME = 3; // Send Immediately, return error if unable to sent, do not resent
    const PRIORITY_HIGH = 2; // Send Immediately, schedule to resend if wasn't sent
    const PRIORITY_REGULAR = 1; // Send  through queue with high priority
    const PRIORITY_LOW = 0; // Send through queue with low priority

    protected ?string $to;
    protected ?string $body;
    protected int $priority;
    protected ?int $userId;

    function __construct(?string $to = null, ?string $message = null, ?int $priority = null)
    {
        $this->to = $to;
        $this->body = $message;
        $this->priority = $priority ?? self::PRIORITY_REGULAR;
    }

    /**
     * @param $to string|User
     * @return $this
     */
    function setTo($to): self
    {
        if ($to instanceof User) {
            $this->to = $to->getMobile();
            $this->setUserId($to->pk());
        } else {
            $this->to = $to;
        }
        return $this;
    }

    function setBody(string $message): self
    {
        $this->body = $message;
        return $this;
    }

    function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    function getPriority(): int
    {
        return $this->priority;
    }

    function getTo(): string
    {
        return $this->to;
    }

    function getBody(): string
    {
        return $this->body;
    }

    function getUserId(): ?int
    {
        return $this->userId??null;
    }

    function setUserId(?int $user_id = null) : self
    {
        $this->userId = $user_id;
        return $this;
    }

    function send(?Am_Sms_Transport_Interface $transport = null)
    {
        if (empty($transport)) {
            $transport = Am_Di::getInstance()->smsTransport;
        }
        if (!($transport instanceof Am_Sms_Transport_Queue)) {
            $transport = new Am_Sms_Transport_Queue($transport);
        }

        $transport->send($this);
    }
}