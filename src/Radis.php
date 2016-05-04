<?php

/**
 * Radis logger for PHP
 * @copyright 2016 David Zurborg
 * @author David Zurborg <zurborg@cpan.org>
 * @link https://github.com/zurborg/liblog-radis-php
 * @license https://opensource.org/licenses/ISC The ISC License
 */

namespace Log;

/**
 * Radis is a graylog logging radio through a redis database
 *
 * Radis (from *Radio* and *Redis*) is a concept of caching GELF messages in a Redis DB. Redis provides a *reliable queue* via the [RPOPLPUSH](http://redis.io/commands/rpoplpush) command.
 *
 * The implementation of a Radis client is quite simple: just push a GELF message with the [LPUSH](http://redis.io/commands/lpush) command onto the queue. A collector fetches the messages from the queue and inserts them into a Graylog2 server, for example.
 *
 * ```php
 * use \Log\Radis;
 * $radis = new \Log\Radis();
 * $radis->log('info', 'Hello, World!');
 * ```
 */
class Radis extends \Psr\Log\AbstractLogger
{

    /**
     * System hostname
     *
     * @var string Defaults to the result of `gethostname()`
     * @see https://php.net/manual/function.gethostname.php
     */
    public $hostname;

    /**
     * Default level, if not given.
     *
     * @var int Should be a single digit between 1 and 9, inclusive.
     */
    public $defaultLevel = 6;

    /**
     * Instance of Redis
     *
     * @var \Redis
     */
    protected $redis;

    /**
     * Stored default values
     *
     * @see setDefault()
     * @var mixed[]
     */
    protected $defaults = [];

    /**
     * Translation of level name to level code
     *
     * @var int[]
     */
    protected $levels = [
        'emergency' => 1,
        'alert'     => 2,
        'critical'  => 3,
        'error'     => 4,
        'warning'   => 5,
        'notice'    => 6,
        'info'      => 7,
        'debug'     => 8,
    ];

    /**
     * @internal
     */
    private $server;

    /**
     * @internal
     * @var bool
     */
    public $testMode = false;

    /**
     * @internal
     * @var string
     */
    public $lastGELF;

    /**
     * Connnects to a Redis DB
     *
     * ```php
     * // Connect with default values
     * $radis = new \Log\Radis('localhost:6379', 'graylog-radis');
     * ```
     * 
     * @see \Redis::pconnect()
     * @param string $server Name of the Redis server, in format *hostname*:*port*
     * @param string $queue Name of the queue
     */
    public function __construct($server = 'localhost:6379', $queue = 'graylog-radis')
    {
        $redis = new \Redis();

        list($host, $port) = explode(':', $server, 2);
        if (!$host) $host = 'localhost';
        if (!$port) $port = 6379;
        if (!$redis->pconnect($host, $port)) {
            die("cannot connect to $host:$port");
        }
        $this->server = $server;
        $this->queue = $queue;
        $this->redis = $redis;
        $this->hostname = gethostname();
    }

    /**
     * Set queue name
     * @param string $queue
     */
    public function setQueue($queue)
    {
        $this->queue = $queue;
    }

    /**
     * Set default value, if `$key` is present in `$context`
     *
     * @param string $key
     * @param mixed $val May be also a callable function
     */
    public function setDefault($key, $val)
    {
        $this->defaults[$key] = $val;
    }

    /**
     * Fork object with another queue name
     *
     * Simply creates a new instance of ourselves, with the same server name, but with a different queue name.
     * 
     * @param string $queue
     * @return \Log\Radis
     */
    public function fork($queue)
    {
        return new Radis($this->server, $queue);
    }

