<?php

class Am_Widget_AffCustomRedirect extends Am_Widget
{
    protected $id = 'aff-custom-redirect';
    protected $path = 'aff-custom-redirect.phtml';

    public function getTitle()
    {
        return ___('Affiliate link with Custom Landing Page');
    }

    public function prepare(Am_View $view)
    {
        if (!$this->getDi()->auth->getUser()) {
            return false;
        }

        $module = $this->getDi()->modules->get('aff');

        if (!$module->canUseCustomRedirect($this->getDi()->user)) {
            return false;
        }
    }
}