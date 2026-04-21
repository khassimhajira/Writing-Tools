<?php
/**
 * @table integration
 * @id maropost
 * @title Maropost
 * @visible_link http://www.maropost.com/
 * @description Maropost has been built from the ground up by experienced marketers
 * for marketers -- taking lessons learned from decades in marketing and applying
 * them so you can rise above the noise and stand out in the crowd. Our intuitive
 * platform flattens out the learning curve while providing powerful email
 * marketing automation tools and analytics to reduce effort and increase ROI.
 * All of this is backed by a commitment to provide stellar service and support.
 * Which is why leading brands such as Volvo, Time, Prada and the Discovery
 * Channel turn to Maropost to increase customer engagement,
 * leads and conversions
 * @different_groups 0
 * @single_login 0
 * @type Email Systems/AutoResponders
 * @docs https://api.maropost.com/api/
 */
class Am_Newsletter_Plugin_Maropost extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('account_id', ['size' => 10])
                ->setLabel('MaroPost Account ID')
                ->addRule('required')
                ->addRule('regex', ___('Digits only please'), '/^[0-9]+$/');
        $form->addSecretText('auth_token', ['size' => 20])
                ->setLabel('MaroPost Auth Token')
                ->addRule('required');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
    }

    function  isConfigured()
    {
        $account_id = (int)$this->getConfig('account_id');
        $auth_token = $this->getConfig('auth_token');
        return ($account_id > 0 && !empty($auth_token));
    }

    /** @return Am_Plugin_MaroPost */
    function getApi()
    {
        return new Am_MaroPost_Api($this->getConfig('account_id'), $this->getConfig('auth_token'), $this);
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();

        foreach ($addLists as $list_id) {
            $api->call('lists/' . $list_id . '/contacts',
                    [
                        'first_name'    => $user->name_f,
                        'last_name'     => $user->name_l,
                        'email'         => $user->email,
                        'subscribe'     => true
                    ],
                    $api::METHOD_POST);
        }

        foreach ($deleteLists as $list_id) {
            $res = $api->call('lists/' . $list_id . '/contacts');
            foreach ($res as $contact) {
                if ($contact['email'] == $user->email)
                    $api->call('lists/' . $list_id . '/contacts/' . $contact['id'],
                        [],
                        $api::METHOD_DELETE);
            }
        }

        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = [];
        $page = 1;
        do {
            $lists = $api->call('lists', [], 'GET', $page);
            foreach ($lists as $l) {
                $id = $l['id'];
                $ret[$id] = [
                    'title' => $l['name'],
                ];
            }
            $page++;
        } while (count($lists) > 0);
        return $ret;
    }
}

class Am_MaroPost_Api extends Am_HttpRequest
{
    const FORMAT_XML      = 'xml';
    const FORMAT_JSON     = 'json';
    protected $account_id = null;
    protected $auth_token = null;
    protected $endpoint = 'https://app.maropost.com/accounts';
    protected $plugin;

    public function __construct($account_id, $auth_token, $plugin)
    {
        $this->account_id = $account_id;
        $this->auth_token = $auth_token;
        $this->plugin = $plugin;
        parent::__construct();
    }

    public function call($path, $params = [], $method = self::METHOD_GET, $page = '')
    {
        if (!$this->account_id || empty($this->auth_token))
           return [];
        $this->setMethod($method);
        $url = $this->endpoint . '/' . $this->account_id . '/' . $path . '.' . self::FORMAT_JSON . '?' . http_build_query(['auth_token' => $this->auth_token]);
        if ($page > 1)
            $url .= '&page=' . $page;
        $this->setUrl($url);

        $this->setBody(json_encode($params));
        $this->setHeader('Content-Type', 'application/json');
        $this->setHeader('Accept', 'application/json');

        $ret = parent::send();
        $this->plugin->debug($this, $ret);

        if ($ret->getStatus() != '200' && $ret->getStatus() != '201')
        {
            throw new Am_Exception_InternalError("MaroPost API Error, please check configuration. Status code: [".$ret->getStatus()."]");
        }
        $arr = [];
        if ($ret)
            $arr = json_decode($ret->getBody(), true);

//        if (!$arr && $method != self::METHOD_DELETE)
//            throw new Am_Exception_InternalError("MaroPost API Error - unknown response [" . $ret->getBody() . "]");

        return $arr;
    }
}