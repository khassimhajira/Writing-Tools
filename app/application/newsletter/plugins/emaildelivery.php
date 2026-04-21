<?php
/**
 * emaildelivery.com
 */
class Am_Newsletter_Plugin_Emaildelivery extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel('API Key')
            ->addRule('required');
        $form->addtext('url', ['class' => 'am-el-wide'])
            ->setLabel("URL\nurl of your emaildelivery installation")
            ->addRule('required');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
    }

    function isConfigured()
    {
        return $this->getConfig('api_key') && $this->getConfig('url');
    }

    function getLists()
    {
        $resp = $this->doRequest('lists');
        $ret = [];
        foreach ($resp as $l) {
            $ret[$l['id']] = ['title' => $l['name']];
        }
        return $ret;
    }

    function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $ID) {
            $this->doRequest("lists/$ID/feed", [
                'email' => $user->email,
                'data' => [
                    'First Name' => $user->name_f,
                    'Last Name' => $user->name_l,
                ]
            ], 'POST');
        }
        foreach ($deleteLists as $ID) {
            $this->doRequest("lists/$ID/deletecontacts", [$user->email], 'POST');
        }
        return true;
    }

    function doRequest($slug, $params = [], $method = 'GET')
    {
        $req = new Am_HttpRequest($this->url($slug), $method);
        $req->setHeader('X-Auth-APIKey', $this->getConfig('api_key'));
        if ($params) {
            $req->setHeader('Content-Type', 'application/json');
            $req->setBody(json_encode($params));
        }

        $resp = $req->send();
        $this->debug($req, $resp, $slug);
        if (!$body = $resp->getBody())
            return [];

        return json_decode($body, true);
    }

    function url($slug)
    {
        $url = rtrim($this->getConfig('url'), '/');
        return "$url/api/$slug";
    }
}