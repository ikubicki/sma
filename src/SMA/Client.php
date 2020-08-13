<?php

namespace Irekk\SMA;

class Client
{
    
    const VALUE_MAXIMUM_POWER = '6100_00411E00';
    const VALUE_CURRENT_POWER = '6100_40263F00';
    const VALUE_TOTAL_YIELD = '6400_00260100';
    const VALUE_TODAY_YIELD = '6400_00262200';
    const VALUE_UPTIME = '6400_00462E00';
    const YIELD_KEY_TODAY = 28672; // today yield
    const YIELD_KEY_BEFORE = 28704; // past yields

    /**
     * Constructor
     * Creates instance of HTTP connector
     * Logs into inverter API
     * 
     * Accepted options:
     * $options['url'] - Base URL of inverter, eg http://192.168.0.2
     * $options['user'] - username / right to SMA inverter API
     * $options['pass'] - password of specified user to SMA inverter API
     * 
     * @author ikubicki
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if (empty($options['user'])) {
            $options['user'] = 'usr';
        }
        if (empty($options['pass'])) {
            $options['pass'] = '0000';
        }
        $this->connector = $this->getHttpConnector($options['url']);
        $this->login($options['user'], $options['pass']);
    }

    /**
     * Destructor
     * Closes session to API
     * 
     * @author ikubicki
     */
    public function __destruct()
    {
        $this->logout();
    }

    /**
     * Returns current power
     * 
     * @author ikubicki
     * @return array
     */
    public function getCurrentPower()
    {
        $data = $this->getData();
        return [
            'value' => $data[self::VALUE_CURRENT_POWER],
            'unit' => 'W',
        ];
    }

    /**
     * Returns maximum power
     * 
     * @author ikubicki
     * @return array
     */
    public function getMaximumPower()
    {
        $data = $this->getData();
        return [
            'value' => $data[self::VALUE_MAXIMUM_POWER],
            'unit' => 'W',
        ];
    }

    /**
     * Returns today yield
     * 
     * @author ikubicki
     * @return array
     */
    public function getTodayYield()
    {
        $data = $this->getData();
        return [
            'value' => $data[self::VALUE_TODAY_YIELD],
            'unit' => 'Wh',
        ];
    }

    /**
     * Returns total yield
     * 
     * @author ikubicki
     * @return array
     */
    public function getTotalYield()
    {
        $data = $this->getData();
        return [
            'value' => $data[self::VALUE_TOTAL_YIELD],
            'unit' => 'Wh',
        ];
    }

    /**
     * Returns uptime
     * 
     * @author ikubicki
     * @return array
     */
    public function getUptime()
    {
        $data = $this->getData();
        return [
            'value' => $data[self::VALUE_UPTIME],
            'unit' => 's'
        ];
    }

    /**
     * Returns all metrics
     * 
     * @author ikubicki
     * @return array
     */
    public function getMetrics()
    {
        $this->_cache = null;
        return [
            'power' => $this->getPowerMetrics(),
            'yield' => $this->getYieldMetrics(),
            'uptime' => $this->getUptime(),
        ];
    }

    /**
     * Returns power metrics
     * 
     * @author ikubicki
     * @return array
     */
    public function getPowerMetrics()
    {
        return [
            'maximum' => $this->getMaximumPower(),
            'current' => $this->getCurrentPower(),
        ];
    }

    /**
     * Returns yield metrics
     * 
     * @author ikubicki
     * @return array
     */
    public function getYieldMetrics()
    {
        return [
            'total' => $this->getTotalYield(),
            'today' => $this->getTodayYield(),
        ];
    }

    /**
     * @var array $_cache
     */
    private $_cache;

    /**
     * Returns raw data
     * 
     * @author ikubicki
     * @return array
     */
    public function getData()
    {
        if ($this->_cache === null) {
            $this->_cache = $this->getValues();
        }
        return $this->_cache;
    }

    /**
     * @var string $sid
     */
    protected $sid;

