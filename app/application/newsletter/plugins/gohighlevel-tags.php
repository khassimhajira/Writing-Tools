<?php
//https://highlevel.stoplight.io/docs/integrations/0443d7d1a4bd0-overview

class Am_Newsletter_Plugin_GohighlevelTags extends Am_Newsletter_Plugin
{
    const AUTH_URL = 'https://marketplace.gohighlevel.com/oauth/chooselocation';
    const OAUTH_URL = 'https://services.leadconnectorhq.com/oauth/token';
    const ACCESS_TOKEN_KEY = 'gohighlevel-tags-access-token';
    const CONTACT_ID = 'gohighlevel-tags-contact-id';
    const GHL_ID = 'qwerty';

    function init()
    {
        //GHL does not allow Highlevel reference in redirect url
        $this->getDi()->router->addRoute('g-high-level', new Am_Mvc_Router_Route(
            'admin-setup/' . self::GHL_ID, [
                'module' => 'default',
                'controller' => 'admin-setup',
                'action' => 'display',
                'p' => 'gohighlevel-tags',
            ]
        ));
    }

    function getAccessToken()
    {
        if ($token = $this->getDi()->store->getBlob(self::ACCESS_TOKEN_KEY)) {
            return $token;
        } elseif($refreshToken = $this->getConfig('refresh_token')) {
            $req = new Am_HttpRequest(self::OAUTH_URL, Am_HttpRequest::METHOD_POST);
            $req->addPostParameter([
                'client_id' => $this->getConfig('client_id'),
                'client_secret' => $this->getConfig('client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ]);
            $res = $req->send();
            $this->debug($req, $res, 'refresh_token');
            if ($res->getStatus() == 200) {
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
        $form->addSelect('default_country', ['class' => 'am-combobox'])
            ->setLabel('Default Country')
            ->loadOptions(['' => ''] + $this->getDi()->countryTable->getOptions());
        if ($this->isConfigured())
        {
            if ($code = @$_GET['code']) {
                $req = new Am_HttpRequest(self::OAUTH_URL, Am_HttpRequest::METHOD_POST);
                $req->addPostParameter([
                    'client_id' => $this->getConfig('client_id'),
                    'client_secret' => $this->getConfig('client_secret'),
                    'grant_type' => 'authorization_code',
                    'code' => $code
                ]);
                $res = $req->send();
                $this->debug($req, $res, 'authorization_code');

                if ($res->getStatus() == 200) {
                    $values = json_decode($res->getBody(), true);
                    $this->getDi()->store->setBlob(self::ACCESS_TOKEN_KEY, $values['access_token'], sqlTime("+$values[expires_in] seconds"));
                    $this->getDi()->config->saveValue("$pre.refresh_token", $tk_ = $values['refresh_token']);
                    $this->getDi()->config->saveValue("$pre.location_id", @$lc_ = $values['locationId']);
                }
            } else {
                $tk_ = $this->getConfig('refresh_token');
            }
            if ($tk = $this->getConfig('refresh_token', @$tk_)) {
                $form->addStatic()->setContent('<a href="?reset_token_'.$this->getId().'=1">Reset Token</a>')
                    ->setLabel(___("Follow the link to reset the token"));
                $form->addStatic()->setContent($this->getConfig('location_id', @$lc_))
                    ->setLabel(___("Location ID"));
            } else {
                $url = self::AUTH_URL . '?' . http_build_query([
                    'scope' => 'contacts.write locations/tags.readonly',
                    'response_type' => 'code',
                    'redirect_uri' => $this->getDi()->surl('admin-setup/' . self::GHL_ID,  false),
                    'client_id' => $this->getConfig('client_id')
                ]);
                $form->addHTML()->setHTML(<<<CUT
                <a href="{$url}" target="_top">Authorize APP</a>
CUT
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
        if (!($cid = $user->data()->get(self::CONTACT_ID))) {
            return;
        }
        $oldUser = $event->getOldUser();
        if (
            $user->getName() != $oldUser->getName
            || $user->email != $oldUser->email
        ) {
            $this->getApi()->put("contacts/$cid", [
                'firstName' => $user->name_f,
                'lastName' => $user->name_l,
                'email' => $user->email,
                'phone' => $user->phone,
                'address1' => $user->street,
                'city' => $user->city,
                'state' => $user->state,
                'postalCode' => $user->zip,
                'country' => $user->country ?: $this->getConfig('default_country'),
            ]);
        }
    }

    public function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $user = $event->getUser();
        if (!($cid = $user->data()->get(self::CONTACT_ID))) {
            return;
        }
        $this->getApi()->delete("contacts/$cid");
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        if (
            count($deleteLists)
            && ($cid = $user->data()->get(self::CONTACT_ID))
        ) {
            if (!count($api->delete("contacts/{$cid}/tags", ['tags' => $deleteLists]))) {
                return false;
            }
        }
        if (count($addLists)) {
            if (!($cid = $user->data()->get(self::CONTACT_ID))) {
                $res = $api->post('contacts/upsert',[
                    'firstName' => $user->name_f,
                    'lastName' => $user->name_l,
                    'email' => $user->email,
                    'locationId' => $this->getConfig('location_id'),
                    'phone' => $user->phone,
                    'address1' => $user->street,
                    'city' => $user->city,
                    'state' => $user->state,
                    'postalCode' => $user->zip,
                    'country' => $user->country ?: $this->getConfig('default_country'),
                ]);
                if (!empty($res['contact']['id'])) {
                    $cid = $res['contact']['id'];
                    $user->data()->set(self::CONTACT_ID, $cid)->update();
                } else {
                    return false;
                }
            }

            if (!count($api->post("contacts/{$cid}/tags", ['tags' => $addLists]))) {
                return false;
            }
        }
        return true;
    }

    function getAPI()
    {
        return new Am_GohighlevelTags_Api($this);
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = [];
        $lists = $api->get("locations/{$this->getConfig('location_id')}/tags");
        if (isset($lists['tags'])) {
            foreach ($lists['tags'] as $l) {
                $ret[$l['id']] = ['title' => $l['name']];
            }
        }
        return $ret;
    }

    function getReadme()
    {
        $redirect_uri = $this->getDi()->surl('admin-setup/' . self::GHL_ID);

        return <<<CUT
You need to create private Application on 
https://marketplace.gohighlevel.com/

Scopes: <strong>contacts.write locations/tags.readonly</strong>
Redirect URI: <strong>$redirect_uri</strong>
 
CUT;

    }
}

class Am_GohighlevelTags_Api extends Am_HttpRequest
{
    protected Am_Newsletter_Plugin_GohighlevelTags $plugin;
    protected $vars = []; // url params
    protected $params = []; // request params

    const API_URL = 'https://services.leadconnectorhq.com';

    public function __construct(Am_Newsletter_Plugin_GohighlevelTags $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
        $this->setHeader('Authorization: Bearer ' . $this->plugin->getAccessToken())
            ->setHeader('Version: 2021-04-15')
            ->setHeader('Accept: application/json');
    }

    public function sendRequest($method, $params = [], $httpMethod = 'POST')
    {
        $this->setMethod($httpMethod);

        $this->setUrl($url = self::API_URL . '/' . $method);
        if ($params) {
            $this->setHeader('Content-Type: application/json');
            $this->setBody(json_encode($params));
        } else {
            $this->setBody('');
        }

        $ret = parent::send();
        $this->plugin->debug($this, $ret, $url);

        if (!in_array($ret->getStatus(), [200, 201, 202, 204]))
        {
            $this->plugin->getDi()->logger->error("Gohighlevel API Error - [{body}]", [
                'body' => $ret->getBody(),
            ]);
            return [];
        }
        $body = $ret->getBody();
        if (!$body)
            return [];
        $arr = json_decode($body, true);
        if (!$arr || !empty($arr['errors'])) {
            $this->plugin->getDi()->logger->error("Gohighlevel API Error - [{errors}]",  [
                'errors' =>  $arr['errors'] ?? ''
            ]);
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