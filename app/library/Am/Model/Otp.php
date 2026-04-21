<?php

class Otp extends Am_Record
{
    protected User $_user;
    protected Am_Otp_Adapter_Interface $_adapter;

    function setUser(User $user): self
    {
        $this->_user = $user;
        $this->user_id = $user->pk();
        return $this;
    }

    function getUser(): User
    {
        if (empty($this->_user)) {
            $this->_user = $this->getDi()->userTable->load($this->user_id);
        }
        return $this->_user;
    }

    function isExpired(): bool
    {
        return $this->expires < $this->getDi()->sqlDateTime;
    }
}

class OtpTable extends Am_Table
{
    protected $_key = 'otp_id';
    protected $_table = '?_otp';
    protected $_recordClass = 'Otp';

    function cleanUp()
    {
        $this->getDi()->db->query("DELETE FROM ?_otp WHERE expires<?", $this->getDi()->sqlDateTime);
    }
}