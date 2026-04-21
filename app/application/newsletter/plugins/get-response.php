<?php

class Am_Newsletter_Plugin_GetResponse extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel("API Key\n" .
                'You can get your API Key <a target="_blank" rel="noreferrer" href="https://app.getresponse.com/manage_api.html">here</a>')
            ->addRule('required');
        $form->addSelect('api_version')->setLabel(___('API Version'))->loadOptions(['v2' => 'v2 (Deprecated)', 'v3' => 'v3']);

        $form->addAdvCheckbox('360', ['id' => 'get-response-360'])
            ->setLabel('I have GetResponse360 Account');
        $form->addText('api_url', ['class' => 'am-el-wide am-row-required', 'id' => 'get-response-360-url'])
            ->setLabel("API URL\n" .
                "contact your GetResponse360 account manager to get API URL");
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#get-response-360').change(function(){
        jQuery('#get-response-360-url').closest('.am-row').toggle(this.checked);
    }).change();
})
CUT
            );
        $form->addRule('callback', 'API URL is required for GetResponse360 account', function ($v)
        {
            return !($v["newsletter.{$this->getId()}.360"] && !$v["newsletter.{$this->getId()}.api_url"]);
        });
        $form->setDefault('api_version', 'v3');
    }

    function isConfigured()
    {
        return (bool)$this->getConfig('api_key');
    }

    function getApi()
    {
        return $this->getConfig('api_version',
            'v2') == 'v2' ? new Am_GetResponse_Api2($this) : new Am_GetResponse_Api3($this);

    }

    function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $lists = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $campaigns = [];
        foreach ($lists as $v) {
            $list = $this->getDi()->newsletterListTable->load($v);
            $campaigns[] = $list->plugin_list_id;
        }

        $user->email = $oldEmail;
        $this->changeSubscription($user, [], $campaigns);
        $user->email = $newEmail;
        $this->changeSubscription($user, $campaigns, []);
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $list_id) {
            $res = $this->getApi()->getContacts($list_id, $user->email);

            if (empty($res)) {
                $this->getApi()->addContact($list_id, $user);
            }

        }

        if (!empty($deleteLists)) {
            foreach ($deleteLists as $list_id) {
                $contacts = $this->getApi()->getContacts($list_id, $user->email);
                foreach ($contacts as $id => $contact) {
                    $this->getApi()->deleteContact($id);
                }

            }

        }

        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = [];
        $lists = $api->getLists();
        foreach ($lists as $id => $l) {
            $ret[$id] = [
                'title' => $l
            ];
        }
        return $ret;
    }
}

interface Am_GetResponse_Api_Interface
{
    /**
     * @return array('list_id' => 'name')
     */
    function getLists();

    function getContacts($list_id, $email);

    function addContact($list_id, User $user);

    function deleteContact($contact_id);
}

abstract class Am_GetResponse_Api_Abstract extends Am_HttpRequest implements Am_GetResponse_Api_Interface
{
    protected $api_key = null;
    protected $endpoint = null;
    /**
     * @var Am_Newsletter_Plugin_GetResponse $plugin
     */
    protected $plugin;

    abstract function getEndpoint();

    public function __construct(Am_Newsletter_Plugin_GetResponse $plugin)
    {
        $endpoint = $plugin->getConfig('360') ? $plugin->getConfig('api_url') : $this->getEndpoint();

        $this->api_key = $plugin->getConfig('api_key');
        $this->endpoint = $endpoint;
        $this->plugin = $plugin;
        parent::__construct($this->endpoint, self::METHOD_POST);
    }
}

class Am_GetResponse_Api2 extends Am_GetResponse_Api_Abstract
{
    protected $lastId = 1;

    function getEndpoint()
    {
        return 'http://api2.getresponse.com';
    }

    public function call($method, $params = null)
    {
        $this->setBody(json_encode($this->prepCall($method, $params)));
        $this->setHeader('Expect', '');
        $ret = parent::send();
        $this->plugin->debug($this, $ret);
        if ($ret->getStatus() != '200') {
            throw new Am_Exception_InternalError("GetResponse API Error, is configured API Key is wrong");
        }

        $arr = json_decode($ret->getBody(), true);
        if (!$arr) {
            throw new Am_Exception_InternalError("GetResponse API Error - unknown response [" . $ret->getBody() . "]");
        }

        if (isset($arr['error'])) {
            throw new Am_Exception_InternalError("GetResponse API Error - {$arr['error']['code']} : {$arr['error']['message']}");
        }

        return $arr['result'];
    }

