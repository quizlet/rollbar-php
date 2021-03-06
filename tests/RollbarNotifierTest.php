<?php

use \Mockery as m;

if (!defined('ROLLBAR_TEST_TOKEN')) {
    define('ROLLBAR_TEST_TOKEN', 'ad865e76e7fb496fab096ac07b1dbabb');
}

class RollbarNotifierTest extends PHPUnit_Framework_TestCase {
    
    private static $simpleConfig = array(
        'access_token' => ROLLBAR_TEST_TOKEN,
        'environment' => 'test',
        'root' => '/path/to/code/root',
        'code_version' => RollbarNotifier::VERSION,
        'batched' => false
    );

    private $_server;

    public function setUp() {
        $this->_server = $_SERVER;
    }

    public function tearDown() {
        m::close();
        $_SERVER = $this->_server;
    }

    public function testConstruct() {
        $notifier = new RollbarNotifier(self::$simpleConfig);

        $this->assertEquals('ad865e76e7fb496fab096ac07b1dbabb', $notifier->access_token);
        $this->assertEquals('test', $notifier->environment);
    }

    public function testSimpleMessage() {
        $notifier = m::mock('RollbarNotifier[_send_payload_blocking]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('_send_payload_blocking')->once();

        $uuid = $notifier->report_message("Hello world");
        $this->assertValidUUID($uuid);
    }

    public function testSimpleError() {
        $notifier = new RollbarNotifier(self::$simpleConfig);
        
        $uuid = $notifier->report_php_error(E_ERROR, "Runtime error", "the_file.php", 1);
        $this->assertValidUUID($uuid);
    }

    public function testSimpleException() {
        $notifier = new RollbarNotifier(self::$simpleConfig);

        $uuid = null;
        try {
            throw new Exception("test exception");
        } catch (Exception $e) {
            $uuid = $notifier->report_exception($e);
        }

        $this->assertValidUUID($uuid);
    }

    public function testFlush() {
        $config = self::$simpleConfig;
        $config['batched'] = true;
        $notifier = new RollbarNotifier($config);
        $this->assertEquals(0, $notifier->queueSize());
        
        $notifier->report_message("Hello world");
        $this->assertEquals(1, $notifier->queueSize());
        
        $notifier->flush();
        $this->assertEquals(0, $notifier->queueSize());
    }

    public function testMessageWithStaticPerson() {
        $config = self::$simpleConfig;
        $config['person'] = array('id' => '123', 'username' => 'example', 'email' => 'example@example.com');
        $notifier = m::mock('RollbarNotifier[send_payload]', array($config))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello world');

        $this->assertEquals('Hello world', $payload['data']['body']['message']['body']);
        $this->assertEquals('123', $payload['data']['person']['id']);
        $this->assertEquals('example', $payload['data']['person']['username']);
        $this->assertEquals('example@example.com', $payload['data']['person']['email']);

        $this->assertValidUUID($uuid);
    }
    
    public function testMessageWithDynamicPerson() {
        $config = self::$simpleConfig;
        $config['person_fn'] = 'dummy_rollbar_person_fn';
        $notifier = m::mock('RollbarNotifier[send_payload]', array($config))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello world');

        $this->assertEquals('Hello world', $payload['data']['body']['message']['body']);
        $this->assertEquals('456', $payload['data']['person']['id']);
        $this->assertEquals('dynamic', $payload['data']['person']['username']);
        $this->assertEquals('dynamic@example.com', $payload['data']['person']['email']);
        
        $this->assertValidUUID($uuid);
    }

    public function testExceptionWithInvalidConfig() {
        $config = self::$simpleConfig;
        $config['access_token'] = 'hello';
        $notifier = new RollbarNotifier($config);

        $result = 'dummy';
        try {
            throw new Exception("test exception");
        } catch (Exception $e) {
            $result = $notifier->report_exception($e);
        }

        $this->assertNull($result);
    }

    public function testErrorWithoutCaptureBacktrace() {
        $config = self::$simpleConfig;
        $config['capture_error_backtraces'] = false;
        $notifier = new RollbarNotifier($config);

        $uuid = $notifier->report_php_error(E_WARNING, 'Some warning', 'the_file.php', 2);
        $this->assertValidUUID($uuid);
    }

    public function testAgentBatched() {
        $config = self::$simpleConfig;
        $config['handler'] = 'agent';
        $config['batched'] = true;
        $notifier = new RollbarNotifier($config);

        $uuid = $notifier->report_message('Hello world');
        $this->assertValidUUID($uuid);

        $notifier->flush();

        $this->assertEquals(0, $notifier->queueSize());
    }
    
    public function testAgentNonbatched() {
        $config = self::$simpleConfig;
        $config['handler'] = 'agent';
        $config['batched'] = false;
        $notifier = new RollbarNotifier($config);

        $uuid = $notifier->report_message('Hello world');
        $this->assertValidUUID($uuid);

        $this->assertEquals(0, $notifier->queueSize());
    }
    
    public function testBlockingBatched() {
        $config = self::$simpleConfig;
        $config['batched'] = true;
        $notifier = new RollbarNotifier($config);

        $uuid = $notifier->report_message('Hello world');
        $this->assertValidUUID($uuid);

        $this->assertEquals(1, $notifier->queueSize());

        $notifier->flush();

        $this->assertEquals(0, $notifier->queueSize());
    }
    
    public function testBlockingNonbatched() {
        $config = self::$simpleConfig;
        $config['batched'] = false;
        $notifier = new RollbarNotifier($config);

        $uuid = $notifier->report_message('Hello world');
        $this->assertValidUUID($uuid);

        $this->assertEquals(0, $notifier->queueSize());
    }

    public function testFlushAtBatchSize() {
        $config = self::$simpleConfig;
        $config['batched'] = true;
        $config['batch_size'] = 2;
        
        $notifier = new RollbarNotifier($config);

        $notifier->report_message("one");
        $this->assertEquals(1, $notifier->queueSize());

        $notifier->report_message("two");
        $this->assertEquals(2, $notifier->queueSize());
        
        $notifier->report_message("three");
        $this->assertEquals(1, $notifier->queueSize());
    }

    public function testExceptionWithExtraAndPayloadData() {
        $notifier = m::mock('RollbarNotifier[send_payload]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = null;
        try {
            throw new Exception("test");
        } catch (Exception $e) {
            $uuid = $notifier->report_exception($e, array('this_is' => 'extra'), array('title' => 'custom title'));
        }
        
        $this->assertEquals('extra', $payload['data']['body']['trace']['extra']['this_is']);
        $this->assertEquals('custom title', $payload['data']['title']);

        $this->assertValidUUID($uuid);
    }

    public function testExceptionWithPreviousExceptions()
    {
        $first = new Exception('First exception');
        $second = new Exception('Second exception', null, $first);
        $third = new Exception('Third exception', null, $second);

        $notifier = m::mock('RollbarNotifier[send_payload]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')
            ->once()
            ->passthru()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_exception($third, array('this_is' => 'extra'));
        $chain = isset($payload['data']['body']['trace_chain']) ? $payload['data']['body']['trace_chain'] : null;

        $this->assertValidUUID($uuid);
        $this->assertInternalType('array', $chain);
        $this->assertEquals(3, count($chain));
        $this->assertEquals($third->getMessage(), $chain[0]['exception']['message']);
        $this->assertEquals($second->getMessage(), $chain[1]['exception']['message']);
        $this->assertEquals($first->getMessage(), $chain[2]['exception']['message']);
        $this->assertEquals('extra', $chain[0]['extra']['this_is']);
    }

    public function testMessageWithExtraAndPayloadData() {
        $notifier = m::mock('RollbarNotifier[send_payload]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello', 'info', array('extra_key' => 'extra_val'), 
            array('title' => 'custom title', 'level' => 'warning'));

        $this->assertEquals('Hello', $payload['data']['body']['message']['body']);
        $this->assertEquals('warning', $payload['data']['level']);  // payload data takes precedence
        $this->assertEquals('extra_val', $payload['data']['body']['message']['extra_key']);
        $this->assertEquals('custom title', $payload['data']['title']);

        $this->assertValidUUID($uuid);
    }

    public function testMessageWithRequestData() {
        $_GET = array('get_key' => 'get_value');
        $_POST = array(
            'post_key' => 'post_value',
            'password' => 'hunter2',
            'something_special' => 'excalibur'
        );
        $_SESSION = array('session_key' => 'session_value');
        $_SERVER = array(
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/example.php',
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '127.0.0.1'
        );

        $config = self::$simpleConfig;
        $config['scrub_fields'] = array('password', 'something_special');
        
        $notifier = m::mock('RollbarNotifier[send_payload]', array($config))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello');

        $this->assertEquals('get_value', $payload['data']['request']['GET']['get_key']);
        $this->assertEquals('post_value', $payload['data']['request']['POST']['post_key']);
        $this->assertEquals('*******', $payload['data']['request']['POST']['password']);
        $this->assertEquals('*********', $payload['data']['request']['POST']['something_special']);
        $this->assertEquals('session_value', $payload['data']['request']['session']['session_key']);
        $this->assertEquals('http://example.com/example.php', $payload['data']['request']['url']);
        $this->assertEquals('POST', $payload['data']['request']['method']);
        $this->assertEquals('127.0.0.1', $payload['data']['request']['user_ip']);
        $this->assertEquals('example.com', $payload['data']['request']['headers']['Host']);
    }

    public function testParamScrubbing() {
        $_GET = array(
            'get_key' => 'get_value',
            'auth_token' => '12345',
            'client_password' => 'hunter2',
        );
        $_POST = array(
            'post_key' => 'post_value',
            'PASSWORD' => 'hunter2',
            'something_special' => 'excalibur',
            'array_token' => array(
                'secret_key' => 'secret_value'
            ),
            'array_key' => array(
                'subarray_key' => 'subarray_value',
                'subarray_password' => 'hunter2',
                'something_special' => 'excalibur'
            )
        );
        $_SESSION = array(
            'session_key' => 'session_value',
            'SeSsIoN_pAssWoRd' => 'hunter2'
        );
        $_SERVER = array(
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/example.php',
            'REQUEST_METHOD' => 'POST',
            'HTTP_PASSWORD' => 'hunter2',
            'HTTP_AUTH_TOKEN' => '12345',
            'REMOTE_ADDR' => '127.0.0.1'
        );

        $config = self::$simpleConfig;
        $config['scrub_fields'] = array('something_special', '/token|password/i');
        
        $notifier = m::mock('RollbarNotifier[send_payload]', array($config))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello');

        $this->assertSame(array(
            'get_key' => 'get_value',
            'auth_token' => '*****',
            'client_password' => '*******',
        ), $payload['data']['request']['GET']);

        $this->assertSame(array(
            'post_key' => 'post_value',
            'PASSWORD' => '*******',
            'something_special' => '*********',
            'array_token' => '*',
            'array_key' => array(
                'subarray_key' => 'subarray_value',
                'subarray_password' => '*******',
                'something_special' => '*********',
            )
        ), $payload['data']['request']['POST']);

        $this->assertSame(array(
            'session_key' => 'session_value',
            'SeSsIoN_pAssWoRd' => '*******',
        ), $payload['data']['request']['session']);

        $this->assertSame(array(
            'Host' => 'example.com',
            'Password' => '*******',
            'Auth-Token' => '*****',
        ), $payload['data']['request']['headers']);
    }

    public function testServerBranchDefaultsEmpty() {
        $config = self::$simpleConfig;

        $notifier = m::mock('RollbarNotifier[send_payload]', array($config))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello');

        $this->assertFalse(isset($payload['data']['server']['branch']));
    }

    public function testServerBranchConfig() {
        $config = self::$simpleConfig;
        $config['branch'] = 'my-branch';

        $notifier = m::mock('RollbarNotifier[send_payload]', array($config))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('send_payload')->once()
            ->with(m::on(function($input) use (&$payload) {
                $payload = $input;
                return true;
            }));

        $uuid = $notifier->report_message('Hello');

        $this->assertEquals($payload['data']['server']['branch'], 'my-branch');
    }

    /* --- Internal exceptions --- */

    public function testInternalExceptionInReportException() {
        $notifier = m::mock('RollbarNotifier[_report_exception,log_error]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('_report_exception')->once()->andThrow(new Exception("internal error"));
        $notifier->shouldReceive('log_error')->once();

        $uuid = 'dummy';
        try {
            throw new Exception("test");
        } catch (Exception $e) {
            $uuid = $notifier->report_exception($e);
        }

        $this->assertNull($uuid);
    }
    
    public function testInternalExceptionInReportMessage() {
        $notifier = m::mock('RollbarNotifier[_report_message,log_error]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('_report_message')->once()->andThrow(new Exception("internal error"));
        $notifier->shouldReceive('log_error')->once();

        $uuid = $notifier->report_message("hello");
        $this->assertNull($uuid);
    }
    
    public function testInternalExceptionInReportPhpError() {
        $notifier = m::mock('RollbarNotifier[_report_php_error,log_error]', array(self::$simpleConfig))
            ->shouldAllowMockingProtectedMethods();
        $notifier->shouldReceive('_report_php_error')->once()->andThrow(new Exception("internal error"));
        $notifier->shouldReceive('log_error')->once();

        $uuid = $notifier->report_php_error(E_NOTICE, "Some notice", "the_file.php", 123);
        $this->assertNull($uuid);
    }
    

    /* --- Helper methods --- */

    private function assertValidUUID($uuid) {
        $this->assertStringMatchesFormat('%x-%x-%x-%x-%x', $uuid);
    }



}

function dummy_rollbar_person_fn() {
    return array('id' => 456, 'username' => 'dynamic', 'email' => 'dynamic@example.com');
}

?>
