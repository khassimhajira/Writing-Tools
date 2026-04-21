<?php

interface Am_Otp_Adapter_Interface
{
    
    /**
     * @return mixed Adapter ID: phone, email, etc..
     */
    function getId();
    
    /**
     * @return mixed Title to be displayed in selects
     */
    
    function getTitle();
    
    /**
     * @param Otp $otp
     * @return mixed
     */
    function sendCode(Otp $otp);
    
    /**
     * Set data source as verified.
     * @return $this
     */
    function setVerified(): self;
    
    /**
     * @return bool Check whenver adapter is using verified datasource
     */
    function isVerified(): bool;
    
    
    function getUser(): User;
    
    /**
     * @return string Masked phone/email to be displayed to customer
     */
    function getMaskedAddress(): string;
}