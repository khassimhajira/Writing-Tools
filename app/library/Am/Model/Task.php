<?php
/**
 * Class represents tasks to run
 */
class TaskRecord extends Am_Record
{
}

class TaskTable extends Am_Table implements Am_Tasks_Storage
{
    protected $_key = 'task_id';
    protected $_table = '?_task';
    protected $_recordClass = 'TaskRecord';

    function storeTask(Am_Task $task)
    {
        $this->insert($task->toArray() + ['added' => Am_Di::getInstance()->time, ], false);
    }

    /**
     * @param int $maxCount
     * @param int $time
     * @return Am_Task[]
     */
    function fetchTasks(int $maxCount, int $time): array
    {
        $a = $this->_db->select(
            "SELECT * FROM ?_task " .
            " WHERE $time >= IFNULL(after, $time) AND $time < IFNULL(skip_after, $time+1) AND status=?d " .
            " ORDER BY priority DESC, task_id " .
            " LIMIT $maxCount",
            Am_Task::ST_QUEUED);
        return array_map([Am_Task::class, 'fromArray'], $a);
    }

    function taskStarted(Am_Task $task, float $time)
    {
        $this->_db->query(
            "UPDATE ?_task SET status=?d, tm_started=? WHERE task_id=?",
            Am_Task::ST_STARTED, $time, $task->task_id()
        );
    }

    function taskFinished(Am_Task $task, float $time)
    {
        $this->_db->query(
            "UPDATE ?_task SET status=?d, tm_finished=? WHERE task_id=?",
            Am_Task::ST_FINISHED, $time, $task->task_id()
        );
    }

    function taskFailed(Am_Task $task, float $time, string $error)
    {
        $this->_db->query(
            "UPDATE ?_task SET status=?d, tm_finished=?, error=? WHERE task_id=?",
            Am_Task::ST_FAILED, $time, $error, $task->task_id()
        );
    }

    public function cleanUp()
    {
        $this->_db->query(
            "DELETE FROM ?_task WHERE added<?d AND (after IS NULL OR after<?d)",
            Am_Di::getInstance()->time - 3600*24*14,
            Am_Di::getInstance()->time - 3600*24*7
        );
    }

    function cancelTasks(string $subject)
    {
        $this->_db->query(
            "UPDATE ?_task SET status=?d WHERE status=?d AND subject=?",
            Am_Task::ST_CANCELLED,
            Am_Task::ST_QUEUED,
            $subject
        );
    }
}