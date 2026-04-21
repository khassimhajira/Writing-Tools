<?php

class Am_Mail_Transport_GmailOauth extends Am_Mail_Transport_Base
{
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const API_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
    const TOKEN = 'gmail-oauth-access-token';

    protected $token = false;
    protected $error = '';

    public function __construct($config)
    {
        if(!($this->token = Am_Di::getInstance()->store->getBlob(self::TOKEN))) {
            $req = new Am_HttpRequest(self::TOKEN_URL, Am_HttpRequest::METHOD_POST);
            $req->addPostParameter([
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type'    => 'refresh_token'
            ]);
            $res = $req->send();
            if(($res->getStatus() == 200) && ($data = json_decode($res->getBody(), true)) && isset($data['access_token'])) {
                Am_Di::getInstance()->store->setBlob(self::TOKEN, $data['access_token'], sqlTime("+ $data[expires_in] seconds"));
                $this->token = $data['access_token'];
            }
            else {
                $this->error = $res->getStatus() . ' - '  .$res->getBody();
            }
        }
    }

    protected function _sendMail()
    {
        if (!$this->token) {
            throw new Zend_Mail_Transport_Exception(
                "Gmail OAUTH API: cannot get access token response: {$this->error}"
            );
        }
        $request = new Am_HttpRequest(self::API_URL, Am_HttpRequest::METHOD_POST);
        $request->setHeader([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json'
        ]);
        $request->setBody(json_encode([
            'raw' => rtrim(strtr(base64_encode($this->header . Zend_Mime::LINEEND . $this->body), '+/', '-_'), '=')
        ]));
        $response = $request->send();
        if ($response->getStatus() != 200) {
            throw new Zend_Mail_Transport_Exception(
                "Gmail OAUTH API: unexpected response: {$response->getStatus()} {$response->getBody()}"
            );
        }
    }
}
