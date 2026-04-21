<?php

class BillingPlanBillingPlanCategory extends Am_Record {}

class BillingPlanBillingPlanCategoryTable extends Am_Table
{
    protected $_key = 'billing_plan_billing_plan_category_id';
    protected $_table = '?_billing_plan_billing_plan_category';
    protected $_recordClass = 'BillingPlanBillingPlanCategory';
}