    /**
     * Creates API session
     * 
     * @author ikubicki
     * @param string $user
     * @param string $pass
     * @return string|boolean
     * @throws \Exception
     */
    protected function login($user, $pass)
    {
        $parameters = [
            'right' => $user,
            'pass' => $pass,
        ];
        $response = $this->postData('/dyn/login.json', $parameters);
        $error = $response['err'] ?? '0';
        if ($error > 0) {
            throw new \Exception(sprintf('Unable to login! Server error: %d', $error));
        }
        if (isset($response['result']['sid'])) {
            return $this->sid = $response['result']['sid'];
        }
        return $this->sid = false;
    }

    /**
     * Closes API session
     * 
     * @author ikubicki
     * @throws \Exception
     */
    protected function logout()
    {
        if ($this->sid) {
            $response = $this->postData('/dyn/logout.json?sid=' . $this->sid, []);
            if (isset($response['result']) && $response['result']['isLogin'] !== false) {
                throw new \Exception('Unable to logout!');
            }
            return true;
        }
        return false;
    }

    /**
     * Returns list of values from inverter API
     * 
     * @author ikubicki
     * @return array
     */
    protected function getValues()
    {
        $keys = [
            self::VALUE_MAXIMUM_POWER,
            self::VALUE_CURRENT_POWER,
            self::VALUE_TOTAL_YIELD,
            self::VALUE_TODAY_YIELD,
            self::VALUE_UPTIME,
        ];
        $device = [];
        if ($this->sid) {
            $parameters = [
                'destDev' => [], 
                'keys' => $keys,
            ];
            $response = $this->postData('/dyn/getValues.json?sid=' . $this->sid, $parameters);
            $device = reset($response['result']); // first device
        }
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = null;
            if (isset($device[$key][1][0]['val'])) {
                $values[$key] = $device[$key][1][0]['val'];
            }
        }
         // converts kWh into Wh
        if (!empty($values[self::VALUE_TOTAL_YIELD])) {
            $values[self::VALUE_TOTAL_YIELD] *= 1000;
        }

        // SMA::VALUE_TODAY_YIELD is not supported in Tripower units.
        // It uses logger values to calculate production instead.
        if (empty($values[self::VALUE_TODAY_YIELD])) {
            $todayTimestamp = (new \DateTime('today midnight'))->getTimestamp();
            $values[self::VALUE_TODAY_YIELD] = $this->getPeriodicYield(self::YIELD_KEY_TODAY, $todayTimestamp);
        }

        return $values;
    }

    /**
     * Returns yield calculated based on values between two dates for given key
     * 
     * @author ikubicki
     * @param integer $key
     * @param integer $from
     * @param integer $to
     * @return integer
     */
    protected function getPeriodicYield($key, $from, $to = null)
    {
        if (!$this->sid) {
            return 0;
        }
        $to = $to ?? $from + 86400;
        $parameters = [
            "destDev" => [],
            "key" => $key,
            "tStart" => $from,
            "tEnd" => $to,
        ];
        $response = $this->postData('/dyn/getLogger.json?sid=' . $this->sid, $parameters);
        $logs = reset($response['result']);
        if (count($logs) > 1) {
            $first = reset($logs);
            $last = end($logs);
            return $last['v'] - $first['v'];
        }
        return 0;
    }

    /**
     * @var \GuzzleHttp\Client $connector
     */
    protected $connector;

    /**
     * Creates instance of http connector
     * 
     * @author ikubicki
     * @param string $url
     * @return \GuzzleHttp\ClientInterface
     */
    protected function getHttpConnector($url)
    {
        if (empty($this->connector)) {
            $this->connector = new \GuzzleHttp\Client([
                'base_uri' => $url,
                'verify' => false,
                'http_errors' => false,
            ]);
        }
        return $this->connector;
    }

    /**
     * Makes HTTP POST request
     * 
     * @author ikubicki
     * @param string $path
     * @param array $data
     * @return array
     */
    protected function postData($path, array $data = [])
    {
        $response = $this->connector->post($path, ['json' => $data]);
        return json_decode($response->getBody()->getContents(), true);
    }
}
