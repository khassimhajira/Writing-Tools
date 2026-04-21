<?php

class Am_Newsletter_Plugin_Mailwizz extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addText('public_key', ['class' => 'am-el-wide'])->setLabel("Mailwizz Public Key");
        $el->addRule(   'regex',
                        'API Keys must be in form of 40 hex lowercase signs',
                        '/^[a-f0-9]{40}$/');
        $el->addRule('required');

        $el = $form->addSecretText('private_key', ['class' => 'am-el-wide'])->setLabel("Mailwizz Private Key");
        $el->addRule(   'regex',
                        'API Keys must be in form of 40 hex lowercase signs',
                        '/^[a-f0-9]{40}$/');
        $el->addRule('required');

        $el = $form->addText('url', ['class' => 'am-el-wide'])
            ->setLabel("Mailwizz API URL");
        $el->addRule('callback', 'The URL isn\'t valid', function($url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
                return false;
            }

            return true;
        });
        $el->addRule('required');
        $form->addAdvcheckbox('debug')->setLabel("Debug mode");
    }

    function isConfigured()
    {
        return $this->getConfig('public_key') && $this->getConfig('private_key') && $this->getConfig('url');
    }

    /**
     * Make authenticated API request to MailWizz
     * 
     * @param string $endpoint API endpoint path
     * @param array $data Request data
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @return array Response data
     * @throws Exception
     */
    protected function makeApiRequest($endpoint, $data = [], $method = 'GET')
    {
        $url = rtrim($this->getConfig('url'), '/') . $endpoint;
        $request = new Am_HttpRequest($url, $method);
        
        // Generate signature and set headers
        $this->signRequest($request, $data, $method);
        
        // Add request data
        if (!empty($data)) {
            if ($method === 'GET') {
                $query_string = http_build_query($data, '', '&');
                if (!empty($query_string)) {
                    $url .= '?' . $query_string;
                    $request->setUrl($url);
                }
            } else {
                $request->setBody(json_encode($data));
            }
        }
        
        $response = $request->send();
        $this->debug($request, $response, $url);
        
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new Exception('API request failed: ' . $response->getStatus() . ' ' . $response->getReasonPhrase());
        }
        
        $response_data = json_decode($response->getBody(), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from API');
        }
        
        return $response_data;
    }

    /**
     * Sign the request according to MailWizz API specification
     * 
     * @param Am_HttpRequest $request
     * @param array $data Request data
     * @param string $method HTTP method
     */
    protected function signRequest(Am_HttpRequest $request, $data = [], $method = 'GET')
    {
        $publicKey = $this->getConfig('public_key');
        $privateKey = $this->getConfig('private_key');
        $timestamp = time();
        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        
        // Set special header parameters
        $specialHeaderParams = [
            'X-MW-PUBLIC-KEY' => $publicKey,
            'X-MW-TIMESTAMP' => $timestamp,
            'X-MW-REMOTE-ADDR' => $remoteAddr,
        ];
        
        // Add headers to request
        foreach ($specialHeaderParams as $key => $value) {
            $request->setHeader($key, $value);
        }
        
        // Merge special headers with request data for signature
        $params = array_merge($specialHeaderParams, $data);
        
        // Sort parameters alphabetically
        ksort($params, SORT_STRING);
        
        // Build signature string: METHOD URL?PARAMS
        $requestUrl = $request->getUrl();
        $separator = strpos($requestUrl, '?') !== false ? '&' : '?';
        $signatureString = strtoupper($method) . ' ' . $requestUrl . $separator . http_build_query($params, '', '&');
        
        // Generate HMAC-SHA1 signature
        $signature = hash_hmac('sha1', $signatureString, $privateKey, false);
        
        // Add signature header
        $request->setHeader('X-MW-SIGNATURE', $signature);
        
        // Set content type
        $request->setHeader('Content-Type', 'application/json');
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        // Add subscribers to lists
        foreach ($addLists as $list_id) {
            try {
                $this->makeApiRequest("/lists/{$list_id}/subscribers", [
                    'EMAIL' => $user->email,
                    'FNAME' => $user->name_f,
                    'LNAME' => $user->name_l
                ], 'POST');
            } catch (Exception $e) {
                $this->getDi()->logger->error("Failed to add subscriber to list {$list_id}: " . $e->getMessage());
                return false;
            }
        }

        // Remove subscribers from lists
        foreach ($deleteLists as $list_id) {
            try {
                $this->makeApiRequest("/lists/{$list_id}/subscribers", [
                    'EMAIL' => $user->email
                ], 'DELETE');
            } catch (Exception $e) {
                $this->getDi()->logger->error("Failed to remove subscriber from list {$list_id}: " . $e->getMessage());
                return false;
            }
        }

        return true;
    }

    public function getLists()
    {
        $ret = [];

        try {
            $response = $this->makeApiRequest('/lists', [
                'page' => 1,
                'per_page' => 999999
            ], 'GET');

            if (!empty($response['data']['records'])) {
                foreach ($response['data']['records'] as $el) {
                    $ret[$el['general']['list_uid']] = [
                        'title' => $el['general']['display_name'],
                    ];
                }
            }
        } catch (Exception $e) {
            $this->getDi()->logger->error("Failed to get lists: " . $e->getMessage());
        }
        return $ret;
    }

}