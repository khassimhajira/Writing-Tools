<?php

class Am_Import_FieldMobileAreaCode extends Am_Import_Field
{
    protected function _setValueForRecord($record, $value)
    {
        //RU+7
        if (preg_match('/^[A-Z]{2}\+[0-9]+$/', $value)) {
            $record->mobile_area_code = $value;
            //+7
        } elseif (preg_match('/^\+([0-9]+)$/', $value, $m)) {
            if ($country = $this->getDi()->countryTable->findFirstByPhoneCode($m[1])) {
                $record->mobile_area_code = "$country->country+$m[1]";
            }
            //7
        } elseif (is_numeric($value)) {
            if ($country = $this->getDi()->countryTable->findFirstByPhoneCode($m[1])) {
                $record->mobile_area_code = "$country->country+$m[1]";
            }
        }
    }
}