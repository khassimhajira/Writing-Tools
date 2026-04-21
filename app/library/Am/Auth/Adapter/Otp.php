<?php

class Am_Auth_Adapter_Otp implements Am_Auth_Adapter_Interface
{
    
    protected Am_Mvc_Request $request;
    
    function __construct(Am_Mvc_Request $request)
    {
        $this->request = $request;
    }
    
    public function authenticate()
    {
        try {
            $session = Am_Otp_Session::resumeFromRequest($this->request);
            if ($session->isConfirmed()) {
                $user = $session->getUser();
                $session->destroy();
                return new Am_Auth_Result(Am_Auth_Result::SUCCESS, null, $user);
            }
        } catch (Exception $ex) {
        }
        return new Am_Auth_Result(Am_Auth_Result::USER_NOT_FOUND);
    }
}