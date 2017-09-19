<?php

include_once(__DIR__ . '/../vendor/autoload.php');

class RadisTest extends PHPUnit_Framework_TestCase
{
    public $stack = [];

    protected function lastMessage()
    {
        $json = array_shift($this->stack);
        return (array) json_decode($json);
    }

    protected function connect($host = 'foo', $port = 123, $queue = 'bar') {
        $mock = $this->getMockBuilder('Redis')
                     ->setMethods(['pconnect', 'lPush'])
                     ->getMock();

        $server = $host.':'.$port;

        $mock->expects($this->once())
             ->method('pconnect')
             ->with($this->equalTo($host),
                    $this->equalTo($port))
             ->will($this->returnValue(TRUE));

        $self = $this;
        $mock->expects($this->any())
             ->method('lPush')
             ->with($this->equalTo($queue),
                    $this->callback(function ($json) use ($self) {
                        $self->stack[] = $json;
                        return true;
                    }))
             ->will($this->returnValue(1));

        $radis = new \Log\Radis($server, $queue, $mock);
        return $radis;
    }

    public function test001()
    {
        $radis = $this->connect();
        $this->assertInstanceOf('\Log\Radis', $radis);
        $this->assertEquals(gethostname(), $radis->hostname);
    }

    public function test002()
    {
        $radis = $this->connect();
        $radis->log('info', 'foobar');
        $msg = $this->lastMessage();
        $this->assertEquals(7, $msg['level']);
        $this->assertEquals($radis->hostname, $msg['host']);
        $this->assertEquals($_SERVER['SCRIPT_FILENAME'], $msg['_php_script']);
        $this->assertEquals('foobar', $msg['message']);
    }

    public function test003()
    {
        $radis = $this->connect();
        $radis->log('info', '', [ 'foo' => 'bar' ]);
        $msg = $this->lastMessage();
        $this->assertEquals('bar', $msg['_foo']);

        $radis->log('info', '', [ [ 'foo','bar' ],[ 'baf', 'baz'] ]);
        $msg = $this->lastMessage();
        $this->assertEquals('foo', $msg['_0_0']);
        $this->assertEquals('bar', $msg['_0_1']);
        $this->assertEquals('baf', $msg['_1_0']);
        $this->assertEquals('baz', $msg['_1_1']);
    }

    public function test004()
    {
        $radis = $this->connect();
        $radis->log('info', "foo\nbar\nbaf\nbaz");
        $msg = $this->lastMessage();
        $this->assertEquals('foo', $msg['message']);
        $this->assertEquals("bar\nbaf\nbaz", $msg['full_message']);
    }

    public function test005()
    {
        $radis = $this->connect();
        $radis->setDefault('foo', 'bar');
        $radis->setDefault('null', null);
        $i = 0;
        $radis->setDefault('func', function () use (&$i) { $i++; return $i*2; });
        $this->assertEquals(0, $i);
        $radis->log('info', '');
        $this->assertEquals(1, $i);
        $msg = $this->lastMessage();
        $this->assertEquals('bar', $msg['_foo']);
        $this->assertArrayNotHasKey('_null', $msg);
        $this->assertEquals(2, $msg['_func']);
    }
}


