<?php

class Charge implements IProduct
{
    protected $amount, $currency, $title, $desc, $tax_group, $billing_plan_data;

    function __construct($amount, $currency, $title = '', $desc = '', $tax_group = IProduct::NO_TAX, $billing_plan_data = [])
    {
        $this->amount = $amount;
        $this->currency = $currency;
        $this->title = $title;
        $this->desc = $desc;
        $this->tax_group = $tax_group;
        $this->billing_plan_data = $billing_plan_data;
    }

    function getProductId()
    {
        return null;
    }

    function getType()
    {
        return 'charge';
    }

    function getTitle()
    {
        return $this->title;
    }

    function getDescription()
    {
        return $this->desc;
    }

    function getFirstPrice()
    {
        return $this->amount;
    }

    function getFirstPeriod()
    {
        return Am_Period::MAX_SQL_DATE;
    }

    function getRebillTimes()
    {
        return 0;
    }

    function getSecondPrice()
    {
        return null;
    }

    function getSecondPeriod()
    {
        return null;
    }

    function getCurrencyCode()
    {
        return $this->currency;
    }

    function getTaxGroup()
    {
        return $this->tax_group;
    }

    function getIsTangible()
    {
        return false;
    }

    function getIsCountable()
    {
        return false;
    }

    function getQty()
    {
        return 1;
    }

    function getIsVariableQty()
    {
        return false;
    }

    function getBillingPlanId()
    {
        return null;
    }

    function getBillingPlanData()
    {
        return $this->billing_plan_data;
    }

    public function getOptions()
    {
        return null;
    }

    public function setOptions(array $options)
    {
        //nop
    }

    function calculateStartDate($paymentDate, Invoice $invoice)
    {
        return $paymentDate;
    }

    function findItem(array $existingInvoiceItems)
    {
        return null;
    }

    function addQty($requestedQty, $itemQty)
    {
        return 1;
    }
}