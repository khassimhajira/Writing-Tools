<?php
/*
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Admin Info /
 *    FileName $RCSfile$
 *    Release: 6.3.39 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class AdminRestoreController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_BACKUP_RESTORE);
    }

    public function preDispatch()
    {
        if (in_array('cc', $this->getDi()->modules->getEnabled()))
            throw new Am_Exception_AccessDenied(___('Online backup is disabled if you have CC payment plugins enabled. Use offline backup instead'));
    }

    function indexAction()
    {
        $url = $this->url('admin-restore/restore', false);

        $maxFileSize = Am_Storage_File::getSizeReadable(
            min(Am_Storage_File::getSizeBytes(ini_get('post_max_size')),
                Am_Storage_File::getSizeBytes(ini_get('upload_max_filesize'))));

        $form = new Am_Form_Admin('restore');
        $form->setAttribute('enctype', 'multipart/form-data');
        $form->setAction($url);

        $msg1 = ___('To restore the aMember database please pick a previously saved aMember Pro backup.');
        $msg2 = ___('WARNING! ALL YOUR CURRENT AMEMBER TABLES AND RECORDS WILL BE REPLACED WITH THE CONTENTS OF THE BACKUP!');

        $form->addProlog(<<<CUT
<div class="info">
    <p>$msg1</p>
    <p><strong><span style="color:#ba2727">$msg2</span></strong></p>
</div>
CUT
);
        $file = $form->addFile('file')
            ->setLabel(___("File\n(max filesize %s)", $maxFileSize));
        $file->setAttribute('class', 'styled');
        $file->addRule('required');

        $form->addSaveButton(___('Restore'));

        $msg = json_encode(___('It will replace all your exising database with backup. Do you really want to proceed?'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#restore').submit(function(){
        return confirm($msg);
    });
});
CUT
            );

        $this->view->title = ___('Restore Database from Backup');
        $this->view->content = $form;
        $this->view->display('admin/layout.phtml');
    }

    function restoreAction()
    {
        check_demo();
        set_time_limit(0);
        ignore_user_abort(true);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);
        ini_set('auto_detect_line_endings', true);

        if (!$this->_request->isPost())
            throw new Am_Exception_InputError('Only POST requests allowed here');

        $db = $this->getDi()->db;
        $f = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$f)
            throw new Am_Exception_InputError('Can not open uploaded file. ' . Am_Upload::errorMessage($_FILES['file']['error']));

        if (substr($_FILES['file']['name'], -3) == '.gz') {
            throw new Am_Exception_InputError('It seems you use archive with backup file. Please extract backup file from archive and then use it.');
        }

        $first_line = trim(fgets($f));
        $second_line = trim(fgets($f));

        if (!$first_line || !$second_line)
            throw new Am_Exception_InputError('Uploaded file has wrong format or empty');

        $this->view->assign('backup_header', "$first_line<br />$second_line");

        if (!preg_match('/^### aMember Pro .+? database backup/', $first_line))
            throw new Am_Exception_InputError(___('Uploaded file is not valid aMember Pro backup'));

        $query = null;
        while ($query || !feof($f)) {
            if ($query && (substr($query, -1) == ';')) {
                $db->getPDO()->query($query);
                $query = null;
            }
            if ($line = fgets($f))
                $query .= "\r\n" . trim($line);
        }
        fclose($f);

        $this->getDi()->adminLogTable->log("Restored from $first_line");
        $this->displayRestoreOk();
    }

    function displayRestoreOk()
    {
        ob_start();
        $this->view->title = ___('Restored Successfully');

        $url = $this->getDi()->url('admin-rebuild');

        echo '<div class="info">' . ___('aMember database has been successfully restored from backup.') . '</div>
<h2>' . ___('Backup file header') . "</h2>
<pre>
{$this->view->backup_header}
</pre>
<br />
<div><strong>Do not forget to <a href='$url'>Rebuild Db</a> to recalcualte access cache after restore from backup.</strong></div>
";
        $this->view->content = ob_get_clean();
        $this->view->display('admin/layout.phtml');
    }
}