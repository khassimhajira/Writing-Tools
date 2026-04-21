<?php

class Am_Newsletter_Plugin_Sendgrid3 extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addSecretText('api_key', ['class' => 'am-el-wide'])
            ->setLabel('SendGrid API Key' .
            "\n You can manage your API keys at Sendgrid -> Settings -> API keys");
        $el->addRule('required');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
    }

    function isConfigured()
    {
        return $this->getConfig('api_key');
    }

    function getAPI()
    {
        return new Am_Sendgrid_Api3($this);
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = [];
        $lists = $api->get('marketing/lists');
        foreach ($lists['result'] as $l)
            $ret[$l['id']] = ['title' => $l['name']];
        return $ret;
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        if (!empty($addLists))
            $api->put(sprintf("marketing/contacts"),
                [
                    'list_ids' => $addLists,
                    'contacts' => [
                        [
                            'email' => $user->email,
                            'first_name' => $user->name_f,
                            'last_name' => $user->name_l,
                            'city' => $user->city,
                            'country' => $user->country,
                            'postal_code' => $user->zip,
                            'state_province_region' => $user->state,
                            'address_line_1' => $user->street,
                            'address_line_2' => $user->street2,
                        ]
                    ]
                ]);
        if (!empty($deleteLists)) {
            $contacts = $api->post('marketing/contacts/search',
                [
                    'query' => "email = '{$user->email}'"
                ]);
            if ($contact = @$contacts['result'][0]) {
                foreach ($deleteLists as $list_id)
                    $api->delete(sprintf("/marketing/lists/%s/contacts?contact_ids=%s", $list_id, $contact['id']));
            }
        }
        return true;
    }

    function getReadme()
    {
        return <<<CUT
Subscription for "<strong>Marketing Campaigns</strong>" should be active in your Sendgrid account to use this plugin
CUT;

    }
}

class Am_Sendgrid_Api3 extends Am_HttpRequest
{
    /** @var Am_Newsletter_Plugin_Sendgrid */
    protected $plugin;
    protected $vars = []; // url params
    protected $params = []; // request params\

    const API_URL = 'https://api.sendgrid.com/v3';

    public function __construct(Am_Newsletter_Plugin_Sendgrid3 $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
        $this->setHeader('Authorization: Bearer ' . $this->plugin->getConfig('api_key'));
    }

    public function sendRequest($method, $params = [], $httpMethod = 'POST')
    {
        $this->setHeader('Content-type', null);
        $this->setMethod($httpMethod);

        $this->setUrl($url = self::API_URL . '/' . $method);
        if ($params) {
            $this->setHeader('Content-Type: application/json');
            $this->setBody(json_encode($params));
        } else {
            $this->setBody('');
        }

        $ret = parent::send();
        $this->plugin->debug($this, $ret);

        if (!in_array($ret->getStatus(), [200, 201, 202, 204])) {
            throw new Am_Exception_InternalError("SendGrid  API v3 Error:" . $ret->getBody());
        }
        $body = $ret->getBody();
        if (!$body)
            return [];

        $arr = json_decode($body, true);
        if (!$arr)
            throw new Am_Exception_InternalError("SendGrid API v3  Error - unknown response [" . $ret->getBody() . "]");
        if (@$arr['errors']) {
            Am_Di::getInstance()->logger->error("Sendgrid  API v3 Error - [" . implode(', ', $arr['errors']) . "]");
            return false;
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