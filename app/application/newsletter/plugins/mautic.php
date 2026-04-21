<?php

class Am_Newsletter_Plugin_Mautic extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('username')
            ->setLabel('Username')
            ->addRule('required');
        $form->addSecretText('pass')
            ->setLabel('Password')
            ->addRule('required');
        $form->addText('url', ['class' => 'am-el-wide'])
            ->setLabel('URL of Mautic Installation')
            ->addRule('required');

        $form->addTextarea('custom_fields', ['rows' => 5, 'class' => 'am-el-wide'])
            ->setLabel("Additional Fields\n" . "mautic_field|amember_field\n"
                . "eg:\n\n<strong>firstname|name_f</strong>\n\n"
                . "one link - one string");

        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\nRecord debug information in the log");
    }

    function isConfigured()
    {
        return $this->getConfig('username')
            && $this->getConfig('pass')
            && $this->getConfig('url');
    }

    function getLists()
    {
        $resp = $this->doRequest('segments?limit=300', "GET");
        $ret = [];
        foreach ($resp['lists'] as $l) {
            $ret[$l['id']] = ['title' => $l['name']];
        }
        return $ret;
    }

    public function changeEmail(User $user, $oldEmail, $newEmail)
    {
        if ($id = $user->data()->get('mautic_id')) {
            $req = new Am_HttpRequest($this->url("contacts/$id/edit"), 'PATCH');
            $req
                ->setAuth($this->getConfig('username'), $this->getConfig('pass'))
                ->setHeader('content-type', 'application/json')
                ->setBody(json_encode([
                    'firstname' => $user->name_f,
                    'lastname' => $user->name_l,
                    'email' => $newEmail
                ]));
            $resp = $req->send();
            $this->debug($req, $resp);
        }
    }

    function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        if (!$user->data()->get('mautic_id')) {
            $resp = $this->doRequest("contacts/new", "POST", [
                    'firstname' => $user->name_f,
                    'lastname' => $user->name_l,
                    'email' => $user->email,
                    'ipAddress' => $user->remote_addr //only Trackable IPs stored
                ] + $this->getCustomFields($user));
            $user->data()->set('mautic_id', $resp['contact']['id']);
            $user->save();
        }

        $contactId = $user->data()->get('mautic_id');

        foreach ($addLists as $listId) {
            $_ = $this->doRequest("segments/{$listId}/contact/{$contactId}/add", "POST");
            if (empty($_['success'])) {
                return false;
            }
        }

        foreach ($deleteLists as $listId) {
            $_ = $this->doRequest("segments/{$listId}/contact/{$contactId}/remove", "POST");
            if (empty($_['success'])) {
                return false;
            }
        }

        return true;
    }

    protected function getCustomFields(User $user)
    {
        $customFields = [];
        $cfg = $this->getConfig('custom_fields');
        if (!empty($cfg))
        {
            foreach (explode("\n", str_replace("\r", "", $cfg)) as $str)
            {
                if (!$str) continue;
                [$k, $v] = explode("|", $str);
                if (!$v) continue;

                if (($value = $user->get($v)) || ($value = $user->data()->get($v)))
                {
                    $customFields[$k] = $value;
                }
            }
        }
        return $customFields;
    }

    function doRequest($method, $verb = 'GET', $params = [])
    {
        $req = new Am_HttpRequest($this->url($method), $verb);
        $req->setAuth($this->getConfig('username'), $this->getConfig('pass'));

        if ($params) {
            $req->addPostParameter($params);
        }

        $resp = $req->send();
        $this->debug($req, $resp);
        if (!$body = $resp->getBody()) {
            return [];
        }

        return json_decode($body, true);
    }

    function url($method)
    {
        return trim($this->getConfig('url'), '/') . "/api/{$method}";
    }
}