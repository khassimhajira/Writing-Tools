<?php
//https://highlevel.stoplight.io/docs/integrations/0443d7d1a4bd0-overview

class Am_Newsletter_Plugin_Gohighlevel extends Am_Newsletter_Plugin
{
    const
        AUTH_URL = 'https://marketplace.gohighlevel.com/oauth/chooselocation',
        OAUTH_URL = 'https://services.leadconnectorhq.com/oauth/token',
        GHL_ID = 'qwerty',
        ACCESS_TOKEN_KEY = 'gohighlevel-access-token',
        CONTACT_ID = 'gohighlevel-contact-id'
    ;
    function init()
    {
        $this->getDi()->router->addRoute('g-high-level', new Am_Mvc_Router_Route(
            'admin-setup/' . self::GHL_ID, [
                'module' => 'default',
                'controller' => 'admin-setup',
                'action' => 'display',
                'p' => 'gohighlevel',
            ]
        ));
    }

    function getAccessToken()
    {
        if($token = $this->getDi()->store->getBlob(self::ACCESS_TOKEN_KEY))
            return $token;
        elseif($refreshToken = $this->getConfig('refresh_token'))
        {
            $req = new Am_HttpRequest(self::OAUTH_URL, Am_HttpRequest::METHOD_POST);
            $req->addPostParameter([
                'client_id' => $this->getConfig('client_id'),
                'client_secret' => $this->getConfig('client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ]);
            $res = $req->send();
            if($this->getConfig('debug')) {
                $l = $this->getDi()->invoiceLogRecord;
                $l->paysys_id = $this->getId();
                $l->title = 'refresh_token';
                $l->add($req);
                $l->add($res);
            }
            if($res->getStatus() == 200) {
                $pre = "newsletter.{$this->getId()}";
                $values = json_decode($res->getBody(), true);
                $this->getDi()->store->setBlob(self::ACCESS_TOKEN_KEY, $values['access_token'], sqlTime("+$values[expires_in] seconds"));
                $this->getDi()->config->saveValue("$pre.refresh_token", $values['refresh_token']);
                return $values['access_token'];
            }
        }
        return false;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $pre = "newsletter.{$this->getId()}";
        if (!empty($_GET['reset_token_' . $this->getId()])) {
            $this->getDi()->store->setBlob(self::ACCESS_TOKEN_KEY, false);
            $this->getDi()->config->saveValue("$pre.refresh_token", '');
            $this->getDi()->config->saveValue("$pre.location_id", '');
            return Am_Mvc_Response::redirectLocation($this->getDi()->url("admin-setup/{$this->getId()}", false));
        }
        $form->addText('client_id', ['class' => 'am-el-wide'])
            ->setLabel('Client ID')
            ->addRule('required');
        $form->addSecretText('client_secret', ['class' => 'am-el-wide'])
            ->setLabel('Client Secret')
            ->addRule('required');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
        if($this->isConfigured())
        {
            if(($code = @$_GET['code']))
            {
                $req = new Am_HttpRequest(self::OAUTH_URL, Am_HttpRequest::METHOD_POST);
                $req->addPostParameter([
                    'client_id' => $this->getConfig('client_id'),
                    'client_secret' => $this->getConfig('client_secret'),
                    'grant_type' => 'authorization_code',
                    'code' => $code
                ]);
                $res = $req->send();
                if($this->getConfig('debug')) {
                    $l = $this->getDi()->invoiceLogRecord;
                    $l->paysys_id = $this->getId();
                    $l->title = 'authorization_code';
                    $l->add($req);
                    $l->add($res);
                }
                if($res->getStatus() == 200) {
                    $values = json_decode($res->getBody(), true);
                    $this->getDi()->store->setBlob(self::ACCESS_TOKEN_KEY, $values['access_token'], sqlTime("+$values[expires_in] seconds"));
                    $this->getDi()->config->saveValue("$pre.refresh_token", $tk_ = $values['refresh_token']);
                    $this->getDi()->config->saveValue("$pre.location_id", @$lc_ = $values['locationId']);
                }
            }
            else
                $tk_ = $this->getConfig('refresh_token');
            if ($tk = $this->getConfig('refresh_token', @$tk_))
            {
                $form->addStatic()->setContent('<a href="?reset_token_'.$this->getId().'=1">Reset Token ['.substr($tk, 0, 10).'...]</a>')
                    ->setLabel(___("Follow the link to reset the token"));
                $form->addStatic()->setContent($this->getConfig('location_id', $lc_))
                    ->setLabel(___("Location ID"));
            }
            else
            {
                $url = self::AUTH_URL . '?' . http_build_query([
                    'scope' => 'contacts.write conversations/message.readonly conversations/message.write campaigns.readonly',
                    'response_type' => 'code',
                    'redirect_uri' => $this->getDi()->url('admin-setup/' . self::GHL_ID, null, false, true),
                    'client_id' => $this->getConfig('client_id')
                ]);
                $form->addHTML()->setHTML(<<<HTML
                <a href="{$url}" target="_top">Authorize APP</a>
HTML
                );
            }
        }
    }

    function isConfigured()
    {
        return strlen($this->getConfig('client_id')) && strlen($this->getConfig('client_secret'));
    }

    public function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $user = $event->getUser();
        if(!($cid = $user->data()->get(self::CONTACT_ID)))
            return;
        $oldUser = $event->getOldUser();
        if($user->getName() != $oldUser->getName || $user->email != $oldUser->email)
        {
            $this->getApi()->put("contacts/$cid", [
                'firstName' => $user->name_f,
                'lastName' => $user->name_l,
                'email' => $user->email,
                'locationId' => $this->getConfig('location_id'),
                'phone' => $user->phone,
                'address1' => $user->street,
                'city' => $user->city,
                'state' => $user->state,
                'postalCode' => $user->zip,
                'country' => $user->country
            ]);
        }
    }

    public function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $user = $event->getUser();
        if(!($cid = $user->data()->get(self::CONTACT_ID)))
            return;
        $this->getApi()->delete("contacts/$cid");
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        if(count($deleteLists) && ($cid = $user->data()->get(self::CONTACT_ID)))
            foreach ($deleteLists as $list_id)
            {
                if (!count($api->delete("contacts/{$cid}/campaigns/{$list_id}")))
                    return false;
            }
        if(count($addLists))
        {
            if(!($cid = $user->data()->get(self::CONTACT_ID)))
            {
                $res = $api->post('contacts/',[
                    'firstName' => $user->name_f,
                    'lastName' => $user->name_l,
                    'email' => $user->email,
                    'locationId' => $this->getConfig('location_id'),
                    'phone' => $user->phone,
                    'address1' => $user->street,
                    'city' => $user->city,
                    'state' => $user->state,
                    'postalCode' => $user->zip,
                    'country' => $user->country
                ]);
                if(!empty($res['contact']['id']))
                {
                    $cid = $res['contact']['id'];
                    $user->data()->set(self::CONTACT_ID, $cid)->update();
                }
                else
                    return false;
            }
        }
        foreach ($addLists as $list_id)
        {
            if (!count($api->post("contacts/{$cid}/campaigns/{$list_id}")))
                return false;
        }
        return true;
    }

    function getAPI()
    {
        return new Am_Gohighlevel_Api($this);
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = [];
        $lists = $api->get('campaigns/?locationId=' . $this->getConfig('location_id'));
        if(isset($lists['campaigns']))
            foreach (@$lists['campaigns'] as $l)
                $ret[$l['id']] = ['title' => $l['name']];
        return $ret;
    }
}

class Am_Gohighlevel_Api extends Am_HttpRequest
{

