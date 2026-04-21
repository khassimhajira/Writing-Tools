<?php

use GuzzleHttp\Client;

/**
 * @am_plugin_api 6.0
*/
class Am_Plugin_Facebook extends Am_Plugin
{
    use Am_Mvc_Controller_User_Create;

    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '6.3.39';
    const FACEBOOK_UID = 'facebook-uid';
    const FACEBOOK_LOGOUT = 'facebook-logout';
    const NOT_LOGGED_IN = 0;
    const LOGGED_IN = 1;
    const LOGGED_AND_LINKED = 2;
    const LOGGED_OUT = 3;
    const FB_APP_ID = 'app_id';
    const FB_APP_SECRET = 'app_secret';

    protected $status = null; //self::NOT_LOGGED_IN;
    /** @var User */
    protected $linkedUser;

    /** @var GraphUser */
    protected $fbProfile = null;
    private $_api_loaded = false;
    private $_api_error = null;
    private $sdkIncluded = false;

    /**
     * @return FacebookSession;
     */
    protected $session = null;

    /**
     * @var Facebook
     */
    protected $api;

    public function isConfigured()
    {
        return $this->getConfig(self::FB_APP_ID) && $this->getConfig(self::FB_APP_SECRET);
    }

    function onSetupForms(Am_Event_SetupForms $event)
    {
        $form = new Am_Form_Setup('facebook');
        $form->setTitle('Facebook');

        $fs = $form->addFieldset()->setLabel(___('FaceBook Application'));
        $fs->addText(self::FB_APP_ID)->setLabel(___('FaceBook App ID'));
        $fs->addText(self::FB_APP_SECRET, ['size' => 40])->setLabel(___('Facebook App Secret'));

        $fs = $form->addFieldset()->setLabel(___('Features'));
        $size = ['icon', 'small', 'medium', 'large', 'xlarge'];
        $fs->addSelect('size')
            ->setLabel(___('Login Button Size'))
            ->loadOptions(array_combine($size, $size));

        $fs->addSelect("login_postion")
            ->setLabel("Login Button Position")
            ->loadOptions([
                'login/form/after' => ___("Below Login Form"),
                'login/form/before' => ___("Above Login Form")
            ]);

        $fs->addAdvCheckbox('no_signup')
            ->setLabel(___('Do not add to Signup Form'));
        $fs->addAdvCheckbox('no_login')
            ->setLabel(___('Do not add to Login Form'));

        $gr = $fs->addGroup()
            ->setLabel(___('Add "Like" button'));
        $gr->addAdvCheckbox('like', ['id' => 'like-settings']);
        $gr->addStatic()->setContent(' <span>' . ___('Like Url') . '</span> ');
        $gr->addText('likeurl', ['size' => 40]);

        $layout = ['standard', 'button_count', 'button', 'box_count'];
        $fs->addSelect('layout', ['rel' => 'like-settings'])
            ->setLabel(___('Like Button Layout'))
            ->loadOptions(array_combine($layout, $layout));
        $action = ['like', 'recommend'];
        $fs->addSelect('action', ['rel' => 'like-settings'])
            ->setLabel(___('Like Button Action'))
            ->loadOptions(array_combine($action, $action));

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    jQuery('#like-settings').change(function(){
        jQuery('#like-settings').nextAll().toggle(this.checked);
        jQuery('[rel=like-settings]').closest('.am-row').toggle(this.checked);
    }).change();
})
CUT
            );

        $form->setDefault('likeurl', ROOT_URL);

        $form->addMagicSelect('add_access', ['class' => 'am-combobox-fixed'])
            ->setLabel("Add free access to a product\n" .
                "if user signup from Facebook")
            ->loadOptions($this->getDi()->productTable->getOptions());
        $form->addFieldsPrefix('misc.facebook.');
        $this->_afterInitSetupForm($form);
        $event->addForm($form);
    }

    function onInitBlocks(Am_Event $event)
    {
        $blocks = $event->getBlocks();
        if (!$this->getConfig('no_login'))
            $blocks->add(
                $this->getConfig('login_postion', 'login/form/after'), new Am_Block_Base(null, 'fb-login', $this, 'fb-login.phtml'));
        if (!$this->getConfig('no_signup'))
            $blocks->add(
                'signup/form/before',new Am_Block_Base(null, 'fb-signup', $this, 'fb-signup.phtml'));
        if ($this->getConfig('like'))
            $blocks->add(
                'member/main/right/bottom', new Am_Block_Base(null, 'fb-like', $this, 'fb-like.phtml'));
    }

    function includeJSSDK()
    {
        $locale = $this->getDi()->locale->getId();
        echo <<<CUT
<div id="fb-root"></div>
<script>
jQuery(document).ready(function($) {
  jQuery.ajaxSetup({ cache: true });
  jQuery.getScript('//connect.facebook.net/$locale/sdk.js', function(){
    FB.init({
      appId: '{$this->getConfig(self::FB_APP_ID)}',
      version: 'v2.3',
      status: true,
      cookie: true,
      xfbml: true,
      oauth: true
    });
    jQuery('#loginbutton,#feedbutton').removeAttr('disabled');
  });
});
</script>
CUT;
    }

    function includeLoginJS()
    {
        echo <<<OUT
<script type="text/javascript">
function facebook_login_login()
{
    var loginRedirect = function(){
        var href = window.location.href;
        if (href.indexOf('?') < 0)
            href += '?fb_login=1';
        else
            href += '&fb_login=1';
        window.location.href=href;
    }

    FB.getLoginStatus(function(response) {

        if(response.status == 'connected')
            loginRedirect();
        else
            FB.login(function(response) {
                if (response.status=='connected')  loginRedirect();
                }, {scope: 'email'});

    });
}
</script>
OUT;
    }


    /**
     * Create account in aMember for user who is logged in facebook.
     */
    function createAccount()
    {
        if (!$this->getFbProfile('email')) {
            throw new Am_Exception_InputError('It is not possible to use Facebook account without email to login, please follow standard signup flow.');
        }

        /* Search for account by email address */
        $user = $this->getDi()->userTable->findFirstByEmail($this->getFbProfile('email'));
        if (empty($user)) {
            $email = $this->getFbProfile('email');
            // check for ban
            if($ban = Am_Di::getInstance()->banTable->checkBan(['email' => $email]))
                throw new Am_Exception_InputError($ban);
            // Create account for user;

            $user = $this->createUser([
                'email' => $email,
                'name_f' => $this->getFbProfile('first_name') ?: '',
                'name_l' => $this->getFbProfile('last_name') ?: '',
            ]);
        }

        if (!$user->data()->get(self::FACEBOOK_UID) && ($pids = $this->getConfig('add_access')))
        {
            foreach ($pids as $pid) {
                if ($product = $this->getDi()->productTable->load($pid, false)) {
                    $billingPlan = $product->getBillingPlan();

                    $access = $this->getDi()->accessRecord;
                    $access->product_id = $pid;
                    $access->begin_date = $this->getDi()->sqlDate;

                    $period = new Am_Period($billingPlan->first_period);
                    $access->expire_date = $period->addTo($access->begin_date);

                    $access->user_id = $user->pk();
                    $access->comment = 'from Facebook login';
                    $access->insert();
                }
            }
        }

        $user->data()->set(self::FACEBOOK_UID, $this->getFbProfile('id'))->update();

        return $user;
    }

    function onAuthCheckLoggedIn(Am_Event_AuthCheckLoggedIn $event)
    {
        $status = $this->getStatus();
        if ($status == self::LOGGED_AND_LINKED) {
            $event->setSuccessAndStop($this->linkedUser);
        } elseif ($status == self::LOGGED_OUT && !empty($_GET['fb_login'])) {
            $this->linkedUser->data()->set(self::FACEBOOK_LOGOUT, null)->update();
            $event->setSuccessAndStop($this->linkedUser);
        } elseif ($status == self::LOGGED_IN && $this->getDi()->request->get('fb_login')) {
            $this->linkedUser = $this->createAccount();
            $event->setSuccessAndStop($this->linkedUser);
        }
    }

    function onAuthAfterLogout(Am_Event_AuthAfterLogout $event)
    {
        $domain = $this->getDi()->request->getHttpHost();
        Am_Cookie::set('fbsr_' . $this->getConfig('app_id'), null, time() - 3600 * 24, "/");
        Am_Cookie::set('fbm_' . $this->getConfig('app_id'), null, time() - 3600 * 24, "/");
        Am_Cookie::set('fbsr_' . $this->getConfig('app_id'), null, time() - 3600 * 24, "/", $domain, false);
        Am_Cookie::set('fbm_' . $this->getConfig('app_id'), null, time() - 3600 * 24, "/", $domain, false);
        $event->getUser()->data()->set(self::FACEBOOK_LOGOUT, true)->update();
    }

    function onAuthAfterLogin(Am_Event_AuthAfterLogin $event)
    {
        if (($this->getStatus() == self::LOGGED_IN) && $this->getFbUid()) {
            $event->getUser()->data()->set(self::FACEBOOK_UID, $this->getFbUid())->update();
        }
    }

    function getStatus()
    {
        if ($this->status !== null)
            return $this->status;

        $this->linkedUser = null;

        if ($id = $this->getFbUid()) {
            $user = $this->getDi()->userTable->findFirstByData(self::FACEBOOK_UID, $id);
            if ($user) {
                $this->linkedUser = $user;
                if ($user->data()->get(self::FACEBOOK_LOGOUT)) {
                    $this->status = self::LOGGED_OUT;
                } else {
                    $this->status = self::LOGGED_AND_LINKED;
                }
            } else {
                $this->status = self::LOGGED_IN;
            }
        } else {
            $this->status = self::NOT_LOGGED_IN;
        }
        return $this->status;
    }

    /** @return User */
    function getLinkedUser()
    {
        return $this->linkedUser;
    }

    /** @return int FbUid */
    function getFbUid()
    {
        $helper = new FacebookJavaScriptHelperGuzzle($this->getConfig(self::FB_APP_ID), $this->getConfig(self::FB_APP_SECRET));
        if($token = $helper->getAccessTokenFromCookie()) {
            $fbUser = $this->getFacebookUserProfile($token);
            if(isset($fbUser['id'])) {
                $this->fbProfile = $fbUser;
                return $fbUser['id'];
            }
        }
    }

    function getFacebookUserProfile(string $accessToken): ?array
    {
        $client = new Client();

        try {
            $response = $client->request('GET', 'https://graph.facebook.com/v19.0/me', [
                'query' => [
                    'fields' => 'id,name,email',
                    'access_token' => $accessToken
                ]
            ]);

            $userData = json_decode($response->getBody(), true);
            return $userData;

        } catch (\GuzzleHttp\Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
            return null;
        }
    }

    /** @return facebook info */
    function getFbProfile($fieldName)
    {
        if(is_null($this->fbProfile)) {
            $this->getFbUid();
        }
        return $this->fbProfile[$fieldName] ?? null;
    }
}


