<?php

class Am_Newsletter_Plugin_Mailerlite extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addAdvRadio('api_type')
            ->setLabel('API Type')
            ->loadOptions([
                'api' => 'New MailerLite',
                'api-classic' => 'Classic MailerLite'
            ]);

        $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel(___("MailerLite API key\n"
                    . "API key can be obtained from Integrations page when you are logged into MailerLite application"));
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");

        $form->setDefault('api_type', 'api-classic');
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $list)
        {
            $this->getApi()->addToList($user, $list);
        }

        foreach ($deleteLists as $list)
        {
            $this->getApi()->deleteFromList($user, $list);
        }
        return true;
    }

    function changeEmail(User $user, $oldEmail, $newEmail)
    {
        $lists = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk());
        $campaigns = [];
        foreach($lists as $v){
            $list = $this->getDi()->newsletterListTable->load($v);
            $campaigns[] = $list->plugin_list_id;
        }

        $user->email = $oldEmail;
        try{
            $this->changeSubscription($user, [], $campaigns);
        }
        catch(Am_Exception_InternalError $e)
        {
            $this->getDi()->logger->error($e->getMessage());
        }
        $user->email = $newEmail;
        try{
            $this->changeSubscription($user, $campaigns, []);
        }
        catch(Am_Exception_InternalError $e)
        {
            $this->getDi()->logger->error($e->getMessage());
        }
    }

    function getLists()
    {
        return $this->getApi()->getLists();
    }

    function getApi()
    {
        return $this->getConfig('api_type', 'api-classic') == 'api-classic' ?
            new Am_Mailerlite_ApiClassic($this->getConfig('api_key'), $this) :
            new Am_Mailerlite_Api($this->getConfig('api_key'), $this);
    }

    public function onGetFlexibleActions(Am_Event $event)
    {
        try {
            $lists = $this->getDi()->cacheFunction->call([$this, 'getLists'], [], ['id' => __CLASS__ . __FUNCTION__], 60);
        } catch (Am_Exception $e) {
            return;
        }
        foreach ($lists as $list_id => $list) {
            $event->addReturn(new Am_FlexibleAction_MailerliteListAdd($list_id, $list['title'], $this));
            $event->addReturn(new Am_FlexibleAction_MailerliteListDelete($list_id, $list['title'], $this));
        }
    }
}

class Am_Mailerlite_Api extends Am_HttpRequest
{
    const ENDPOINT = 'https://connect.mailerlite.com/api';

    protected $api_key, $endpoint, $plugin;

    function __construct($key, Am_Newsletter_Plugin $plugin)
    {
        $this->api_key = $key;
        $this->endpoint = self::ENDPOINT;
        $this->plugin = $plugin;
        parent::__construct();
    }

    function getLists()
    {
        $ret = [];
        foreach ($this->get('groups', ['limit' => 250]) as $gr)
        {
            $ret[$gr['id']] = ['title' => $gr['name']];
        }
        return $ret;
    }

    function addToList(User $user, $list)
    {
        try {
            $data = $this->post('subscribers', $this->getUserInfoArray($user));
            $this->post(sprintf('subscribers/%s/groups/%s', $data['id'], $list));
        } catch (Exception $e) {}
    }

    function deleteFromList(User $user, $list)
    {
        try {
            $data = $this->get('subscribers/' . urlencode($user->email));
            $this->delete(sprintf('subscribers/%s/groups/%s', $data['id'], $list));
        } catch (Exception $e) {}
    }

    function getUserInfoArray(User $user)
    {
        return [
            'email' => $user->getEmail(),
            'fields' => [
                'name' => $user->name_f,
                'last_name' => $user->name_l,
                'country' => $user->country,
                'city' => $user->city,
                'state' => $user->state,
                'zip' => $user->zip,
                'phone' => $user->phone
            ],
        ];
    }

    function send()
    {
        $this->setHeader("Authorization: Bearer $this->api_key");
        $ret = parent::send();
        $this->plugin->debug($this, $ret);
        return $ret;
    }

    function getEndpoint($method, $params = [])
    {
        return $this->endpoint . '/' . $method . (!empty($params) ? '?' . http_build_query($params) : '');
    }

    function __call($name, $arguments)
    {
        if (!in_array($name, ['post', 'put', 'get', 'delete']))
            throw new Am_Exception_InternalError('MailerLite Request: unknown method ' . $name);

        $this->setMethod(strtoupper($name));

        if ($name == 'get') {
            $this->setUrl($this->getEndpoint($arguments[0]) . '?' . http_build_query($arguments[1] ?? []));
        } else  {
            $this->setUrl($this->getEndpoint($arguments[0]));
            if (!empty($arguments[1]))
            {
                $this->setHeader('Content-Type', 'application/json');
                $this->setHeader('Accept', 'application/json');
                $this->setBody(json_encode($arguments[1]));
            }
        }

        return $this->prepareResponse($this->send());
    }

