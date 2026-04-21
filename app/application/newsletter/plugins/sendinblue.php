<?php

class Am_Newsletter_Plugin_Sendinblue extends Am_Newsletter_Plugin
{
    const API_URL = 'https://api.sendinblue.com/v3/';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel('API Key')
            ->addRule('required');
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    function getLists()
    {
        $resp = $this->doRequest('contacts/lists', [
            'limit' => 50
        ]);
        $ret = [];
        foreach ($resp['lists'] as $l) {
            $ret[$l['id']] = ['title' => $l['name']];
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
            $this->doRequest("contacts/lists/{$ID}/contacts/add", [
                'emails' => [$user->email]
            ], 'POST');
        }
        foreach ($deleteLists as $ID) {
            $this->doRequest("contacts/lists/{$ID}/contacts/remove", [
                'emails' => [$user->email]
            ], 'POST');
        }
        return true;
    }

    function doRequest($method, $params = [], $verb = 'GET')
    {
        $req = new Am_HttpRequest();
        $req->setHeader('Api-key', $this->getConfig('api_key'));
        $req->setHeader('Accept', 'application/json');
        $req->setMethod($verb);
        switch ($verb) {
            case 'GET' :
                $req->setUrl(self::API_URL . $method . '?' . http_build_query($params));
                break;
            case 'POST' :
                $req->setUrl(self::API_URL . $method);
                $req->setBody(json_encode($params));
                $req->setHeader('Content-Type', 'application/json');
                break;
        }

        $resp = $req->send();
        $this->debug($req, $resp);
        if (!$body = $resp->getBody())
            return [];

        return json_decode($body, true);
    }

}