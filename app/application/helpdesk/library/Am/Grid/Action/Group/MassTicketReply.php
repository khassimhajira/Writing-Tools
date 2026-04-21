<?php

class Am_Grid_Action_Group_MassTicketReply extends Am_Grid_Action_Group_Form
{
    protected Am_Helpdesk_Strategy_Abstract $strategy;

    public function __construct()
    {
        parent::__construct('mass-ticket-reply', ___('Mass Reply'));
        $this->setTarget('_top');
        $this->strategy = $this->getDi()->helpdeskStrategy;
    }

    public function handleRecord($id, $ticket)
    {
        if (!$this->strategy->canEditTicket($ticket)) {
            throw new Am_Exception_AccessDenied(___('Access Denied'));
        }

        $vars = $this->getForm()->getValue();

        $message = $this->getDi()->helpdeskMessageRecord;
        $message->content = $vars['content'];
        $message->ticket_id = $ticket->ticket_id;
        $message->type = 'message';
        $message->setAttachments($vars['attachments']);
        $message = $this->strategy->fillUpMessageIdentity($message);
        $message->save();

        $this->strategy->onAfterInsertMessage($message, $ticket);

        $ticket->status = $this->strategy->getTicketStatusAfterReply($message);
        $ticket->updated = $this->getDi()->sqlDateTime;
        $ticket->save();
        if (isset($vars['_close']) && $vars['_close']) {
            $ticket->status = HelpdeskTicket::STATUS_CLOSED;
            $ticket->save();
        }
    }

    public function createForm(): Am_Form
    {
        $form = $this->strategy->createForm();

        $form->addTextarea('content', [
            'rows' => 7,
            'class' => 'am-no-label am-el-wide',
            'placeholder' => ___('Write your reply...')
        ])
            ->addRule('required');

        $this->strategy->addUpload($form);

        $form->addAdvCheckbox('_close', null, ['content' => ___('Close This Ticket After Response')]);

        $form->addSaveButton(___('Reply'));

        return $form;
    }

    function getDi(): Am_Di
    {
        return Am_Di::getInstance();
    }
}