class FacebookJavaScriptHelperGuzzle
{
    private $appId;
    private $appSecret;
    private $httpClient;

    public function __construct(string $appId, string $appSecret)
    {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->httpClient = new Client();
    }

    public function getAccessTokenFromCookie(): ?string
    {
        $cookieName = "fbsr_{$this->appId}";
        if (!isset($_COOKIE[$cookieName])) {
            return null;
        }
        $signedRequest = $_COOKIE[$cookieName];
        $data = $this->parseSignedRequest($signedRequest);

        if (!$data || !isset($data['code'])) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v19.0/oauth/access_token', [
                'query' => [
                    'client_id' => $this->appId,
                    'redirect_uri' => '',
                    'client_secret' => $this->appSecret,
                    'code' => $data['code']
                ]
            ]);
        }
        catch (GuzzleHttp\Exception\ClientException $e) {
            Am_Di::getInstance()->errorLogTable->logException($e);
            return null;
        }

        $responseData = json_decode($response->getBody(), true);
        return $responseData['access_token'] ?? null;
    }

    private function parseSignedRequest(string $signedRequest): ?array
    {
        [$encodedSig, $payload] = explode('.', $signedRequest, 2);

        $sig = $this->base64UrlDecode($encodedSig);
        $data = json_decode($this->base64UrlDecode($payload), true);

        if (strtoupper($data['algorithm']) !== 'HMAC-SHA256') {
            return null;
        }

        $expectedSig = hash_hmac('sha256', $payload, $this->appSecret, true);

        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        return $data;
    }

    private function base64UrlDecode(string $input): string
    {
        $replaced = strtr($input, '-_', '+/');
        return base64_decode($replaced . str_repeat('=', 3 - ((3 + strlen($input)) % 4)));
    }
}
