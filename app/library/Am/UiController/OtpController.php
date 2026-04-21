<?php

class OtpController extends Am_Mvc_Controller
{
    function sendCodeAction()
    {
        try {
            $session = Am_Otp_Session::resumeFromRequest($this->_request);

            if (!$session->isNew()) {
                throw new Am_Exception_InternalError(___('Code was already sent'));
            }

            $adapter = $session->getUser()->getOtpAdapter($this->getRequest()->getParam('type') ?? null);

            if (!$adapter) {
                throw new Am_Exception_InputError(___('Unable to send verification code. Transport is not configured'));
            }

            $session->sendCode($adapter);

            return $this->getResponse()->setRedirect($session->getSignedUrl($this->getDi()->surl('otp/verify', ['type' => $this->getParam('type')])));

        } catch (Exception $ex) {
            if ($this->_request->isXmlHttpRequest()) {
                return $this->_response->setBody(json_encode([
                    'ok' => false,
                    'error' => $ex->getMessage()
                ]));
            }

            throw $ex;
        }
    }

    function resendAction()
    {
        try {
            $session = Am_Otp_Session::resumeFromRequest($this->_request);

            if (!$session->isAwaiting()) {
                throw new Am_Exception_Security(___("You can't resend code"));
            }

            $tm = $this->getDi()->time - $session->getCodeSendTm();
            if ($tm < $this->getDi()->config->get('oto-regenerate-delay', 60)) {
                throw new Am_Exception_Security(___("Please wait %s seconds",
                    $this->getDi()->config->get('oto-regenerate-delay', 60) - $tm));
            }

            $adapter = $session->getUser()->getOtpAdapter($this->getRequest()->getParam('type') ?? null);

            if (!$adapter) {
                throw new Am_Exception_InputError(___('Unable to send verification code. Transport is not configured'));
            }

            $session->resendCode($adapter);

            return $this->getResponse()->setRedirect($session->getSignedUrl($this->getDi()->surl('otp/verify', ['type' => $this->getParam('type')])));

        } catch (Exception $ex) {
            if ($this->_request->isXmlHttpRequest()) {
                return $this->_response->setBody(json_encode([
                    'ok' => false,
                    'error' => $ex->getMessage()
                ]));
            }

            throw $ex;
        }
    }

    function verifyAction()
    {
        $session = Am_Otp_Session::resumeFromRequest($this->_request);

        if (!$session->isAwaiting()) {
            throw new Am_Exception_InternalError(___('Wrong session status'));
        }

        $form = new Am_Form();
        $form->setAction($session->getSignedUrl($this->getDi()->surl('otp/verify', ['type'=>$this->getParam('type')])));
        $_1 = ___('One Time Password  has been sent to your %s: %s', $session->getAdapter()->getId(),
            "<b>" . $session->getAdapter()->getMaskedAddress() . "</b>");
        $_2 = ___('Enter it  in the form below in order to continue');
        $form->addHtml()->setHtml(<<<CUT
    <div>{$_1}</div>
    <div>{$_2}</div>
CUT
        );

        $form->addInteger('code')->setLabel(___('One Time Password'))->addRule('required');
        $resendUrl = $session->getSignedUrl($this->getDi()->surl('otp/resend', ['type'=>$this->getParam('type')]));
        if ($session->getResendCodeAttemptsCount()) {
            $form->addHtml('link')->setHtml(<<<HTML
<div id="otp-code-resend" style="display:block;"><a href="{$resendUrl}">Resend Code</a></div>
<div id="otp-code-resend-timer" style="display:none;">You can resend code in <span id="otp-timer-value"></span> seconds</div>
HTML
            );
            $timerStart = $this->getDi()->config->get('otp-regenerate-delay',
                    60) - ($this->getDi()->time - $session->getCodeSendTm());
            if ($timerStart > 0) {
                $secondsLeft = json_encode($timerStart);
                $form->addScript()->setScript(<<<EOT
jQuery(document).ready(function($){
    const secondsLeft = {$secondsLeft};
    $("#otp-code-resend").hide();
    $("#otp-code-resend-timer").show();
    $("#otp-timer-value").html({$secondsLeft});
    const countDown = setInterval(function(){
        let currTimer = parseInt($("#otp-timer-value").html())-1;
        $("#otp-timer-value").html(currTimer);
        if (currTimer == 0) {
            clearInterval(countDown);
            $("#otp-code-resend").show();
            $("#otp-code-resend-timer").hide();
        }
    }, 1000);
    
});
EOT
                );
            }
        }

        $gr = $form->addGroup();
        $gr->addSubmit('confirm', ['value' => ___('Confirm')]);
        $gr->addHtml()->setHtml(<<<LINK
<span style="margin-left:1em;"><a href="{$session->getRedirect()}">Cancel</a></span>
LINK
        );

        if ($form->isSubmitted() && $form->validate()) {
            $value = $form->getValue();
            $result = $session->validateCode($value['code']);
            switch ($result) {
                case Am_Otp_Session::CODE_OK :
                    $session->setStatus(Am_Otp_Session::STATUS_CONFIRMED);
                    $session->getAdapter()->setVerified();
                    $redirect = $session->getRedirect();
                    return $this->_response->setRedirect($session->getSignedUrl($redirect ?? $this->getDi()->surl('/member')));
                    break;
                case Am_Otp_Session::CODE_NO_ATTEMPTS :
                case Am_Otp_Session::CODE_EXPIRED :
                    $form = new Am_Form(null, null, 'get');
                    $form->addSubmit('continue', ['value' => 'Return Back']);
                    $form->setAction($redirect = $session->getRedirect());
                    $session->destroy();
                    $form->setError(___('Verification code is wrong. Please %sstart over%s', "<a href='{$redirect}'>",
                        "</a>"));
                    break;
                case Am_Otp_Session::CODE_WRONG :
                    // Ask to enter code again
                    $form->setError(___('Verification code is wrong. Please try again'));
                    break;
                default:
                    throw new Am_Exception_InternalError(___('Unknown result received'));
            }

        }
        $this->view->assign('title', ___('One Time Password'));
        $this->view->assign('content', $form);
        $this->view->display('layout.phtml');
    }
}