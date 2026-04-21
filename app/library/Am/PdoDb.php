<?php
namespace Am;

use PDO;
use PDOStatement;
use Am_Exception_Db;

/**
 * This is a simple and quick wrapper for PDO connection
 * this file is used in Lite, should not use external code
 */
class PdoDb
{
    /** @var PDO  */
    protected $conn;
    protected string $prefix;
    /** @var callable  function($errorOrException) */
    protected $errorCallback;

    /**
     * @param PDO|\Am_Db_PDOProxy $conn
     * @param string $prefix
     * @param callable $errorCallback
     */
    function __construct($conn, string $prefix, callable $errorCallback) {
        $this->conn = $conn;
        $this->prefix = $prefix;
        $this->errorCallback = $errorCallback;
    }

    static function createFromDb(\Am_Db $db) : self {
        return new self($db->getPdo(), $db->getPrefix(), function($msgOrException) {
            if (!$msgOrException instanceof \Exception)
                throw new Am_Exception_Db($msgOrException);
            else
                throw $msgOrException;
        });
    }

    protected function replaceTablePrefix(string $sql) : string {
        return preg_replace('/(\s)\?_([a-z0-9_]+)\b/', ' ' . $this->prefix . '\2', $sql);
    }

    function liteQuery($sql, ...$argv) : PDOStatement
    {
        $sql = $this->replaceTablePrefix($sql);
        foreach ($argv as & $arg) //skip first value, it is $sql
        {
            if (is_array($arg))
            {
                $arg = implode(',', array_map([$this->conn, 'quote'], $arg));
            } elseif (is_null($arg)) {
                $arg = 'NULL';
            } else {
                $arg = $this->conn->quote($arg);
            }
        }
        $f = function() use (& $argv) {
            return $argv ? array_shift($argv) : 'LITE_DB_ERROR_NO_VALUE';
        };
        $sql = preg_replace_callback('#\?#', $f, $sql);
        $statement = $this->conn->query($sql);
        if (!$statement)
        {
            $errorInfo = $this->conn->errorInfo();
            $this->error($errorInfo[2]);
        }
        $statement->setFetchMode(PDO::FETCH_ASSOC);

        return $statement;
    }

    public function prefix() : string {
        return $this->prefix;
    }

    public function error($msgOrExceptionOrErrorInfo) {
        if (is_array($msgOrExceptionOrErrorInfo)) {
            $msgOrExceptionOrErrorInfo = $msgOrExceptionOrErrorInfo[0] . '/' . $msgOrExceptionOrErrorInfo[1] . ":" . $msgOrExceptionOrErrorInfo[2];
        }
        ($this->errorCallback)($msgOrExceptionOrErrorInfo);
    }

    public function prepare(string $query, ...$options) : PDOStatement {
        $query = $this->replaceTablePrefix($query);
        $q = $this->conn->prepare($query, ...$options);
        if (!$q) {
            $this->error($this->conn->errorInfo());
        }
        return $q;
    }

    /**
     * Prepare and execute statement with error checking
     * return number of affected rows
     *
     * @param $query
     * @param array $args
     * @return int
     */
    public function exec($query, array $args = []) : int
    {
        if (!$args) {
            $query = $this->replaceTablePrefix($query);
            return $this->conn->exec($query);
        } else {
            $st = $this->prepare($query);
            if (!$st->execute($args)) {
                $this->error($st->errorInfo());
            }
            return $st->rowCount();
        }
    }

    public function execStatement(PDOStatement $st, ...$args)
    {
        $ret = call_user_func_array([$st, 'execute'], $args);
        if ($ret === false) {
            $this->error($st->errorInfo());
        }
        return $ret;
    }

    public function selectCell(string $query, array $args = []) {
        $st = $this->prepare($query);
        if (!$st->execute($args)) {
            $this->error($this->conn->errorInfo());
        }
        $row = $st->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    public function selectRow(string $query, array $args = []) {
        $st = $this->prepare($query);
        if (!$st->execute($args)) {
            $this->error($this->conn->errorInfo());
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    public function select(string $query, array $args = []) : array{
        $st = $this->prepare($query);
        if (!$st->execute($args)) {
            $this->error($this->conn->errorInfo());
        }
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function quote(string $s, $kind = PDO::PARAM_STR) : string
    {
        return $this->conn->quote($s, $kind);
    }

}