<?php

abstract class Am_Otp_Adapter_Abstract implements Am_Otp_Adapter_Interface
{
    protected User $user;
    protected $id;
    
    function __construct(User $user)
    {
        $this->user = $user;
    }
    
    function getId()
    {
        if (empty($this->id)) {
            $_ = explode('\\', get_class($this));
            $class = array_pop($_);
            $parts = explode("Am_Otp_Adapter_", $class);
            $this->id = strtolower(array_pop($parts));
        }
        return $this->id;
    }
    
    function getTitle()
    {
        return ucfirst($this->getId());
    }
    
    function getUser(): User
    {
        return $this->user;
    }
    
}