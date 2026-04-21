<?php

interface Am_Tasks_Storage
{
    function storeTask(Am_Task $task);

    /** @return Am_Task[] */
    function fetchTasks(int $maxCount, int $time);
    function cancelTasks(string $subject);

    function taskStarted(Am_Task $task, float $time);
    function taskFinished(Am_Task $task, float $time);
    function taskFailed(Am_Task $task, float $time, string $error);

    // remove old and expired tasks
    function cleanUp();
}


class Am_Tasks
{
    protected $di;

    const CACHE_HAVE_TASKS = 'haveTasks';
    protected $lockId;
    /** @var Am_Locker_Interface */
    private $locker;

    function __construct(Am_Di $di)
    {
        $this->di = $di;
        $this->lockId = 'am_tasks_lock_'.md5(__FILE__);
        $this->locker = new Am_Locker_Null;
    }

    function setupHook()
    {
        $this->di->hook->add(Am_Event::VIEW_BODY_APPEND, function(Am_Event $event){
            if (0 !== $this->di->cache->load(self::CACHE_HAVE_TASKS))
            {
                $url = $this->di->url('cron/tasks');
                $event->addReturn("<img src='$url' width='1' height='1' style='display:none'>");
            }
        });

    }

    function schedule(Am_Task $task)
    {
        $this->di->tasksStorage->storeTask($task);
        $this->di->cache->save(1 , self::CACHE_HAVE_TASKS);
    }

    function cancel(string $subject) : self
    {
        $this->di->tasksStorage->cancelTasks($subject);
        return $this;
    }

    function create($callback, array $params = []) : Am_Task
    {
        return new Am_Task($callback, $params, [$this, 'schedule']);
    }

    function run($tasksLimit = 10, $beforeRun = null) : int
    {
        if ($beforeRun === null) $beforeRun = function(){};
        $storage = $this->di->tasksStorage;
        $tasks = $storage->fetchTasks($tasksLimit, $this->di->time);
        $executed = 0;
        if (!$tasks)
        {
            $this->di->cache->save(0, self::CACHE_HAVE_TASKS, [], 120);
        }
        foreach ($tasks as $task)
        {
            $storage->taskStarted($task, microtime(true));
            try {
                if (false === $beforeRun) return $executed;
                $executed++;
                $task->run();
                $storage->taskFinished($task, microtime(true));
            } catch (\Exception $e) {
                $storage->taskFailed($task, microtime(true), (string)$e);
            }
        }
        return $executed;
    }

    function runAsManyAsPossibleWithLock($log = null, $timeLimit = 60)
    {
        if ($log === null)
            $log = function(){};

        $this->locker = $this->di->createLocker($this->lockId);
        try {
            $this->locker->lock(60);
        } catch (Am_Exception_CannotAcquireLock $e) {
            $log("Could not set lock, skip cron run");
        }

        $stopAt = $this->di->time + $timeLimit;
        $beforeRun = function() use ($stopAt) {
            static $prevTime;
            $tm = $this->di->time;
            if ($tm > $stopAt) return false;
            if ($prevTime != $tm)
            {
                $prevTime = $tm;
                $this->locker->lock(120);
            }
        };

        while ($this->run(50, $beforeRun) > 0);
    }

}

class Am_Task
{
    const PR_HIGH = 10;
    const PR_NORMAL = 5;
    const PR_LOW = 1;

    const ST_QUEUED = 0;
    const ST_STARTED = 10;
    const ST_FINISHED = 20;
    const ST_CANCELLED = 21;
    const ST_FAILED = 30;
    const ST_EXPIRED = 31;

    const E_SKIP_CODE = 4040;

    protected $priority;
    protected $callback;
    protected $params;
    protected $subject;
    protected $after;
    protected $skip_after;
    protected $task_id;

    protected $_scheduleCallback;

    function __construct($callback, $params, $scheduleCallback = null)
    {
        $this->setCallback($callback);
        $this->params = is_scalar($params) ? $params : json_encode($params);
        $this->_scheduleCallback = $scheduleCallback;
    }

    protected function setCallback($callback)
    {
        $findPluginSetString = function(Am_Plugin_Base $o) {
            $id = $o->getId();
            foreach (Am_Di::getInstance()->plugins as $plugins) {
                if (!in_array($id, $plugins->getEnabled())) continue;
                if ($plugins->loadGet($id) === $o)
                    return "__plugin:{$plugins->getId()}:$id";
            }
        };

        if (is_array($callback))
        {
            if (is_object($callback[0])) {
                $o = $callback[0];
                if (!$o instanceof Am_Plugin_Base)
                {
                    throw new Am_Exception_InternalError("Task callback must be a static function call or a plugin method callback");
                } else {
                    $callback[0] = $findPluginSetString($o);
                    $callback = $callback[0] . '->' . $callback[1];
                }
            } else {
                $callback = $callback[0] . '::' . $callback[1];
            }
        }
        if (!is_string($callback))
            throw new Am_Exception_InternalError("Task callback must be a static function call or a plugin method callback");

        $this->callback = $callback;
    }

    public function signature()
    {
        return $this->callback;
    }

    function priority(int $priority) : self
    {
        $this->priority = $priority;
        return $this;
    }

    function subject(string $subject) : self
    {
        $this->subject = $subject;
        return $this;
    }

    function after(int $time) : self
    {
        $this->after = $time;
        return $this;
    }

    function task_id()
    {
        return $this->task_id;
    }

    function skipAfter(int $time) : self
    {
        $this->skip_after = $time;
        return $this;
    }

    function schedule()
    {
        call_user_func($this->_scheduleCallback, $this);
    }

    protected function resolveCallback()
    {
        if (0 === strpos($this->callback, '__plugin:'))
        {
            $cb = explode('->', $this->callback, 2);
            $oo = explode(':', $cb[0]);
            $plList = Am_Di::getInstance()->plugins[$oo[1]];
            if (!$plList->isEnabled($oo[2]))
                throw new Am_Exception_InternalError("Plugin {$oo[1]}:{$oo[2]} is disabled, skip this task", self::E_SKIP_CODE);
            $pl = $plList->loadGet($oo[2]);
            return [$pl, $cb[1]];
        }
        if (false !== strpos($this->callback, '::'))
        {
            return explode('::', $this->callback, 2);
        }
        return $this->callback;
    }

    function run()
    {
        $cb = $this->resolveCallback();
        call_user_func_array($cb, json_decode($this->params, true));
    }

    function toArray() : array
    {
        return [
            'callback' => $this->callback,
            'params' => $this->params,
            'priority' => $this->priority,
            'subject' => $this->subject,
            'after' => $this->after,
            'skip_after' => $this->skip_after,
            'task_id' => $this->task_id,
        ];
    }

    static function fromArray(array $data) : self
    {
        $t = new self($data['callback'], $data['params']);
        $t->priority = $data['priority'];
        $t->subject = $data['subject'];
        $t->after = $data['after'];
        $t->skip_after = $data['skip_after'];
        $t->task_id = $data['task_id'];
        return $t;
    }

}
