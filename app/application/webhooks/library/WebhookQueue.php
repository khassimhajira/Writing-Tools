<?php

class WebhookQueue extends Am_Record
{
    function getHeaders(): array
    {
        return array_filter(array_map('trim', explode("\n", $this->headers ?? '')));
    }
}

class WebhookQueueTable extends Am_Table
{
    protected $_key = 'webhook_queue_id';
    protected $_table = '?_webhook_queue';
    protected $_recordClass = 'WebhookQueue';
}