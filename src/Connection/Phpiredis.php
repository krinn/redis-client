<?php
/**
 * Created by PhpStorm.
 * User: sebakpl
 * Date: 21/03/15
 * Time: 12:39
 */

namespace Redis\Connection;

use Redis\Algorithms;

/**
 * Class Phpiredis
 * @package Redis\Connection
 */
class Phpiredis implements ConnectionInterface
{

    /**
     * @var array
     */
    protected static $connections = array();
    /**
     * @var Algorithms\AlgorithmsInterface
     */
    protected $hashingInterface;
    /**
     * @var
     */
    protected $startingPort;
    /**
     * @var
     */
    protected $masterInstances;

    /**
     * @var int
     */
    protected $slaves;

    /**
     * @param $value
     * @return bool
     */
    private function _checkIfMoved($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $msg = explode(" ", $value);

        if ($msg[0] === 'MOVED') {
            $msg = explode(':', $msg[2]);
            return $msg[1];
        }

        return false;
    }

    /**
     * @param $instance
     * @param $cmd
     * @return mixed
     */
    private function _singleCmd($instance, $cmd)
    {

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            restore_error_handler();
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
                die();
            }
        });

        if ($instance) {

            $value = phpiredis_command_bs($instance, $cmd);

            if (!is_null($value)) {

                if (isset($value[0])) {
                    $ip = $this->_checkIfMoved($value[0]);
                }

                if (!$ip) {
                    restore_error_handler();
                    return $value;
                }

            }
        }

        if ($ip) {
            $parts = explode(":", $ip);
            $port = array_pop($parts);
            $instance = $this->getInstanceByPort($port);
            if ($instance && $value = phpiredis_command_bs($instance, $cmd)) {
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param $instance
     * @param array $cmdRecs
     * @return mixed
     */
    private function _multiCmdPerInstance($instance, $cmdRecs = array())
    {

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            restore_error_handler();
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
                die();
            }
        });

        $order = array();
        $cmd = array();
        foreach ($cmdRecs as $r) {
            $order[] = $r['order'];
            $cmd[] = $r['cmd'];
        }

        $sort = function ($values) use ($order) {
            $return = array();

            foreach ($values as $idx => $value) {
                $return[$order[$idx]] = $value;
            }

            return $return;
        };

        if ($instance && $value = phpiredis_multi_command_bs($instance, $cmd)) {
            if (isset($value[0])) {
                $ip = $this->_checkIfMoved($value[0]);
            }

            if (!$ip) {
                restore_error_handler();
                return $sort($value);
            }
        }

        if ($ip) {

            $instance = $this->getInstanceByPort($ip);
            if ($instance && $value = phpiredis_multi_command_bs($instance, $cmd)) {
                restore_error_handler();
                return $sort($value);
            }

        }

        restore_error_handler();
        return false;
    }

    public function singleCmd(array $cmd) {
        $key = $cmd[1];
        $slot = $this->getSlot($key);
        $port = $this->getPortBySlot($slot, $this->startingPort, $this->masterInstances);
        $instance = $this->getInstanceByPort($port);
        return $this->_singleCmd($instance, $cmd);
    }

    /**
     * @param Algorithms\AlgorithmsInterface $hashingInterface
     * @param $startingPort
     * @param $masterInstances
     * @param $slaves
     */
    public function __construct(Algorithms\AlgorithmsInterface $hashingInterface, $startingPort, $masterInstances, $slaves = 0)
    {
        $this->hashingInterface = $hashingInterface;
        $this->startingPort = $startingPort;
        $this->masterInstances = $masterInstances;
        $this->slaves = $slaves;
    }

    /**
     * @param $port
     * @return bool
     */
    public function connect($port)
    {
        $conn = self::$connections;

        if (!isset($conn[$port])) {
            $redis = phpiredis_connect('127.0.0.1', $port);
            $conn[$port] = $redis;
            self::$connections[$port] = $redis;
        }

        return $conn[$port] ?: false;
    }

    /**
     * @param $key
     * @return bool
     */
    public function read($key)
    {

        $instance = $this->getInstanceBySlot(
            $this->getSlot($key),
            $this->startingPort,
            $this->masterInstances
        );

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
            }
        });

        if ($instance && $value = phpiredis_command_bs($instance, array('GET', $key))) {
            restore_error_handler();
            return $value;
        }

        if ($ip) {
            $parts = explode(":", $ip);
            $port = array_pop($parts);
            $instance = $this->getInstanceByPort($port);

            if ($instance && $value = phpiredis_command_bs($instance, array('GET', $key))) {
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param $key
     * @param $value
     * @param $cacheTime
     * @return bool
     */
    public function write($key, $value, $cacheTime = false)
    {

        $slot = $this->getSlot($key);
        $instance = $this->getInstanceBySlot(
            $slot,
            $this->startingPort,
            $this->masterInstances
        );

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
            }
        });

        $cmd = array('SET', $key, '' . $value);

        if ($cacheTime) {
            $cmd[] = 'EX';
            $cmd[] = '' . $cacheTime;
        }

        if ($instance && $value = phpiredis_command_bs($instance, $cmd)) {
            restore_error_handler();
            return $value;
        }

        if ($ip) {
            $parts = explode(":", $ip);
            $port = array_pop($parts);
            $instance = $this->getInstanceByPort($port);
            if ($instance && $value = phpiredis_command_bs($instance, $cmd)) {
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param $key
     * @param array $fields
     * @return bool
     */
    public function hmRead($key, array $fields = array())
    {

        $instance = $this->getInstanceBySlot(
            $this->getSlot($key),
            $this->startingPort,
            $this->masterInstances
        );

        $tmp = array('HMGET', $key);
        while ($r = array_shift($fields)) {
            $tmp[] = $r;
        }

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
            }
        });
        if ($instance && $value = phpiredis_command_bs($instance, $tmp)) {
            restore_error_handler();
            return $value;
        }

        if ($ip) {
            $parts = explode(":", $ip);
            $port = array_pop($parts);
            $instance = $this->getInstanceByPort($port);
            if ($instance && $value = phpiredis_command_bs($instance, $tmp)) {
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param $key
     * @param array $fields
     * @param array $values
     * @return bool
     */
    public function hmWrite($key, array $fields = array(), array $values = array())
    {

        $instance = $this->getInstanceBySlot(
            $this->getSlot($key),
            $this->startingPort,
            $this->masterInstances
        );

        $tmp = array('HMSET', $key);
        while ($r = array_shift($fields)) {
            $tmp[] = $r;
            $tmp[] = array_shift($values);
        }

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
            }
        });

        if ($instance && $value = phpiredis_command_bs($instance, $tmp)) {
            restore_error_handler();
            return $value;
        }

        if ($ip) {
            $parts = explode(":", $ip);
            $port = array_pop($parts);
            $instance = $this->getInstanceByPort($port);
            if ($instance && $value = phpiredis_command_bs($instance, $tmp)) {
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param $key
     * @param array $fields
     * @return bool
     */
    public function hmRemove($key, array $fields = array())
    {

        $instance = $this->getInstanceBySlot(
            $this->getSlot($key),
            $this->startingPort,
            $this->masterInstances
        );

        if (count($fields) > 0) {
            $tmp = array('HDEL', $key);
        } else {
            $tmp = array('DEL', $key);
        }

        while ($r = array_shift($fields)) {
            $tmp[] = $r;
        }

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$ip) {
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $ip = $msg[3];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
            }
        });

        if ($instance) {
            $value = phpiredis_command_bs($instance, $tmp);
            if (isset($value) && !isset($ip)) {
                restore_error_handler();
                return $value;
            }
        }

        if ($ip) {

            $parts = explode(":", $ip);
            $port = array_pop($parts);
            $instance = $this->getInstanceByPort($port);
            if ($instance) {
                $value = phpiredis_command_bs($instance, $tmp);
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param $slot
     * @param $startingPort
     * @param $masterInstances
     * @return bool
     */
    public function getInstanceBySlot($slot, $startingPort, $masterInstances)
    {
        $instance = floor(($slot % 16384) / (16384 / $masterInstances));
        return $this->connect($startingPort + $instance);
    }

    /**
     * @param $slot
     * @param $startingPort
     * @param $masterInstance
     * @return mixed
     */
    public function getPortBySlot($slot, $startingPort, $masterInstance)
    {
        return $startingPort + floor(($slot % 16384) / (16384 / $masterInstance));
    }

    /**
     * @param $port
     * @return bool
     */
    public function getInstanceByPort($port)
    {
        return $this->connect($port);
    }

    /**
     * @param $key
     * @param $startingPort
     * @return bool
     */
    public function getInstanceBySlotMap($key, $startingPort)
    {
        $slot = $this->getSlot($key);
        //todo
        $instance = 0;
        return $this->connect($startingPort + $instance);
    }

    /**
     * @param $key
     * @return int
     */
    public function getSlot($key)
    {

        if (false !== $start = strpos($key, '{')) {
            if (false !== ($end = strpos($key, '}', $start)) && $end !== ++$start) {
                $key = substr($key, $start, $end - $start);
            }
        }
        $hash = $this->hashingInterface->hash($key);
        return $hash & 0x3FFF;
    }

    /**
     * @param $startingPort
     */
    protected function getSlotMap($startingPort)
    {
        $instance = $this->getInstanceByPort($startingPort);
        $resp = phpiredis_command_bs($instance, array('cluster', 'slots'));
        //todo:
    }

    /**
     * @param $key
     * @param $match
     * @return mixed
     */
    public function hScan($key, $match)
    {
        $instance = $this->getInstanceBySlot(
            $this->getSlot($key),
            $this->startingPort,
            $this->masterInstances
        );

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$moved) {
            $msg = explode(" ", $errstr);
            if ($msg[1] === 'MOVED') {
                $moved = $msg[2];
            } else {
                echo "\r\n// " . join(", ", array($errstr, 0, $errno, $errfile, $errline));
            }
        });

        $tmp = array('HSCAN', $key, 'MATCH', $match);
        if ($instance && $value = phpiredis_command_bs($instance, $tmp)) {
            if (isset($value)) {
                $moved = $this->_checkIfMoved($value[0]);
            }

            if (!$moved) {
                restore_error_handler();
                return $value;
            }
        }

        if ($moved) {

            $instance = $this->getInstanceByPort($moved);
            if ($instance && $value = phpiredis_command_bs($instance, $tmp)) {
                restore_error_handler();
                return $value;
            }

        }

        restore_error_handler();
        return false;
    }

    /**
     * @param array $cmd
     * @return array
     */
    public function multiCmd(array $cmd = array())
    {
        $instances = array();
        $values = array();

        foreach ($cmd as $idx => $c) {
            $key = $c[1];
            $slot = $this->getSlot($key);
            $port = $this->getPortBySlot($slot, $this->startingPort, $this->masterInstances);
            $instances[$port] = isset($instances[$port]) ? $instances[$port] : array();
            $instances[$port][] = array('order' => $idx, 'cmd' => $c);
        }

        foreach ($instances as $port => $_cmd) {
            $instance = $this->getInstanceByPort($port);
            $tmp = $this->_multiCmdPerInstance($instance, $_cmd);
            $values = $values + $tmp;
        }

        return $values;
    }

    /**
     *
     */
    public static function close()
    {
        $conn = self::$connections;
        foreach ($conn as $idx => $c) {
            unset(self::$connections[$idx]);
        }
    }

    /**
     * @param $key
     * @param $value
     * @return mixed
     */
    public function push($key, $value)
    {
        $slot = $this->getSlot($key);
        $port = $this->getPortBySlot($slot, $this->startingPort, $this->masterInstances);
        $instance = $this->getInstanceByPort($port);
        return $this->_singleCmd($instance, array("LPUSH", $key, "" . $value));
    }

    /**
     * @param $key
     * @return mixed
     */
    public function pop($key)
    {
        $slot = $this->getSlot($key);
        $port = $this->getPortBySlot($slot, $this->startingPort, $this->masterInstances);
        $instance = $this->getInstanceByPort($port);
        return $this->_singleCmd($instance, array("LPOP", $key));
    }

    /**
     * @param $key
     * @param bool $remove
     * @return mixed
     */
    public function getFullList($key, $remove = false)
    {

        $slot = $this->getSlot($key);
        $port = $this->getPortBySlot($slot, $this->startingPort, $this->masterInstances);
        $instance = $this->getInstanceByPort($port);
        $values = $this->_singleCmd($instance, array("LRANGE", $key, "0", "-1"));

        if ($remove) {
            $this->_singleCmd($instance, array("DEL", $key));
        }

        return $values;

    }

    /**
     * @param $key
     * @param array $list
     * @return mixed
     */
    public function pushFullList($key, array $list)
    {

        $slot = $this->getSlot($key);
        $port = $this->getPortBySlot($slot, $this->startingPort, $this->masterInstances);
        $instance = $this->getInstanceByPort($port);
        $values = array_merge(array("LPUSH", $key), $list);

        return $this->_singleCmd($instance, $values);

    }

    public function info($section = "")
    {
        $cmd = array("INFO");
        $info = array();

        if (strlen($section) > 0) {
            $cmd[1] = " " . $section;
        }

        $port = $this->startingPort;
        $instances = $this->masterInstances;
        $max = $port + $instances;

        if ($this->slaves) {
            $max += $instances * $this->slaves;
        }

        $instance = $this->getInstanceByPort($port);
        $info[$port] = $this->_singleCmd($instance, $cmd);
        for (; $port < $max; $port++) {
            $instance = $this->getInstanceByPort($port);
            $info[$port] = $this->_singleCmd($instance, $cmd);
        }

        return $info;
    }

    public function keys()
    {
        $cmd = array("keys", "*");
        $info = array();

        $port = $this->startingPort;
        $instances = $this->masterInstances;
        $max = $port + $instances;

        $instance = $this->getInstanceByPort($port);
        $info[$port] = $this->_singleCmd($instance, $cmd);
        for (; $port < $max; $port++) {
            $instance = $this->getInstanceByPort($port);
            $info[$port] = $this->_singleCmd($instance, $cmd);
        }

        return $info;

    }
}