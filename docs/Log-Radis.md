Log\Radis
===============

Radis is a graylog logging radio through a redis database

Radis (from *Radio* and *Redis*) is a concept of caching GELF messages in a Redis DB. Redis provides a *reliable queue* via the [RPOPLPUSH](http://redis.io/commands/rpoplpush) command.

The implementation of a Radis client is quite simple: just push a GELF message with the [LPUSH](http://redis.io/commands/lpush) command onto the queue. A collector fetches the messages from the queue and inserts them into a Graylog2 server, for example.

```php
use \Log\Radis;
$radis = new \Log\Radis();
$radis->log('info', 'Hello, World!');
```


* Class name: Radis
* Namespace: Log
* Parent class: Psr\Log\AbstractLogger





Properties
----------


### $hostname

    public string $hostname

System hostname



* Visibility: **public**


### $defaultLevel

    public integer $defaultLevel = 6

Default level, if not given.



* Visibility: **public**


Methods
-------


### __construct

    mixed Log\Radis::__construct(string $server, string $queue, \Log\Redis $redis)

Connnects to a Redis DB

```php
// Connect with default values
$radis = new \Log\Radis('localhost:6379', 'graylog-radis');
```

* Visibility: **public**


#### Arguments
* $server **string** - &lt;p&gt;Name of the Redis server, in format &lt;em&gt;hostname&lt;/em&gt;:&lt;em&gt;port&lt;/em&gt;&lt;/p&gt;
* $queue **string** - &lt;p&gt;Name of the queue&lt;/p&gt;
* $redis **Log\Redis** - &lt;p&gt;@internal&lt;/p&gt;



### setQueue

    mixed Log\Radis::setQueue(string $queue)

Set queue name



* Visibility: **public**


#### Arguments
* $queue **string**



### setDefault

    mixed Log\Radis::setDefault(string $key, mixed $val)

Set default value, if `$key` is present in `$context`



* Visibility: **public**


#### Arguments
* $key **string**
* $val **mixed** - &lt;p&gt;May be also a callable function&lt;/p&gt;



### fork

    \Log\Radis Log\Radis::fork(string $queue)

Fork object with another queue name

Simply creates a new instance of ourselves, with the same server name, but with a different queue name.

* Visibility: **public**


#### Arguments
* $queue **string**



### log

    boolean Log\Radis::log(string $level, string $message, array $context)

Pushes a message to Redis

Any values of `$context`, at any deep level, which are callable (a function for example) are executed at this point.

This may be useful together with default values:

```php
$radis->setDefault('timestamp', function() { return time(); });
$radis->log(...);
```

The whole array of `$context` will be flattened, the subarrays are merged and the names of subkeys are concatenated together with an underscore.

These keywords are set by default, when not present:

| GELF key | PHP value |
| --- | --- |
| host             | `gethostname()` |
| timestamp        | `sprintf('%0.06', microtime(true))` |
| level            | `$level` |
| short_message    | `$message` |
| _time_offset     | `sprintf('%0.06', microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'])` |
| _php_script      | `$_SERVER['SCRIPT_FILENAME']` |
| _http_query      | `$_SERVER['QUERY_STRING']` |
| _http_path       | `$_SERVER['PATH_INFO']` |
| _http_addr       | `$_SERVER['SERVER_ADDR']` |
| _http_vhost      | `$_SERVER['SERVER_NAME']` |
| _http_proto      | `$_SERVER['SERVER_PROTOCOL']` |
| _http_method     | `$_SERVER['REQUEST_METHOD']` |
| _http_uri        | `$_SERVER['REQUEST_URI']` |
| _http_referer    | `$_SERVER['HTTP_REFERER']` |
| _http_host       | `$_SERVER['HTTP_HOST']` |
| _http_useragent  | `$_SERVER['HTTP_USER_AGENT']` |
| _http_connection | `$_SERVER['HTTP_CONNECTION']` |
| _http_user       | `$_SERVER['REMOTE_USER']` |
| _client_addr     | `$_SERVER['REMOTE_ADDR']` |
| _client_port     | `$_SERVER['REMOTE_PORT']` |
| _session_id      | `session_id();` |

and they will be only filled if the corresponding variables are available. In a CLI application, only `SCRIPT_FILENAME` is present, for example.

* Visibility: **public**


#### Arguments
* $level **string** - &lt;p&gt;The severity level of log you are making.&lt;/p&gt;
* $message **string** - &lt;p&gt;The message you want to log.&lt;/p&gt;
* $context **array** - &lt;p&gt;Additional information about the logged message&lt;/p&gt;


