<?php

class Am_Api_SavedForm extends Am_ApiController_Table
{
    function createQuery()
    {
        $qry = new Am_Query($table = $this->getDi()->savedFormTable);
        $qry->addWhere('`type` in (?a)', array_keys($table->getTypeDefs()));
        return $qry;
    }

    function index($request, $response, $args)
    {
        $total = 0;
        $forms = $this->selectRecords($total, false, $request);

        foreach ($forms as $_) {
            /** @var SavedForm $_ */
            $_->title = sprintf("%s (%s)", $_->title, $_->comment);
            $_->url = $this->getDi()->surl($_->getUrl());
        }
        return $this->apiOutRecords($forms, ['_total' => $total], $request, $response);
    }
}