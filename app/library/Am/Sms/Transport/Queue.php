<?php

class  Am_Sms_Transport_Queue implements Am_Sms_Transport_Interface
{
    protected Am_Sms_Transport_Interface $transport;
    protected $di;

    function __construct(Am_Sms_Transport_Interface $transport, ?Am_Di $di = null)
    {
        $this->transport = $transport;
        $this->di = $di;
    }

    function  getDi(): Am_Di
    {
        return $this->di??Am_Di::getInstance();
    }

    public function getId($oldStyle = true)
    {
        return $this->transport->getId();
    }

    public function send(Am_Sms_Message $message): bool
    {
        /**
         * @var SmsQueue $queueRecord;
         */
        $queueRecord = $this->getDi()->smsQueueTable->createFromMessage($message);
        // Send Immediately if priority is high, if not leave it to cron
        return ($queueRecord->priority > Am_Sms_Message::PRIORITY_REGULAR ? $this->sendSaved($queueRecord) : true);
    }

    public function sendSaved(SmsQueue $record)
    {
        if($this->transport->checkLimits())
        {
            $result = $this->transport->send($record->getMessage());
            if($result)
            {
                $record->statusOk();
            }
            else
            {
                $record->statusError($this->transport->getLastError());
            }
            return $result;
        }
        return true;
    }

    public function getLastError(): ?string
    {
        return $this->transport->getLastError();
    }

    public function checkLimits(): bool
    {
        return $this->transport->checkLimits();
    }
}