    /** @var Am_Newsletter_Plugin_Gohighlevel */
    protected
        $plugin;
    protected
        $vars = []; // url params
    protected
        $params = []; // request params\

    const
        API_URL = 'https://services.leadconnectorhq.com';

    public
    function __construct(Am_Newsletter_Plugin_Gohighlevel $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
        $this->setHeader('Authorization: Bearer ' . $this->plugin->getAccessToken())
            ->setHeader('Version: 2021-04-15')
            ->setHeader('Accept: application/json');
    }

    public
    function sendRequest($method, $params = [], $httpMethod = 'POST')
    {
        $this->setMethod($httpMethod);

        $this->setUrl($url = self::API_URL . '/' . $method);
        if ($params)
        {
            $this->setHeader('Content-Type: application/json');
            $this->setBody(json_encode($params));
        }
        else
        {

            $this->setBody('');
        }

        $ret = parent::send();
        $this->plugin->debug($this, $ret, $url);

        if (!in_array($ret->getStatus(), [200, 201, 202, 204]))
        {
            Am_Di::getInstance()->logger->error("Gohighlevel API Error - [" . $ret->getBody() . "]");
            return [];
        }
        $body = $ret->getBody();
        if (!$body)
            return [];
        $arr = json_decode($body, true);
        if (!$arr || @$arr['errors'])
        {
            Am_Di::getInstance()->logger->error("Gohighlevel API Error - [" . implode(', ', @$arr['errors']) . "]");
            return [];
        }
        return $arr;
    }

    function get($method)
    {
        return $this->sendRequest($method, [], 'GET');
    }

    function post($method, $data = [])
    {
        return $this->sendRequest($method, $data, 'POST');
    }

    function put($method, $data = [])
    {
        return $this->sendRequest($method, $data, 'PUT');
    }

    function delete($method, $data = [])
    {
        return $this->sendRequest($method, $data, 'DELETE');
    }

}