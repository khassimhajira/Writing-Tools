<?php
/**
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: Cron run file
*    FileName $RCSfile$
*    Release: 6.3.39 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class CronController extends Am_Mvc_Controller
{
    public function preDispatch()
    {
        set_time_limit(0);
        ignore_user_abort(true);
        ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);
        $this->getDi()->session->writeClose();

        $this->getDi()->auth->_setUser(null);
        $this->getDi()->authAdmin->_setUser(null);
        $_COOKIE = [];
    }

    function indexAction()
    {
        $log = $this->_request->getInt('debug') ? function(){
            $args = func_get_args();
            foreach ($args as $a)
                print_r($a);
                echo "\n";
        } : function(){};

        $this->getDi()->cron->checkCron($log);
    }

    function tasksAction()
    {
        $this->getDi()->tasks->runAsManyAsPossibleWithLock(null, 120);
    }
}