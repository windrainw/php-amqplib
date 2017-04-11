<?php
namespace PhpAmqpLib\Wire\IO;

use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Helper\MiscHelper;
use PhpAmqpLib\Wire\AMQPWriter;

class StreamIO extends AbstractIO
{
    /** @var string */
    protected $protocol;

    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

    /** @var int */
    protected $connection_timeout;

    /** @var int */
    protected $read_write_timeout;

    /** @var int 毫秒*/
    protected $whole_read_write_timeout;

    /** @var resource */
    protected $context;

    /** @var bool */
    protected $keepalive;

    /** @var int */
    protected $heartbeat;

    /** @var float */
    protected $last_read;

    /** @var float */
    protected $last_write;

    /** @var array */
    protected $last_error;

    /** @var resource */
    private $sock;

    /** @var bool */
    private $canSelectNull;

    /** @var bool */
    private $canDispatchPcntlSignal;

    /**
     * @param string $host
     * @param int $port
     * @param int $connection_timeout
     * @param int $read_write_timeout
     * @param null $context
     * @param bool $keepalive
     * @param int $heartbeat
     * @param int $whole_read_write_timeout 读写总超时
     */
    public function __construct(
        $host,
        $port,
        $connection_timeout,
        $read_write_timeout,
        $context = null,
        $keepalive = false,
        $heartbeat = 0
    ) {
        $this->protocol = 'tcp';
        $this->host = $host;
        $this->port = $port;
        $this->connection_timeout = $connection_timeout;
        $this->read_write_timeout = $read_write_timeout;
        $this->whole_read_write_timeout = 100;
        $this->context = $context;
        $this->keepalive = $keepalive;
        $this->heartbeat = $heartbeat;
        $this->canSelectNull = true;
        $this->canDispatchPcntlSignal = $this->isPcntlSignalEnabled();

        if (is_null($this->context)) {
            $this->context = stream_context_create();
        } else {
            $this->protocol = 'ssl';
            // php bugs 41631 & 65137 prevent select null from working on ssl streams
            if (PHP_VERSION_ID < 50436) {
                $this->canSelectNull = false;
            }
        }
    }