    /**
     * @param mixed[] &$array
     * @param string $key
     * @param mixed|null $value
     */
    static private function setIf(array &$array, $key, $value)
    {
        if (array_key_exists($key, $array) and isset($array[$key]))
            return;

        if (is_callable($value))
            $value = $value();

        if (is_null($value))
            return;

        if (is_string($value) and !strlen($value))
            return;

        $array[$key] = $value;
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed|null
     */
    static private function getServerVar($key, $default = null)
    {
        return array_key_exists($key, $_SERVER) ? $_SERVER[$key] : $default;
    }

    /**
     * @param mixed[] $input
     * @param string $prefix
     * @return mixed[]
     */
    static private function flatten(array $input, $prefix = '')
    {
        $output = [];

        foreach ($input as $key => $val)
        {
            if (is_callable($val))
                $val = $val();

            if (is_null($val))
                continue;

            if (is_array($val))
            {
                $temp = self::flatten($val, $prefix.$key.'_');
                $output = array_merge($output, $temp);
                continue;
            }

            if (is_string($val) and !strlen($val))
                continue;

            $key = strtolower($prefix.$key);

            if (substr($key, 0, 1) !== '_')
                $key = "_$key";

            $output[$key] = $val;
        }

        return $output;
    }

    /**
     * @param int|string $level
     * @param string $message
     * @param mixed[] $context
     * @return mixed[]
     */
    protected function prepare($level, $message, array $context)
    {
        $now = microtime(true);
        $offset = $now - self::getServerVar('REQUEST_TIME_FLOAT');

        if (is_null($level))
            $level = $this->defaultLevel;

        if (!is_int($level))
        {
            if (!array_key_exists($level, $this->levels))
                $level = $this->defaultLevel;

            $level = $this->levels[$level];
        }

        $context = array_merge($this->defaults, $context);

        $extras = self::flatten($context);

        self::setIf($extras, '_time_offset',     sprintf('%0.06f', $offset));
        self::setIf($extras, '_php_script',      self::getServerVar('SCRIPT_FILENAME'));
        self::setIf($extras, '_http_query',      self::getServerVar('QUERY_STRING'));
        self::setIf($extras, '_http_path',       self::getServerVar('PATH_INFO'));
        self::setIf($extras, '_http_addr',       self::getServerVar('SERVER_ADDR'));
        self::setIf($extras, '_http_vhost',      self::getServerVar('SERVER_NAME'));
        self::setIf($extras, '_http_proto',      self::getServerVar('SERVER_PROTOCOL'));
        self::setIf($extras, '_http_method',     self::getServerVar('REQUEST_METHOD'));
        self::setIf($extras, '_http_uri',        self::getServerVar('REQUEST_URI'));
        self::setIf($extras, '_http_referer',    self::getServerVar('HTTP_REFERER'));
        self::setIf($extras, '_http_host',       self::getServerVar('HTTP_HOST'));
        self::setIf($extras, '_http_useragent',  self::getServerVar('HTTP_USER_AGENT'));
        self::setIf($extras, '_http_connection', self::getServerVar('HTTP_CONNECTION'));
        self::setIf($extras, '_http_user',       self::getServerVar('REMOTE_USER'));
        self::setIf($extras, '_client_addr',     self::getServerVar('REMOTE_ADDR'));
        self::setIf($extras, '_client_port',     self::getServerVar('REMOTE_PORT'));
        self::setIf($extras, '_session_id',      session_id());

        if (strstr($message, "\n"))
        {
            list($short_message, $long_message) = explode("\n", $message, 2);
            $extras['message'] = $long_message;
            $message = $short_message;
        }

        $gelf = array_merge($extras, [
            'host' => $this->hostname,
            'timestamp' => sprintf('%0.06f', $now),
            'short_message' => $message,
            'level' => $level,
        ]);
        
        return $gelf;
    }

    /**
     * Pushes a message to Redis
     *
     * Any values of `$context`, at any deep level, which are callable (a function for example) are executed at this point.
     *
     * This may be useful together with default values:
     *
     * ```php
     * $radis->setDefault('timestamp', function() { return time(); });
     * $radis->log(...);
     * ```
     * 
     * The whole array of `$context` will be flattened, the subarrays are merged and the names of subkeys are concatenated together with an underscore.
     *
     * These keywords are set by default, when not present:
     *
     * | GELF key | PHP value |
     * | --- | --- |
     * | host             | `gethostname()` |
     * | timestamp        | `sprintf('%0.06', microtime(true))` |
     * | level            | `$level` |
     * | short_message    | `$message` |
     * | _time_offset     | `sprintf('%0.06', microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'])` |
     * | _php_script      | `$_SERVER['SCRIPT_FILENAME']` |
     * | _http_query      | `$_SERVER['QUERY_STRING']` |
     * | _http_path       | `$_SERVER['PATH_INFO']` |
     * | _http_addr       | `$_SERVER['SERVER_ADDR']` |
     * | _http_vhost      | `$_SERVER['SERVER_NAME']` |
     * | _http_proto      | `$_SERVER['SERVER_PROTOCOL']` |
     * | _http_method     | `$_SERVER['REQUEST_METHOD']` |
     * | _http_uri        | `$_SERVER['REQUEST_URI']` |
     * | _http_referer    | `$_SERVER['HTTP_REFERER']` |
     * | _http_host       | `$_SERVER['HTTP_HOST']` |
     * | _http_useragent  | `$_SERVER['HTTP_USER_AGENT']` |
     * | _http_connection | `$_SERVER['HTTP_CONNECTION']` |
     * | _http_user       | `$_SERVER['REMOTE_USER']` |
     * | _client_addr     | `$_SERVER['REMOTE_ADDR']` |
     * | _client_port     | `$_SERVER['REMOTE_PORT']` |
     * | _session_id      | `session_id();` |
     *
     * and they will be only filled if the corresponding variables are available. In a CLI application, only `SCRIPT_FILENAME` is present, for example. 
     *
     * @param string $level The severity level of log you are making.
     * @param string $message The message you want to log.
     * @param array $context Additional information about the logged message
     * @return bool success of push.
     */
    public function log($level, $message, array $context = [])
    {
        $gelf = $this->prepare($level, $message, $context);

        if ($this->testMode)
        {
            $this->lastGELF = $gelf;
            return true;
        }

        $gelf = json_encode($gelf);

        $result = $this->redis->lPush($this->queue, $gelf);

        return !($result === false);
    }

}

