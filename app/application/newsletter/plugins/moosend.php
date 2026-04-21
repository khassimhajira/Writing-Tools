<?php

class Am_Newsletter_Plugin_Moosend extends Am_Newsletter_Plugin
{
    const API_URL = 'https://api.moosend.com/v3/';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('apikey', ['class' => 'am-el-wide'])
            ->setLabel("API Key\nMoosend -> Settings -> API Key")
            ->addRule('required');

        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
    }

    function isConfigured()
    {
        return $this->getConfig('apikey');
    }

    function getLists()
    {
        $resp = $this->doRequest('lists');
        $ret = [];
        foreach ($resp['Context']['MailingLists'] as $l) {
            $ret[$l['ID']] = ['title' => $l['Name']];
        }
        return $ret;
    }

    function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $this->doRequest('contacts', [
            'email' => $user->email,
            'updateEnabled' => true,
        ], 'POST');

        foreach ($addLists as $ID) {
            $this->doRequest("subscribers/{$ID}/subscribe", [
                'Name' => $user->getName(),
                'Email' => $user->email,
                'HasExternalDoubleOptIn' => true,
            ], 'POST');
        }
        foreach ($deleteLists as $ID) {
            $this->doRequest("subscribers/{$ID}/unsubscribe", [
                'Email' => $user->email
            ], 'POST');
        }
        return true;
    }

    function doRequest($method, $params = [], $verb = 'GET')
    {
        $req = new Am_HttpRequest();
        $req->setHeader('Accept', 'application/json');
        $req->setMethod($verb);
        switch ($verb) {
            case 'GET' :
                $req->setUrl(self::API_URL . $method . '.json?' . http_build_query(['apikey' => $this->getConfig('apikey')] + $params));
                break;
            case 'POST' :
                $req->setUrl(self::API_URL . $method . '.json?' . http_build_query(['apikey' => $this->getConfig('apikey')]) );
                $req->addPostParameter($params);
                break;
        }

        $resp = $req->send();
        $this->debug($req, $resp, $method);
        if (!$body = $resp->getBody())
            return [];

        return json_decode($body, true);
    }
}