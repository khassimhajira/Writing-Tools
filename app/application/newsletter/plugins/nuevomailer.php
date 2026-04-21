<?php

class Am_Newsletter_Plugin_Nuevomailer extends Am_Newsletter_Plugin
{
    protected $user_fields = [
        'name_f' => 'First Name',
        'name_l' => 'Last Name',
        'phone' => 'Phone',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'Zip',
        'country' => 'Country'
    ];

    public function _initSetupForm(Am_Form_Setup $form)
    {
        parent::_initSetupForm($form);
        $form->addText('install_url', ['class' => 'am-el-wide'])
            ->setLabel(___('NuevoMailer installation URL'));
        $form->addSecretText('api_key')
            ->setLabel(___('API Key'));
        $form->addAdvCheckbox('api_send_email')
            ->setLabel(___('Send opt-in/out emails'));
        $form->addAdvCheckbox('double_optin')
            ->setLabel(___('Enable double opt-in'));

        if ($fields = $this->getFields()) {
            $g = $form->addFieldset()
                ->setLabel("Fields Mapping");
            $op = ['' => '-- None'] + $this->user_fields;
            foreach($fields as $k => $v) {
                $g->addSelect("field_map_$k")
                    ->setLabel($v)
                    ->loadOptions($op);
            }
        }

        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
    }

    function url($slug)
    {
        return rtrim($this->getConfig('install_url'), '/') . "/api/v7/$slug";
    }

    function getCustomFields(User $user)
    {
        $res = [];

        $data = $user->toArray();
        $fields = $this->getFields();

        foreach ($fields as $Nuevomailer => $v) {
            $amember = $this->getConfig("field_map_$Nuevomailer");
            if (!empty($amember)) {
                $res[$Nuevomailer] = $data[$amember];
            }
        }

        return $res;
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        if (!empty($addLists)) {
            $req = new Am_HttpRequest($this->url('subscribers'), Am_HttpRequest::METHOD_POST);
            $req->setHeader([
                'X-Apikey' => $this->getConfig('api_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
            $req->setBody(json_encode([
                'api_action' => 'add',
                'api_send_email' => $this->getConfig('api_send_email') ? 'yes' : 'no',
                'email' => $user->email,
                'double_optin' => $this->getConfig('double_optin', 0),
                'lists' => implode(',', $addLists)
            ] + $this->getCustomFields($user)));
            $ret = $req->send();
            $this->debug($req, $ret, 'subscribers');
        }

        if (!empty($deleteLists)) {
            $req = new Am_HttpRequest($this->url('subscribers'), Am_HttpRequest::METHOD_POST);
            $req->setHeader([
                'X-Apikey' => $this->getConfig('api_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
            $req->setBody(json_encode([
                'api_action' => 'remove',
                'api_send_email' => $this->getConfig('api_send_email') ? 'yes' : 'no',
                'email' => $user->email,
                'opt_out_type' => 1,
                'lists' => implode(',', $addLists)
            ] + $this->getCustomFields($user)));
            $ret = $req->send();
            $this->debug($req, $ret, 'subscribers');
        }

        return true;
    }

    public function getLists()
    {
        $ret = [];
        $req = new Am_HttpRequest($this->url('lists'));
        $req->setHeader([
            'X-Apikey' => $this->getConfig('api_key'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        try {
            $resp = $req->send();
            $this->debug($req, $resp, 'lists');
            if ($resp->getStatus() != 200) {
                throw new Am_Exception_InternalError('Nuevomailer getLists api is not available!');
            }

            $lists = @json_decode($resp->getBody(), true);
            if (empty($lists)) {
                throw new Am_Exception_InternalError('Unable to fetch lists from noevomailer');
            }
        } catch (Exception $e) {
            $this->getDi()->logger->error("Could not fetch lists from nuevomailer", ["exception" => $e]);
            return $ret;
        }

        foreach ($lists as $r) {
            $ret[$r['idList']] = ['title' => $r['listName']];
        }
        return $ret;
    }

    function getFields()
    {
        $ret = [];
        $req = new Am_HttpRequest($this->url('fields'));
        $req->setHeader([
            'X-Apikey' => $this->getConfig('api_key'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        try {
            $resp = $req->send();
            $this->debug($req, $resp, 'fields');
            if ($resp->getStatus() != 200) {
                throw new Am_Exception_InternalError('Nuevomailer getFields api is not available!');
            }

            $fields = @json_decode($resp->getBody(), true);
            if (empty($fields)) {
                throw new Am_Exception_InternalError('Unable to fetch fields from noevomailer');
            }
        } catch (Exception $e) {
            //$this->getDi()->logger->error("Could not fetch fields from nuevomailer", ["exception" => $e]);
            return $ret;
        }

        foreach ($fields as $r) {
            $ret[$r['key']] = $r['label'];
        }
        return $ret;
    }
}