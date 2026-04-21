<?php

class AdminClearCacheController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return true;
    }

    function indexAction()
    {
        $label = ___('Clear Cache');
        $msg = ___('You can use this tool to clear all cache data.');
        $url = $this->getDi()->url('admin-clear-cache/do');
        $this->view->title = ___('Clear Cache');
        $this->view->content = <<<CUT
<p>$msg</p>
<a href="$url" class="button">$label</a>
CUT;
        $this->view->display('admin/layout.phtml');
    }

    function doAction()
    {
        $this->getDi()->cacheBackend->clean();
        $this->view->title = ___('Clear Cache');
        $this->view->content = ___('All Cache data is cleared');
        $this->view->display('admin/layout.phtml');
    }
}