    /**
     * @return bool
     */
    private function isPcntlSignalEnabled()
    {
        return extension_loaded('pcntl')
            && function_exists('pcntl_signal_dispatch')
            && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : true);
    }

    /**
     * Sets up the stream connection
     *
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException
     * @throws \Exception
     */
    public function connect()
    {
        $errstr = $errno = null;

        $remote = sprintf(
            '%s://%s:%s',
            $this->protocol,
            $this->host,
            $this->port
        );

        set_error_handler(array($this, 'error_handler'));

        try {
            $this->sock = stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $this->connection_timeout,
                STREAM_CLIENT_CONNECT,
                $this->context
            );
        } catch (\Exception $e) {
            restore_error_handler();
            throw $e;
        }

        restore_error_handler();

        if (false === $this->sock) {
            throw new AMQPRuntimeException(
                sprintf(
                    'Error Connecting to server(%s): %s ',
                    $errno,
                    $errstr
                ),
                $errno
            );
        }

        if (false === stream_socket_get_name($this->sock, true)) {
            throw new AMQPRuntimeException(
                sprintf(
                    'Connection refused: %s ',
                    $remote
                )
            );
        }

        list($sec, $uSec) = MiscHelper::splitSecondsMicroseconds($this->read_write_timeout);
        if (!stream_set_timeout($this->sock, $sec, $uSec)) {
            throw new AMQPIOException('Timeout could not be set');
        }

        // php cannot capture signals while streams are blocking
        if ($this->canDispatchPcntlSignal) {
            stream_set_blocking($this->sock, 0);
            stream_set_write_buffer($this->sock, 0);
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($this->sock, 0);
            }
        } else {
            stream_set_blocking($this->sock, 1);
        }

        if ($this->keepalive) {
            $this->enable_keepalive();
        }
    }

    /**
     * Reconnects the socket
     */
    public function reconnect()
    {
        $this->close();
        $this->connect();
    }

    /**
     * @param $len
     * @throws \PhpAmqpLib\Exception\AMQPIOException
     * @return mixed|string
     */
    public function read($len)
    {
        $read = 0;
        $data = '';

        $start = microtime(TRUE); 
        $blockSelected = FALSE;
        while ($read < $len) {
            $spendTime = (microtime(TRUE) - $start) * 1000;
            if($spendTime > $this->whole_read_write_timeout){
                throw new AMQPTimeoutException('read data time out for whole_read_write_timeout, used:' . $spendTime);
            }

            $this->check_heartbeat();

            if (!is_resource($this->sock) || feof($this->sock)) {
                throw new AMQPRuntimeException('Broken pipe or closed connection');
            }

            set_error_handler(array($this, 'error_handler'));
            $buffer = fread($this->sock, ($len - $read));
            restore_error_handler();

            if ($buffer === false) {
                throw new AMQPRuntimeException('Error receiving data');
            }

            if ($buffer === '') {
                if ($this->canDispatchPcntlSignal) {
                    // prevent cpu from being consumed while waiting
                    if ($this->canSelectNull) {
                        $selectTimeoutSeconds = 10;
                        $startSelectTime = microtime(TRUE);
                        $selectResult = $this->select($selectTimeoutSeconds, null);
                        $endSelectTime = microtime(TRUE);
                        $selectSpendTime = $endSelectTime - $startSelectTime;
                        if($selectSpendTime > ($selectTimeoutSeconds - 2)){
                            $scriptName = $_SERVER['argv']['0'];
                            if(preg_match(";(write_like_to_cache|write_like_to_db|market_activity);", $scriptName)){
                                $backtrace = debug_backtrace();
                                $traceArray = $this->generateTrace($backtrace);
                                $pid = posix_getpid();
                                $lsofInfo = shell_exec("lsof -p $pid | grep -P \"\d+u\"");
                                $metaData = stream_get_meta_data($this->sock);
                                $log = array(
                                    'type'              => 'direct_select',
                                    'scriptName'        => $scriptName,
                                    'logTime'           => date("Y-m-d H:i:s"),
                                    'startSelectTime'   => date("Y-m-d H:i:s", $startSelectTime),
                                    'endSelectTime'     => date("Y-m-d H:i:s", $endSelectTime),
                                    'pid'               => $pid,
                                    'traceInfo'         => $traceArray,
                                    'lsofInfo'          => $lsofInfo,
                                    'metaData'          => $metaData,
                                );
                                $logInfo = json_encode($log, JSON_UNESCAPED_UNICODE);
                                error_log($logInfo.PHP_EOL, 3, "/home/nice/coolly/var/logs/mq_select_trace.log-" . date("Y-m-d"));
                                $blockSelected = TRUE;
                            }
                        }

                        pcntl_signal_dispatch();

                        if($blockSelected){
                           $backtrace = debug_backtrace();
                           $traceArray = $this->generateTrace($backtrace);
                           $pid = posix_getpid();
                           $lsofInfo = shell_exec("lsof -p $pid | grep -P \"\d+u\"");
                           $metaData = stream_get_meta_data($this->sock);
                           $log = array(
                               'type'              => 'after_signal',
                               'scriptName'        => $scriptName,
                               'logTime'           => date("Y-m-d H:i:s"),
                               'startSelectTime'   => date("Y-m-d H:i:s", $startSelectTime),
                               'endSelectTime'     => date("Y-m-d H:i:s", $endSelectTime),
                               'pid'               => $pid,
                               'traceInfo'         => $traceArray,
                               'lsofInfo'          => $lsofInfo,
                               'metaData'          => $metaData,
                           );
                           $logInfo = json_encode($log, JSON_UNESCAPED_UNICODE);
 
                            error_log($logInfo.PHP_EOL, 3, "/home/nice/coolly/var/logs/mq_select_trace.log-" . date("Y-m-d"));
                        }
                        if(intval($selectResult) <= 0){
                            throw new AMQPIOException('select timeout, used:' . $selectSpendTime);
                        }
                    } else {
                        usleep(100000);
                        pcntl_signal_dispatch();
                    }
                }
                continue;
            }

            $read += mb_strlen($buffer, 'ASCII');
            $data .= $buffer;
        }

        if (mb_strlen($data, 'ASCII') !== $len) {
            throw new AMQPRuntimeException(
                sprintf(
                    'Error reading data. Received %s instead of expected %s bytes',
                    mb_strlen($data, 'ASCII'),
                    $len
                )
            );
        }

        $this->last_read = microtime(true);
        return $data;
    }

    public function generateTrace($backtrace){
        $traceArray = array();
        $context      = $backtrace;
        $maxDepth     = count($context);
        $currentDepth = min(max(0, $depth), $maxDepth);
        $parentDepth  = min($depth + 1, $maxDepth);
        
        for ($i = $parentDepth; $i < $maxDepth; $i ++) {
            if (!isset($context[$i])) {
                break;
            }
            $file = isset($context[$i]['file']) ? $context[$i]['file'] : '';
            $line = isset($context[$i]['line']) ? $context[$i]['line'] : '';
            $class = isset($context[$i]['class']) ? $context[$i]['class'] : '';
            $type = isset($context[$i]['type']) ? $context[$i]['type'] : '';
            $function = isset($context[$i]['function']) ? $context[$i]['function'] : '';
            $args = '';
            if (is_array($context[$i]['args']) && !empty($context[$i]['args'])) {
                $argsArr = array();
                foreach ($context[$i]['args'] as $key => $arg) {
                    if ($arg instanceof Exception) { //读取Exception中的trace信息
                        $exception_trace = $arg->getTrace();
                        if (is_array($exception_trace) && !empty($exception_trace)) {
                            foreach ($exception_trace as $trace) {
                                array_push($context, $trace);
                                $maxDepth ++;
                            }
                        }
                    } else if (is_object($arg)) {
                        $argsArr[$key] = 'object';
                    } else if (is_array($arg)) {
                        $argsArr[$key] = 'array';
                    } else {
                        if (strlen($arg) > 5000) {
                            $arg = substr($arg, 0, 5000);
                        }
                        $argsArr[$key] = strval($arg);
                    }
                }
                $args = "('" . implode("','", $argsArr) . "')";
            }
            
            $traceArray[] =  $file . ':' . $line . ' ' . $class . $type . $function . $args;
        }
        return $traceArray;
    }

    /**
     * @param $data
     * @return mixed|void
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException
     * @throws \PhpAmqpLib\Exception\AMQPTimeoutException
     */
    public function write($data)
    {
        $written = 0;
        $len = mb_strlen($data, 'ASCII');
        $start = microtime(TRUE); 
        while ($written < $len) {
            $spendTime = (microtime(TRUE) - $start) * 1000;
            if($spendTime > $this->whole_read_write_timeout){
                throw new AMQPRuntimeException('write data time out for whole_read_write_timeout, used:' . $spendTime);
            }

            if (!is_resource($this->sock)) {
                throw new AMQPRuntimeException('Broken pipe or closed connection');
            }

            set_error_handler(array($this, 'error_handler'));
            // OpenSSL's C library function SSL_write() can balk on buffers > 8192
            // bytes in length, so we're limiting the write size here. On both TLS
            // and plaintext connections, the write loop will continue until the
            // buffer has been fully written.
            // This behavior has been observed in OpenSSL dating back to at least
            // September 2002:
            // http://comments.gmane.org/gmane.comp.encryption.openssl.user/4361
            $buffer = fwrite($this->sock, $data, 8192);
            restore_error_handler();

            if ($buffer === false) {
                throw new AMQPRuntimeException('Error sending data');
            }

            if ($buffer === 0 && feof($this->sock)) {
                throw new AMQPRuntimeException('Broken pipe or closed connection');
            }

            if ($this->timed_out()) {
                throw new AMQPTimeoutException('Error sending data. Socket connection timed out');
            }

            $written += $buffer;

            if ($buffer > 0) {
                $data = mb_substr($data, $buffer, mb_strlen($data, 'ASCII') - $buffer, 'ASCII');
            }
        }

        $this->last_write = microtime(true);
    }

    /**
     * Internal error handler to deal with stream and socket errors that need to be ignored
     *
     * @param  int $errno
     * @param  string $errstr
     * @param  string $errfile
     * @param  int $errline
     * @param  array $errcontext
     * @return null
     * @throws \ErrorException
     */
    public function error_handler($errno, $errstr, $errfile, $errline, $errcontext = null)
    {
        $this->last_error = compact('errno', 'errstr', 'errfile', 'errline', 'errcontext');

        // fwrite notice that the stream isn't ready
        if (strstr($errstr, 'Resource temporarily unavailable')) {
             // it's allowed to retry
            return null;
        }

        // stream_select warning that it has been interrupted by a signal
        if (strstr($errstr, 'Interrupted system call')) {
             // it's allowed while processing signals
            return null;
        }

        restore_error_handler();

        // raise all other issues to exceptions
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Heartbeat logic: check connection health here
     */
    protected function check_heartbeat()
    {
        // ignore unless heartbeat interval is set
        if ($this->heartbeat !== 0 && $this->last_read && $this->last_write) {
            $t = microtime(true);
            $t_read = round($t - $this->last_read);
            $t_write = round($t - $this->last_write);

            // server has gone away
            if (($this->heartbeat * 2) < $t_read) {
                $this->reconnect();
            }

            // time for client to send a heartbeat
            if (($this->heartbeat / 2) < $t_write) {
                $this->write_heartbeat();
            }
        }
    }

    /**
     * Sends a heartbeat message
     */
    protected function write_heartbeat()
    {
        $pkt = new AMQPWriter();
        $pkt->write_octet(8);
        $pkt->write_short(0);
        $pkt->write_long(0);
        $pkt->write_octet(0xCE);
        $this->write($pkt->getvalue());
    }

    public function close()
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
        $this->sock = null;
    }

    /**
     * @return resource
     */
    public function get_socket()
    {
        return $this->sock;
    }

    /**
     * @return resource
     */
    public function getSocket()
    {
        return $this->get_socket();
    }

    /**
     * @param $sec
     * @param $usec
     * @return int|mixed
     */
    public function select($sec, $usec)
    {
        $read = array($this->sock);
        $write = null;
        $except = null;
        $result = false;

        set_error_handler(array($this, 'error_handler'));
        $result = stream_select($read, $write, $except, $sec, $usec);
        restore_error_handler();

        return $result;
    }

    /**
     * @return mixed
     */
    protected function timed_out()
    {
        // get status of socket to determine whether or not it has timed out
        $info = stream_get_meta_data($this->sock);

        return $info['timed_out'];
    }

    /**
     * @throws \PhpAmqpLib\Exception\AMQPIOException
     */
    protected function enable_keepalive()
    {
        if (!function_exists('socket_import_stream')) {
            throw new AMQPIOException('Can not enable keepalive: function socket_import_stream does not exist');
        }

        if (!defined('SOL_SOCKET') || !defined('SO_KEEPALIVE')) {
            throw new AMQPIOException('Can not enable keepalive: SOL_SOCKET or SO_KEEPALIVE is not defined');
        }

        $socket = socket_import_stream($this->sock);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
    }

    /**
     * @return $this
     */
    public function disableHeartbeat()
    {
        $this->heartbeat = 0;

        return $this;
    }
}