    protected function prepCall($method, $params = null)
    {
        $p = [$this->api_key];
        if (!is_null($params)) {
            array_push($p, $params);
        }

        $call = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $p,
            'id' => $this->lastId++
        ];

        return $call;
    }

    function getLists()
    {
        $ret = [];
        $lists = $this->call('get_campaigns');
        foreach ($lists as $id => $list) {
            $ret[$id] = $list['name'];
        }
        return $ret;
    }

    function getContacts($list_id, $email)
    {
        $res = $this->call('get_contacts', [
            "campaigns" => [$list_id],
            'email' => [
                'EQUALS' => $email
            ]
        ]);
        return $res;
    }

    function addContact($list_id, User $user)
    {
        try {
            $ret = $this->call('add_contact', [
                'campaign' => $list_id,
                'name' => $user->getName() ?: $user->login,
                'email' => $user->email,
                'cycle_day' => 0,
                'ip' => filter_var($user->remote_addr, FILTER_VALIDATE_IP,
                    ['flags' => FILTER_FLAG_IPV4]) ? $user->remote_addr : (filter_var($_SERVER['REMOTE_ADDR'],
                    FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_IPV4]) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1')
            ]);
        } catch (Am_Exception_InternalError $e) {
            if (
                (strpos($e->getMessage(), 'Contact already added to target campaign') === false)
                &&
                (strpos($e->getMessage(), 'Contact already queued for target campaign') === false)
            ) {
                throw $e;
            }
        }
        return $ret;
    }

    function deleteContact($contact_id)
    {
        $this->call('delete_contact', [
            'contact' => $contact_id
        ]);
    }
}

class Am_GetResponse_Api3 extends Am_GetResponse_Api_Abstract
{
    function getEndpoint()
    {
        return 'https://api.getresponse.com/v3';
    }

    function call($method, $params = [], $httpMethod = Am_HttpRequest::METHOD_GET)
    {
        $this->setMethod($httpMethod);
        $this->setHeader('X-Auth-Token', "api-key " . $this->plugin->getConfig('api_key'));

        switch ($httpMethod) {
            case Am_HttpRequest::METHOD_POST :
                $this->setHeader('Content-type', 'application/json');
                $this->setBody(json_encode($params));
                $this->setUrl($this->endpoint . "/" . $method);
                break;
            case Am_HttpRequest::METHOD_GET :
                $this->setUrl($this->endpoint . "/" . $method . '?' . http_build_query($params));
                break;
            default:
                $this->setUrl($this->endpoint . "/" . $method);
        }

        $resp = parent::send();
        $this->plugin->debug($this, $resp);
        if (!in_array($resp->getStatus(), ['200', '204', '202'])) {
            throw new Am_Exception_InternalError("GetResponse API Error, status is not success");
        }

        $ret = @json_decode($resp->getBody(), true);

        if (is_null($ret) && ($resp->getStatus() != 202) && ($resp->getStatus() != 204)) {
            throw new Am_Exception_InternalError("GetResponse API Error - unknown response [" . $resp->getBody() . "]");
        }

        if (isset($ret['message'])) {
            throw new Am_Exception_InternalError("GetResponse API Error - {$ret['code']} : {$ret['message']}");
        }

        return $ret;
    }

    function getLists()
    {
        $lists = $this->call('campaigns');
        $ret = [];
        foreach ($lists as $list) {
            $ret[$list['campaignId']] = $list['name'];
        }
        return $ret;
    }

    function getContacts($list_id, $email)
    {
        $_ = $this->call('contacts', [
            'query' => [
                'campaignId' => $list_id,
                'email' => $email
            ]
        ]);
        $ret = [];
        foreach ($_ as $contact) {
            $ret[$contact['contactId']] = $contact;
        }
        return $ret;
    }

    function addContact($list_id, User $user)
    {
        $this->call('contacts', [
            'name' => $user->getName() ?: $user->login,
            'campaign' => [
                'campaignId' => $list_id
            ],
            'email' => $user->email,
            'dayOfCycle' => 0,
            'ipAddress' => filter_var($user->remote_addr, FILTER_VALIDATE_IP,
                ['flags' => FILTER_FLAG_IPV4]) ? $user->remote_addr : (filter_var($_SERVER['REMOTE_ADDR'],
                FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_IPV4]) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1')
        ], Am_HttpRequest::METHOD_POST);
    }

    function deleteContact($contact_id)
    {
        $this->call('contacts/' . $contact_id, [], Am_HttpRequest::METHOD_DELETE);
    }
}