<?php

/**
 * @property string $status
 * @property int $user_id
 * @property int $otp_id
 */
class Am_Otp_Session
{
    const STATUS_NEW = 'new';
    const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    const STATUS_CONFIRMED = 'confirmed';

    const CODE_OK = 1;
    const CODE_EXPIRED = 2;
    const CODE_WRONG = 3;
    const CODE_NO_ATTEMPTS = 4;

    protected Otp $otp;
    protected string $sid;
    protected User $user;

    private function __construct(?string $sid = null)
    {
        $this->sid = empty($sid) ? self::generateSid() : $sid;

    }

    function generateSid()
    {

        return Am_Di::getInstance()->security->siteHash(uniqid("otp", true) . microtime(true));
    }

    static function start(User $user, string $redirect): self
    {
        $session = new Am_Otp_Session();
        $session
            ->setUser($user)
            ->setStatus(self::STATUS_NEW)
            ->setRedirect($redirect);

        $session->attempts = 3;

        $session->resendCodeAttempts = 3;


        return $session;
    }

    function setRedirect(string $url): self
    {
        $this->session()->redirect = $url;
        return $this;
    }

    static function resumeFromRequest(Am_Mvc_Request $request): Am_Otp_Session
    {
        $sid = $request->getQuery('osid');
        if (empty($sid)) {
            throw new Am_Exception_Security(___('Session id is empty in request'));
        }
        $session = Am_Otp_Session::resume($sid);

        if (!$session->verifyRequest($request)) {
            throw new Am_Exception_Security(___('Unable to verify request'));
        }
        return $session;
    }

    static function resume(string $sid): self
    {
        $session = new Am_Otp_Session($sid);
        return $session;
    }

    function verifyRequest(Am_Mvc_Request $request)
    {
        $params = $request->getQuery();

        if (empty($params['_s']) || empty($params['_n']))
            return false;
        $signature = $params['_s'];
        unset($params['_s']);
        ksort($params);
        if ($signature != $this->getDi()->security->siteHash(http_build_query($params)))
            return false;
        if (!$this->getDi()->nonce->verify($params['_n'], $this->getNonceKey()))
            return false;
        return true;
    }

    protected function getNonceKey()
    {
        $sesid = Am_Di::getInstance()->session->getId();
        return "{$this->status}:{$sesid}";
    }

    public function id()
    {
        return $this->sid;
    }

    function getRedirect(): string
    {
        return $this->session()->redirect;
    }

    protected function session(): Am_Session_Ns
    {
        return $this->getDi()->session->ns('OTP-' . $this->sid);
    }

    function getDi(): Am_Di
    {
        return Am_Di::getInstance();
    }

    function resendCode(Am_Otp_Adapter_Interface $adapter)
    {
        if ($this->resendCodeAttempts <= 0) {
            throw new Am_Exception_InputError(___('Resend Code attempts limit was reached'));
        }
        $this->getOtp()->delete();

        $this->sendCode($adapter);

        $this->resendCodeAttempts--;
    }

    function getOtp(): ?Otp
    {
        if (empty($this->otp)) {
            $this->otp = $this->getDi()->otpTable->findFirstBy(['sid' => $this->sid]);
        }
        return $this->otp;
    }

    function sendCode(Am_Otp_Adapter_Interface $adapter)
    {
        $this->setAdapter($adapter);

        $this->otp = $this->createCode();

        $this->getAdapter()->sendCode($this->otp);

        $this->setStatus(self::STATUS_AWAITING_CONFIRMATION);
        $this->session()->send_tm = $this->getDi()->time;
    }

    function setAdapter(Am_Otp_Adapter_Interface $adapter): self
    {
        $this->adapter = $adapter;
        return $this;
    }

    protected function createCode(): Otp
    {
        $this->otp = $this->getDi()->otpRecord;
        $this->otp
            ->set('sid', $this->sid)
            ->set('code', self::generateCode())
            ->set('user_id', $this->getUser()->user_id)
            ->set('created', $this->getDi()->sqlDateTime)
            ->set('expires', $this->getDi()->dateTime->modify(sprintf("+%d minutes", $this->getDi()->config->get("otp-lifetime", 30)))->format("Y-m-d H:i:s"))
            ->insert();
        return $this->otp;
    }

    function generateCode($len = 6, $acceptedChars = '0123456789')
    {
        $code = Am_Di::getInstance()->security->randomString($len, $acceptedChars);
        return $code;
    }

    function getUser()
    {
        if (empty($this->user)) {
            $this->user = $this->getDi()->userTable->load($this->session()->user_id);
        }
        return $this->user;
    }

    function setUser(User $user): self
    {
        $this->user_id = $user->pk();
        $this->user = $user;
        return $this;

    }

    function getAdapter(): Am_Otp_Adapter_Interface
    {
        return $this->adapter;
    }

    function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    function getCodeSendTm()
    {
        return $this->session()->send_tm;
    }

    function getResendCodeAttemptsCount()
    {
        return $this->resendCodeAttempts;
    }

    function validateCode(string $code): int
    {
        $otp = $this->getOtp();

        if ($otp->isExpired()) {
            return self::CODE_EXPIRED;
        }


        if ($otp->code != $code) {
            $this->attempts--;
            if ($this->attempts) {
                return self::CODE_WRONG;
            } else {
                return self::CODE_NO_ATTEMPTS;
            }
        }
        return self::CODE_OK;

    }

    function __get($key)
    {
        return $this->session()->{$key};
    }

    function __set($key, $value)
    {
        $this->session()->{$key} = $value;
    }

    function destroy()
    {
        $this->session()->unsetAll();
        $otp = $this->getOtp();
        if (!empty($otp)) {
            $otp->delete();
        }
    }

    function getSendCodeUrl(?string $adapterType = null)
    {
        return $this->getSignedUrl($this->getDi()->surl('otp/send-code', ['type' => $adapterType]));
    }

    function getSignedUrl($url)
    {
        $parts = parse_url($url);
        $parts['query'] = $parts['query'] ?? "";
        parse_str($parts['query'], $query);

        unset($query['_s']);
        $query = $this->getSignedParams($query);

        return (@$parts['scheme'] ? $parts['scheme'] . "://" : "") . (@$parts['host'] ?: "") . (@$parts['port'] ? ":" . $parts['port'] : "") . $parts['path'] . "?" . http_build_query($query);
    }

    function getSignedParams(array $query): array
    {
        $query['osid'] = $this->sid;
        $query['_n'] = $this->getDi()->nonce->create($this->getNonceKey());

        $_ = $query;
        ksort($_);
        $query['_s'] = $this->getDi()->security->siteHash(http_build_query($_));
        return $query;
    }

    /**
     * Status helpers
     */
    function isNew(): bool
    {
        return $this->getStatus() == self::STATUS_NEW;
    }

    function getStatus(): string
    {
        return $this->status;
    }

    function isAwaiting(): bool
    {
        return $this->getStatus() == self::STATUS_AWAITING_CONFIRMATION;
    }

    function isConfirmed(): bool
    {
        return $this->getStatus() == self::STATUS_CONFIRMED;
    }
}