    function prepareResponse(HTTP_Request2_Response $resp)
    {
        if (!in_array($resp->getStatus(), [200, 201, 204]))
            throw new Am_Exception_InternalError(sprintf("MailerLite Response Status: %s. Response Body: %s", $resp->getStatus(), $resp->getBody()));

        $ret = json_decode($resp->getBody(), true);
        if (@$ret['message'])
            throw new Am_Exception_InternalError(sprintf("MailerLite Error: %s", $ret['message']));
        return $ret['data'];
    }
}

class Am_Mailerlite_ApiClassic extends Am_HttpRequest
{
    const ENDPOINT = 'https://api.mailerlite.com/api/v2';

    protected $api_key, $endpoint, $plugin;

    function __construct($key, Am_Newsletter_Plugin $plugin)
    {
        $this->api_key = $key;
        $this->endpoint = self::ENDPOINT;
        $this->plugin = $plugin;
        parent::__construct();
    }

    function getLists()
    {
        $ret = [];
        foreach ($this->get('groups', ['limit' => 250]) as $gr)
        {
            $ret[$gr['id']] = ['title' => $gr['name']];
        }
        return $ret;
    }

    function addToList(User $user, $list)
    {
        $this->post(sprintf('groups/%s/subscribers', $list), $this->getUserInfoArray($user));
    }

    function deleteFromList(User $user, $list)
    {
        $this->delete(sprintf('groups/%s/subscribers/%s', $list, $user->email));
    }

    function getUserInfoArray(User $user)
    {
        return [
            'email' => $user->getEmail(),
            'fields' => [
                'name' => $user->name_f,
                'last_name' => $user->name_l,
                'country' => $user->country,
                'city' => $user->city,
                'state' => $user->state,
                'zip' => $user->zip,
                'phone' => $user->phone
            ],
            'resubscribe' => true,
            'autoresponders' => true,
        ];
    }

    function send()
    {
        $this->setHeader("X-MailerLite-ApiKey", $this->api_key);
        $ret = parent::send();
        $this->plugin->debug($this, $ret);
        return $ret;
    }

    function getEndpoint($method, $params = [])
    {
        return $this->endpoint . '/' . $method . (!empty($params) ? '?' . http_build_query($params) : '');
    }

    function __call($name, $arguments)
    {
        if (!in_array($name, ['post', 'put', 'get', 'delete']))
            throw new Am_Exception_InternalError('MailerLite Request: unknown method ' . $name);

        $this->setMethod(strtoupper($name));

        if ($name == 'get') {
            $this->setUrl($this->getEndpoint($arguments[0]) . '?' . http_build_query($arguments[1]));
        } else {
            $this->setUrl($this->getEndpoint($arguments[0]));
            if (!empty($arguments[1])) {
                $this->setHeader('Content-Type', 'application/json');
                $this->setBody(json_encode($arguments[1]));
            }
        }

        return $this->prepareResponse($this->send());
    }

    function prepareResponse(HTTP_Request2_Response $resp)
    {
        if (!in_array($resp->getStatus(), [200, 201, 204]))
            throw new Am_Exception_InternalError(sprintf("MailerLite Response Status: %s. Response Body: %s", $resp->getStatus(), $resp->getBody()));

        $ret = json_decode($resp->getBody(), true);
        if (@$ret['error'])
            throw new Am_Exception_InternalError(sprintf("MailerLite Error: %s - %s", $ret['error']['code'], $ret['error']['message']));
        return $ret;
    }
}

if (interface_exists('Am_FlexibleAction_Interface', true)):

    abstract class Am_FlexibleAction_Mailerlite implements Am_FlexibleAction_Interface
    {
        /**
         * @var Am_Newsletter_Plugin_Mailerlite $plugin
         */
        protected $plugin;
        protected $list_id , $title;

        function __construct($list_id, $title, Am_Newsletter_Plugin_Mailerlite $plugin)
        {
            $this->list_id = $list_id;
            $this->title = $title;
            $this->plugin = $plugin;
        }

        function commit()
        {
        }
    }

    class Am_FlexibleAction_MailerliteListAdd extends Am_FlexibleAction_Mailerlite
    {
        function run(User $user)
        {
            $this->plugin->getApi()->post(sprintf('groups/%s/subscribers', $this->list_id), $this->plugin->getUserInfoArray($user));
        }

        function getTitle()
        {
            return "MailerLite: Add to List: " . Am_Html::escape($this->title);
        }

        function getId() {
            return 'mailerlite-list-add-' . $this->list_id;
        }
    }

    class Am_FlexibleAction_MailerliteListDelete extends Am_FlexibleAction_Mailerlite
    {
        function run(User $user)
        {
            $this->plugin->getApi()->delete(sprintf('groups/%s/subscribers/%s', $this->list_id, $user->email));

        }

        function getTitle()
        {
            return "MailerLite: Delete from List: " . Am_Html::escape($this->title);
        }

        function getId()
        {
            return 'mailerlite-list-delete-' . $this->list_id;
        }
    }

endif;