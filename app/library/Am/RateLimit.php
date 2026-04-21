<?php

class Am_Exception_RateLimit extends Am_Exception_InputError
{
    protected string $apiName;
    protected int $limitFailed;

    public function __construct(string $apiName, int $limitFailed)
    {
        $this->apiName = $apiName;
        $this->limitFailed = $limitFailed;
        parent::__construct(___("API rate limit exceeded, please repeat your attempt later"));
    }

    public function getCategory()
    {
        return 'rateLimit';
    }

    /** number of seconds limit which has been violated */
    public function limitFailed(): int
    {
        return $this->limitFailed;
    }

    // return this to GraphQL - visible by client!
    public function getExtensions(): ?array
    {
        $p = parent::getExtensions();
        $p['apiName'] = $this->apiName;
        $p['repeatAfter'] = $this->limitFailed;
        return $p;
    }


}

class Am_RateLimit
{
    protected Am_Di $di;

    public function __construct(Am_Di $di)
    {
        $this->di = $di;
    }

    // todo reimplement using cache/memcache for loaded websites
    // this function does not raise exception! use carefully
    // limits is array of [period1 => allowed_number_of_calls1, period2 => allowed_number_of_calls2, ]
    /** @return int 0 if OK, periodSeconds >0 if failed */
    function _test(string $apiName, $keys, array $limits): int
    {
        $hash = sha1($apiName . ':' . json_encode(array_map(fn($_) => (string)$_, $keys))); // string len is limited in DB for md5!

        $maxPeriod = 0;
        $microtime = $this->di->microtime;
        $time = floor($microtime);
        foreach ($limits as $periodSeconds => $limit) {

            if ($periodSeconds > $maxPeriod) $maxPeriod = $periodSeconds;

            $window_start = $time - $periodSeconds;

            $count = $this->di->db->selectCell("
                SELECT COUNT(*) FROM ?_rate_limit
                WHERE hash=? AND tm >= ?                            
            ", $hash, $window_start);

            if ($count >= $limit) return $periodSeconds;
        }

        $this->di->db->query(
            "INSERT INTO ?_rate_limit 
             SET hash=?, tm=?, expires=?d
            ",
            $hash, $this->di->microtime, $this->di->time + $maxPeriod
        );

        return 0;
    }

    /** @throws Am_Exception_RateLimit if not OK * */
    function check(string $apiName, $keys, array $limits)
    {
        if ($limitFailed = $this->_test($apiName, $keys, $limits)) {
            throw new Am_Exception_RateLimit($apiName, $limitFailed);
        }
    }

    function hourly()
    {
        $this->di->db->query("DELETE FROM ?_rate_limit WHERE expires<?d", $this->di->time);
    }
}