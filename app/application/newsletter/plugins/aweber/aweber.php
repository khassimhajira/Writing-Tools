<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Am_Newsletter_Plugin_Aweber extends Am_Newsletter_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const OAUTH_ENDPOINT = 'https://auth.aweber.com/oauth2';
    const CONFIG_CLIENT_ID = 'oauth-client-id';
    const CONFIG_REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob';
    const CONFIG_CLIENT_SECRET = 'oauth-client-secret';
    const STORE_ACCESS_TOKEN = 'aweber-oauth-access-token';
    const STORE_REFRESH_TOKEN = 'aweber-oauth-refresh-token';
    const STORE_ACCOUNT_ID = 'aweber-oauth-account-id';

    function  getAmemberOauthClientId()
    {
        return "OR4PojHOG0lK4z4zWbQnKxELjHNDFwNd";
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addHidden(self::CONFIG_CLIENT_ID);
        $form->addHidden(self::CONFIG_CLIENT_SECRET);

        if ($this->getAccessToken() === null) {

                $emptyToken = ___('Access token is empty!');
                $link = ___('%sClick here%s to getAuthorization Code (link will be opened in new window)',
                    sprintf("<a href='%s' target='_blank'>", $this->getDi()->surl('newsletter/aweber/authorize')),
                    '</a>'
                );
                $form->addHTML()->setHTML(<<<CUT
<div style="color: red; font-weight: bold">{$emptyToken}</div>
<div style="margin-top:1em">{$link}</div>
CUT
                );

                $form->addHtml()->setHtml(<<<CODE
<div>Paste Authorization Code below</div>
<div style="display:flex; width:100%"><span style="flex-grow: 2"><input type="text" class="am-el-wide" id="am-aweber-code"/></span><span><button id="am-aweber-redirect">Get Access Token</button></span>
</div>
<script>
    jQuery(document).ready(function(){
        jQuery("#am-aweber-redirect").on('click', function(e){
            e.preventDefault();
            document.location.href=amUrl('/newsletter/aweber/redirect?code='+jQuery("#am-aweber-code").val());
        })
    });
    jQuery(function(){
        jQuery('#save-0').closest('.am-row').remove();
    })
</script>
CODE
);
            } else {
                $tokenIsSet = ___('Access token is set. Use this %slink%s to delete it',
                    sprintf("<a href='%s'>", $this->getDi()->surl('newsletter/aweber/reset')),
                    '</a>'
                );

                $field = $form->addHTML()->setHTML(<<<CUT
<div style="color: green; font-weight: bold">{$tokenIsSet}</div>
CUT
                );
            $field->setLabel(___('Access Token'));

            $fields = $this->getDi()->userTable->getFields(true);
            unset ($fields['email']);
            unset ($fields['name_f']);
            unset ($fields['name_l']);
            $ff = $form->addMagicSelect('fields')->setLabel("Pass additional fields to AWeber\nfields must be configured in AWeber with exactly same titles\nelse API calls will fail and users will not be added\n\nBy default the plugin passes \"email\" and \"name\"\nfields to Aweber, so usually you do not need to select \nthat fields to send as additional fields.
");
            $ff->loadOptions(array_combine($fields, $fields));

            $form->addAdvCheckbox('debug')
                ->setLabel("Debug logging\nRecord debug information in the log");
        }

        $form->setDefault(self::CONFIG_CLIENT_ID, $this->getAmemberOauthClientId());
        $form->setDefault(self::CONFIG_CLIENT_SECRET, null);
    }

    function onGridProductInitForm(Am_Event_Grid $event)
    {
        $grid = $event->getGrid();
        $form = $grid->getForm();
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_FROM_FORM, function($values, $record){
            $record->data()->setBlob('aweber_tags', $values['_aweber_tags']);
        });
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, function(&$values, $record){
            $values['_aweber_tags'] = $record->data()->getBlob('aweber_tags') ?: '[]';
        });

        $listOptions = json_encode($this->getListOptions());

        $form->addHidden('_aweber_tags');

        $form->addHtml()
            ->setLabel('Aweber Tags
        tags users when user makes purchase of this product
        user will be tagged only if account  is suppose to be added
        to list according to aMember CP -> Protect Content -> Newsletters configuration'
            )->setHtml(<<<CUT
<div class="am-aweber-tags-c">
<div class="am-aweber-tags">

</div>
<a href="javascript:;" class="local add-aweber-tag" style="display:inline-block;margin-top:.4em">Add Tag</a>
</div>
<script>
jQuery(function() {
    var listOptions = $listOptions;
    
    function addTag(list=false, tag=false)
    {
        var list_el = jQuery('<select name="_aweber_list[]" class="am-combobox-fixed-compact"></select>');
        var tag_el = jQuery('<input type="text" name="_aweber_tag[]" placeholder="Tag" style="margin-left:.5em">');
        var item = jQuery('<div style="margin-top:.4em"></div>').
            append(list_el).
            append(tag_el).
            append(' <a href="javascript:;" class="del-aweber-tag" style="text-decoration:none;color:#ba2727">&Cross;</a>');
        jQuery('.am-aweber-tags').append(item);
        
        for (var k in listOptions) {
            list_el.append(
                jQuery('<option>').text(listOptions[k]).attr('value', k)
            );
            list && list_el.val(list);
        }
        
        tag && tag_el.val(tag);
        
        var p;
        jQuery("select.am-combobox-fixed-compact").select2(p = {
            minimumResultsForSearch : 10,
            width :  "180px",
        }).data('select2-option', p);
    }
    
    function sync()
    {
        var res = [];
        jQuery(".am-aweber-tags div").each(function(){
            if (jQuery('[name="_aweber_tag[]"]', this).val()) {
                res.push(jQuery('[name="_aweber_list[]"]', this).val() + '|||' + jQuery('[name="_aweber_tag[]"]', this).val());
            }
        });
        jQuery("[name=_aweber_tags]").val(JSON.stringify(res));
    }
    
     var val = JSON.parse(jQuery('[name=_aweber_tags]').val());
     for (var i in val) {
        [list, tag] = val[i].split('|||'); 
        addTag(list, tag);
     }
     
     jQuery('.am-aweber-tags-c').on('click', '.add-aweber-tag', function(){
        addTag();
        sync();
    });
    jQuery('.am-aweber-tags-c').on('click', '.del-aweber-tag', function(){
        jQuery(this).closest('div').remove();
        sync();
    });

    jQuery('.am-aweber-tags-c').on('change', 'select, input', function(){
        sync();
    });
});
</script>
CUT
);
    }

    function isConfigured(): bool
    {
        return true;
    }

    function getAccessToken(): ?string
    {
        $token = $this->getDi()->store->get(self::STORE_ACCESS_TOKEN);
        if (is_null($token) && $this->getRefreshToken()) {
            $provider = $this->getOAuth2Client();
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $this->getRefreshToken()
            ]);
            $this->saveAccessToken($token);
        }

        return $token;
    }

    function getRefreshToken(): ?string
    {
        return $this->getDi()->store->get(self::STORE_REFRESH_TOKEN);
    }

    function getAccountId(): ?string
    {
        $this->getAccessToken();
        return $this->getDi()->store->get(self::STORE_ACCOUNT_ID);
    }

    function getRedirectUrl(): string
    {
        return $this->getDi()->surl("newsletter/{$this->getId()}/redirect");
    }

    function getOAuth2Client(): GenericProvider
    {
        static $provider;

        if (empty($provider)) {
            $provider = new GenericProvider([
                'clientId' => $clientId = $this->getConfig(self::CONFIG_CLIENT_ID, $this->getAmemberOauthClientId()),
                'clientSecret' => $this->getConfig(self::CONFIG_CLIENT_SECRET, null),
                'redirectUri' => $clientId == $this->getAmemberOauthClientId() ? self::CONFIG_REDIRECT_URI : $this->getRedirectUrl(),
                'urlAuthorize' => self::OAUTH_ENDPOINT . '/authorize',
                'urlAccessToken' => self::OAUTH_ENDPOINT . '/token',
                'urlResourceOwnerDetails' => self::OAUTH_ENDPOINT,
                'scopeSeparator' => ' ',
                'scopes' => [
                    'list.read',
                    'subscriber.read',
                    'subscriber.write',
                    'account.read'
                ]
            ]);
        }

        return $provider;
    }

    function getEndpoint(...$parts): string
    {
        return "https://api.aweber.com/1.0/" . implode("/", $parts);
    }

    function sendRequest(RequestInterface $request): ResponseInterface
    {
        $client = new Client();
        return $client->send($request);
    }

    function saveAccessToken(AccessToken $token): void
    {
        $this->getDi()->store->set(self::STORE_ACCESS_TOKEN, $token->getToken(), sqlTime($token->getExpires()));
        $this->getDi()->store->set(self::STORE_REFRESH_TOKEN, $token->getRefreshToken());

        $provider = $this->getOAuth2Client();

        $request = $provider->getAuthenticatedRequest('GET', $this->getEndpoint('accounts'), $token->getToken());

        $response = $this->sendRequest($request);
        $content = $response->getBody()->getContents();

        $this->debug($request->getBody()->getContents(), $content, $request->getMethod() . " " . $request->getUri());

        if ($response->getStatusCode() != 200) {
            throw new Am_Exception_InternalError('Unable to get account info!');
        }

        $resp = @json_decode($content, true);

        if (empty($resp['entries'])) {
            throw new Am_Exception_InternalError('There is no accounts in response');
        }

        $this->getDi()->store->set(self::STORE_ACCOUNT_ID, @$resp['entries'][0]['id'], sqlTime($token->getExpires()));
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        $admin = $this->getDi()->authAdmin->getUser();
        if (!$admin && !$admin->isSuper()) {
            throw new Am_Exception_Security(___('Access denied'));
        }

        switch ($request->getActionName()) {
            case 'authorize' :
                $provider = $this->getOAuth2Client();
                $verifierBytes = random_bytes(64);
                $codeVerifier = rtrim(strtr(base64_encode($verifierBytes), "+/", "-_"), "=");
                $challengeBytes = hash("sha256", $codeVerifier, true);
                $codeChallenge = rtrim(strtr(base64_encode($challengeBytes), "+/", "-_"), "=");
                $url = $provider->getAuthorizationUrl([
                    'code_challenge' => $codeChallenge,
                    'code_challenge_method' => 'S256'
                ]);

                $this->getDi()->session->ns('aweber-oauth')->state = $provider->getState();
                $this->getDi()->session->ns('aweber-oauth')->code_verifier = $codeVerifier;
                $response->setRedirect($url);
                break;
            case 'redirect' :
                if ($request->getParam('error')) {
                    throw new Am_Exception_InputError(___("Got Error from Aweber API: [%s] : %s",
                        $request->getParam('error'), $request->getParam('error_description')));
                }

                if (!$request->getParam('code')) {
                    throw new Am_Exception_Security(___('Authorization code is empty'));
                }

                $code_verifier = $this->getDi()->session->ns('aweber-oauth')->code_verifier;
                $this->getDi()->session->ns('aweber-oauth')->code_verifier = null;

                $accessToken = $this->getOAuth2Client()->getAccessToken('authorization_code', [
                    'code' => $request->getParam('code'),
                    'code_verifier' => $code_verifier
                ]);

                $this->saveAccessToken($accessToken);

                $response->setRedirect($this->getDi()->surl('admin-setup/' . $this->getId()));
                break;

            case 'reset' :
                $this->getDi()->store->set(self::STORE_REFRESH_TOKEN, null);
                $this->getDi()->store->set(self::STORE_ACCESS_TOKEN, null);
                $this->getDi()->config
                    ->set('newsletter.aweber.'.self::CONFIG_CLIENT_ID, null)
                    ->set('newsletter.aweber.'.self::CONFIG_CLIENT_SECRET, null)
                    ->save();
                $response->setRedirect($this->getDi()->surl('admin-setup/' . $this->getId()));
                break;
            default:
                parent::directAction($request, $response, $invokeArgs);
        }
    }

    function sendApiRequest(string $method, string $uri, array $data = [])
    {
        $provider = $this->getOAuth2Client();
        $options = [
            'headers' =>
                [
                    'Content-type' => 'application/json'
                ]
        ];

        if (!empty($data)) {
            if ($method == 'DELETE') {
                $uri .= "?" . http_build_query($data);
            } else {
                $options['body'] = json_encode($data);
            }
        }

        $request = $provider->getAuthenticatedRequest($method, $uri, $this->getAccessToken(), $options);
        try {
            $response = $this->sendRequest($request);
            $content = $response->getBody()->getContents();
            $this->debug($request->getBody()->getContents(), $content, $request->getMethod() . " " . $request->getUri());
            return $content;
        }
        catch(Exception $e)
        {
            $this->getDi()->errorLogTable->logException($e);
            return '{}';
        }
    }

    public function getLists()
    {
        $ret = [];
        $start = 0;
        $size = 100;

        do {
            $response = $this->sendApiRequest('GET', $this->getEndpoint('accounts', $this->getAccountId(), 'lists') . "?ws.start=$start&ws.size=$size");
            $lists = json_decode($response);
            foreach ($lists->entries as $list) {
                $ret[$list->id] = ['title' => $list->name];
            }
            $start += $size;
        } while (isset($lists->next_collection_link));

        return $ret;
    }

    public function getTagOptions(int $list_id)
    {
        $response = $this->sendApiRequest('GET', $this->getEndpoint('accounts', $this->getAccountId(), 'lists', $list_id, 'tags'));
        $tags = json_decode($response);
        $ret = [];
        foreach($tags as $tag)
        {
            $ret[$tag] = $tag;
        }
        return $ret;
    }

    function getListTagOptions()
    {
        $options = [];

        //limit 120/minute
        $start = time();
        $n=0;
        foreach($this->getLists() as $list_id => $list)
        {
            if ($n == 100) {
                sleep(60 - (time() - $start));
                $start = time();
                $n = 0;
            }
            foreach($this->getTagOptions($list_id) as $tag_id => $tag_name)
            {
                $options["{$list_id}|||{$tag_id}"] = $list['title']."/".$tag_name;
            }
            $n++;
        }
        return $options;
    }

    function getTagsForProduct($product_id)
    {
        $product = $this->getDi()->productTable->load($product_id);
        $tags = $product->data()->getBlob('aweber_tags');
        return $tags ? json_decode($tags, true) : [];
    }

    function onSubscriptionChanged(Am_Event_SubscriptionChanged $event)
    {
        $user = $event->getUser();

        $tagsByList = [];
        foreach ($event->getAdded() as $product_id)
        {
            $tags = $this->getTagsForProduct($product_id);
            foreach($tags as $_)
            {
                [$list_id, $tag] = explode('|||', $_);
                $tagsByList[$list_id]['add'][] = $tag;
            }
        }
        foreach ($event->getDeleted() as $product_id)
        {
            $tags = $this->getTagsForProduct($product_id);
            foreach($tags as $_)
            {
                [$list_id, $tag] = explode('|||', $_);
                $tagsByList[$list_id]['remove'][] = $tag;
            }
        }
        if (!empty($tagsByList)) {
            foreach ($tagsByList as $list_id => $tags) {
                try {
                    $this->sendApiRequest(
                        'PATCH',
                        $this->getEndpoint('accounts', $this->getAccountId(), 'lists', $list_id, 'subscribers?'.http_build_query(['subscriber_email' => $user->email])),
                        $_ = ['tags' => $tags]
                    );
                } catch (ClientException $e) {
                    $this->getDi()->logger->error($e);
                }
            }
        }
    }

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $listId) {
            try {
                $custom_fields = [];
                foreach ($this->getConfig('fields', []) as $f) {
                    $custom_fields[$f] = (string)$user->get($f);
                    if (!strlen($custom_fields[$f])) {
                        $custom_fields[$f] = (string)$user->data()->get($f);
                    }
                }
                $info = [
                    'email' => $user->email,
                    'name' => $user->getName(),
                    'ip_address' => (string)filter_var($user->remote_addr, FILTER_VALIDATE_IP,
                        ['flags' => FILTER_FLAG_IPV4])
                ];
                if ($custom_fields) {
                    $info['custom_fields'] = $custom_fields;
                }

                $this->sendApiRequest(
                    'POST',
                    $this->getEndpoint('accounts', $this->getAccountId(), 'lists', $listId, 'subscribers'),
                    $info
                );
            } catch (ClientException $e) {
                $respBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                if (empty($respBody['error']['message']) || $respBody['error']['message'] != 'email: Subscriber already subscribed.') {
                    $this->getDi()->logger->error($e);
                    return false;
                }
            }
        }

        foreach ($deleteLists as $listId) {
            try {
                $this->sendApiRequest(
                    'DELETE',
                    $this->getEndpoint('accounts', $this->getAccountId(), 'lists', $listId, 'subscribers'),
                    ['subscriber_email' => $user->email]
                );
            } catch (ClientException $e) {
                $respBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                if (empty($respBody['error']['type']) || $respBody['error']['type'] != "NotFoundError") {
                    $this->getDi()->logger->error($e);
                    return false;
                }
            }
        }
        return true;
    }
}