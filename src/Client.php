<?php

namespace ReactphpX\Bridge;

use React\Stream\DuplexStreamInterface;
use React\Socket\ConnectorInterface;
use React\Stream;
use SplObjectStorage;
use React\Promise\Deferred;
use Ramsey\Uuid\Uuid;
use React\Promise\Timer\TimeoutException;
use function React\Async\await;
use React\EventLoop\Loop;
use ReactphpX\Bridge\Info;
use ReactphpX\Bridge\DecodeEncode\WebsocketDecodeEncode;
use ReactphpX\Bridge\DecodeEncode\TcpDecodeEncode;
use ReactphpX\Bridge\Connector\WebsocketConnector;
use ReactphpX\Bridge\Connector\TcpConnector;

final class Client extends AbstractClient
{
    use \Evenement\EventEmitterTrait;

    public static $debug = false;
    public static $secretKey;

    private $uri;

    /**
     * @var DuplexStreamInterface
     */
    protected $controlConnection;
    protected SplObjectStorage $connections;
    protected SplObjectStorage $clients;

    private $uuidToDeferred = [];

    protected $connector;

    // 0 close 1 open 2 shutdown
    protected $status = 0;


    public function __construct($uri, $uuid)
    {
        parent::__construct($uuid);
        $this->connections = new SplObjectStorage;
        $this->clients = new SplObjectStorage;
        if (strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
        }
        $this->uri = $uri;
        $this->createConnector($uri);
    }

    private function createConnector($uri)
    {
        $scheme = 'tcp';
        if (\strpos($uri, '://') !== false) {
            $scheme = (string)\substr($uri, 0, \strpos($uri, '://'));
        }

        if ($scheme == 'ws' || $scheme == 'wss') {
            $this->setConnector(new WebsocketConnector($this));
            $this->setDecodeEncode(new WebsocketDecodeEncode);
        } else if ($scheme == 'tcp' || $scheme == 'tls' || $scheme == 'unix') {
            $this->setConnector(new TcpConnector($this));
            $this->setDecodeEncode(new TcpDecodeEncode);
        } else {
            throw new \InvalidArgumentException('unsupported scheme ' . $scheme. ' for uri ' . $uri);
        }
    }


    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        $msg = 'control';
        if (!$this->controlConnection) {
            echo 'controlConnection' . PHP_EOL;
            $this->controlConnection = $stream;
            $this->controlConnection->write($this->decodeEncode->encode([
                'cmd' => 'registerController',
                'uuid' => $this->uuid,
            ]));
            $this->emit('controlConnection', [$this->controlConnection]);
        } else {
            $msg = 'tunnel';
            echo 'tunnelConnection' . PHP_EOL;
            $stream->write($this->decodeEncode->encode([
                'cmd' => 'registerTunnel',
                'control_uuid' => $this->uuid,
                'uuid' => $info['uuid'],
            ]));
            $this->connections->attach($stream, new Info([
                'local_address' => $info['local_address'],
                'remote_address' => '',
                'decodeEncode' => new $this->decodeEncodeClass,
                'streams' => new SplObjectStorage,
                'uuidToStream' => new Info([])
            ]));
        }

        $timer = Loop::addPeriodicTimer(30, function () use ($stream, $msg) {
            $info = $this->clients[$stream];
            // 空闲去 ping
            if ((time() - $info['active_time']) > 30) {
                $this->ping($stream)->then(function () use ($msg) {
                    echo $msg . ' ping success' . PHP_EOL;
                }, function ($e) use ($stream, $msg) {
                    echo $msg . ' ping error ' . $e->getMessage() . PHP_EOL;
                    $stream->close();
                });
            }
           
        });

        $stream->on('close', function () use ($timer) {
            Loop::cancelTimer($timer);
        });
        $this->clients->attach($stream, new Info($info + [
            'decodeEncode' => new $this->decodeEncodeClass,
            'active_time' => time(),
        ]));
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        if ($this->clients->contains($stream)) {
            $this->clients[$stream]['active_time'] = time();
        }

        if ($this->controlConnection === $stream) {
            if ($messages = $this->decodeEncode->decode($msg)) {
                foreach ($messages as $message) {
                    $this->handleControlData($stream, $message);
                }
            }
        } elseif ($this->connections->contains($stream)) {
            if ($messages = $this->connections[$stream]['decodeEncode']->decode($msg)) {
                foreach ($messages as $message) {
                    $this->handleTunnelData($stream, $message);
                }
            }
        } else {
            $stream->close();
        }
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {

        if ($this->controlConnection === $stream) {
            $this->controlConnection = null;
            echo "controlConnection close retry after 3 second\n";
            if ($this->status != 2 && $this->status != 3) {
                $this->status = 0;
            }
            Loop::addTimer(3, function () {
                $this->start();
            });
        } elseif ($this->connections->contains($stream)) {
            echo 'tunnelConnection close' . PHP_EOL;
            $tunnelStreams = [];
            foreach ($this->connections[$stream]['streams'] as $tunnelStream) {
                $tunnelStreams[] = $tunnelStream;
            }
            foreach ($tunnelStreams as $tmpTunnelStream) {
                $tmpTunnelStream->close();
            }
            echo 'tunnelConnection closed' . PHP_EOL;
            $this->connections->detach($stream);
        }

        if ($this->clients->contains($stream)) {
            $this->clients->detach($stream);
        }

        if ($this->clients->count() == 0) {
            echo 'no clients, retry after 3 second' . PHP_EOL;
            if ($this->status != 2 && $this->status != 3) {
                $this->status = 0;
                $this->controlConnection = null;
                Loop::addTimer(3, function () {
                    $this->start();
                });
            }
        }

    }

    protected function ping($conn)
    {
        $deferred = new Deferred;
        $uuid = Uuid::uuid4()->toString();
        $conn->write($this->decodeEncode->encode([
            'cmd' => 'ping',
            'uuid' => $uuid,
        ]));
        $this->uuidToDeferred[$uuid] = $deferred;
        return \React\Promise\Timer\timeout($deferred->promise(), 3)->then(function () use ($uuid) {
            unset($this->uuidToDeferred[$uuid]);
        }, function ($e) use ($uuid) {
            unset($this->uuidToDeferred[$uuid]);
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection timed out'
                );
            }
            throw $e;
        });
    }

    public function onError(DuplexStreamInterface $stream, \Exception $e)
    {
        echo 'connect error ' . $e->getMessage() . PHP_EOL;
    }

    public function setConnector(ConnectorInterface $connector)
    {
        $this->connector = $connector;
    }

    protected function createTunnelConnection($uuid)
    {
        return $this->connector->connect($this->uri . '?uuid=' . $uuid);
    }

    private function handleControlData(DuplexStreamInterface $stream, $message)
    {
        if (!is_array($message)) {
            return;
        }

        if (self::$debug) {
            echo '====> controlMessage: <=====' . PHP_EOL;
            var_export($message);
            echo PHP_EOL;
        }

        $cmd = $message['cmd'] ?? '';
        $uuid = $message['uuid'] ?? '';
        if ($cmd == 'init') {
            $this->emit('success', [$message['data'] ?? []]);
        } else if ($cmd == 'controllerConnected') {
            $this->emit('controllerConnected', [$message['data'] ?? [], $stream]);
        } else if ($cmd == 'createTunnelConnection') {
            $this->createTunnelConnection($uuid);
        } else if ($message['cmd'] == 'ping') {
            $stream->write($this->decodeEncode->encode([
                'cmd' => 'pong',
                'uuid' => $message['uuid'],
            ]));
        } else if ($cmd == 'pong') {
            if (isset($this->uuidToDeferred[$uuid])) {
                $this->uuidToDeferred[$uuid]->resolve(true);
            }
        } elseif ($cmd === 'extend_cmd') {
            $this->emit('extend_cmd', [$message, $stream]);
        }
    }


    protected function handleTunnelData($conn, $message)
    {
        if (!is_array($message)) {
            return;
        }

        if (self::$debug) {
            echo '====> tunnelMessage: <=====' . PHP_EOL;
            var_export($message);
            echo PHP_EOL;
        }

        $decodeEncode = $this->connections[$conn]['decodeEncode'];
        $uuid = $message['uuid'];
        $cmd = $message['cmd'];
        if ($cmd == 'init') {
            $this->connections[$conn]['remote_address'] = $message['data']['remote_address'] ?? '';
        } else if ($cmd == 'callback') {
            $read = new Stream\ThroughStream;
            $write = new Stream\ThroughStream;
            $stream = new Stream\CompositeStream($read, $write);
            $this->connections[$conn]['streams']->attach($stream);
            $this->connections[$conn]['uuidToStream'][$uuid] = $stream;
            $write->on('data', function ($data) use ($uuid, $conn, $decodeEncode) {
                $conn->write($decodeEncode->encode([
                    'cmd' => 'data',
                    'uuid' => $uuid,
                    'data' => $data
                ]));
            });
            $write->on('end', function () use ($uuid, $conn, $stream, $decodeEncode) {
                if ($this->connections->contains($conn)) {
                    if ($this->connections[$conn]['streams']->contains($stream)) {

                        $this->connections[$conn]['streams']->detach($stream);
                        $conn->write($decodeEncode->encode([
                            'cmd' => 'end',
                            'uuid' => $uuid,
                        ]));
                    }
                }
            });

            $stream->on('error', function ($e) use ($uuid, $conn, $stream, $decodeEncode) {
                if ($this->connections->contains($conn)) {
                    // 主动关闭的
                    if ($this->connections[$conn]['streams']->contains($stream)) {
                        $this->connections[$conn]['streams']->detach($stream);
                        $conn->write($decodeEncode->encode([
                            'cmd' => 'error',
                            'uuid' => $uuid,
                            'data' => [
                                'message' => $e->getMessage(),
                                'code' => $e->getCode(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'trace' => $e->getTraceAsString(),
                            ]
                        ]));
                        $stream->close();
                    }
                }
            });

            $stream->on('close', function () use ($uuid, $conn, $stream, $decodeEncode) {
                if ($this->connections->contains($conn)) {
                    if ($this->connections[$conn]['streams']->contains($stream)) {
                        $this->connections[$conn]['streams']->detach($stream);
                        $conn->write($decodeEncode->encode([
                            'cmd' => 'close',
                            'uuid' => $uuid,
                        ]));
                    }
                    // $uuidToStream = $this->connections[$conn]['uuidToStream'];
                    unset($this->connections[$conn]['uuidToStream'][$uuid]);
                    // $this->connections[$conn]['uuidToStream'] = $uuidToStream;
                }
            });

            try {
                $event = $message['data']['event'] ?? '';
                if ($event) {
                    $this->emit($event, [$stream]);
                }
                $serialized = $message['data']['serialized'];
                $closure = SerializableClosure::unserialize($serialized, static::$secretKey);
                $r = $closure($stream, $this->connections[$conn]);
                if ($r instanceof \React\Promise\PromiseInterface) {
                    $r->then(function ($value) use ($stream) {
                        $stream->end($value);
                    }, function ($e) use ($stream) {
                        $stream->emit('error', [$e]);
                    });
                } elseif ($r !== $stream) {
                    $stream->end($r);
                }
            } catch (\Throwable $e) {
                $stream->emit('error', [$e]);
            }
        } else if (in_array($cmd, ['data', 'end', 'close', 'error'])) {
            if (isset($this->connections[$conn]['uuidToStream'][$uuid])) {
                $stream = $this->connections[$conn]['uuidToStream'][$uuid];
                if ($cmd == 'close') {
                    $this->connections[$conn]['streams']->detach($stream);
                    $stream->emit('end');
                    $stream->end();
                } else if ($cmd == 'error') {
                    $this->connections[$conn]['streams']->detach($stream);
                    $stream->emit('error', [new \Exception(is_array($message['data']) ? json_encode($message['data'], JSON_UNESCAPED_UNICODE) : $message['data'])]);
                    $stream->end();
                } else if ($cmd == 'end') {
                    $this->connections[$conn]['streams']->detach($stream);
                    $stream->emit('end');
                    $stream->end();
                } else if ($cmd == 'data') {
                    $stream->emit('data', [$message['data']]);
                }
            }
        } else if ($cmd == 'ping') {
            $conn->write($decodeEncode->encode([
                'cmd' => 'pong',
                'uuid' => $uuid,
            ]));
        } else if ($cmd == 'pong') {
            if (isset($this->uuidToDeferred[$uuid])) {
                $this->uuidToDeferred[$uuid]->resolve(true);
            }
        }
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function start()
    {
        if ($this->status == 1 || $this->status == 2 || $this->status == 3) {
            return;
        }

        $this->status = 3;
        $this->connector->connect($this->uri)->then(function ($stream) {
            $this->status = 1;
            return $stream;
        }, function ($e) {
            echo "connection failed " . $this->uri . "\n";
            echo $e->getMessage() . "\n";
            echo "retry after 3 second\n";
            Loop::addTimer(3, function () {
                $this->start();
            });
        });
    }

    public function stop()
    {
        $this->status = 2;
        if ($this->controlConnection) {
            $this->controlConnection->close();
        }
        foreach ($this->connections as $conn) {
            $conn->close();
        }
    }

    public function call($closure, $params = null, $data = [])
    {

        echo "call\n";
        echo "params: ";
        var_export($params);
        echo "\n";
        echo "data: ";
        var_export($data);
        echo "\n";
        $selfClosure = function($stream) {
            return $stream;
        };
        $selfSerialized = SerializableClosure::serialize($selfClosure->bindTo(null, null), static::$secretKey);
        $peerSerialized = is_string($closure) ? $closure : SerializableClosure::serialize($closure->bindTo(null, null), static::$secretKey);
        $error = null;

        $deferred = new Deferred;
        $uuid = Uuid::uuid4()->toString();
        $this->uuidToDeferred[$uuid] = $deferred;
        $fn = function ($controlConnection) use ($selfSerialized, $peerSerialized, $params, $uuid, $data) {
            $controlConnection->write($this->decodeEncode->encode([
                'cmd' => 'callback_peer_stream',
                'uuid' => $this->uuid,
                'data' => [
                    'event' => $uuid . '_' . 'callback_peer_stream',
                    'self_serialized' => $selfSerialized,
                    'peer_serialized' => $peerSerialized,
                    'params' => $params,
                    'data' => $data
                ]
            ]));
        };

        if (!$this->controlConnection) {
            $this->once('controlConnection', $fn);
        } else {
            $fn($this->controlConnection);
        }
        $this->once($uuid . '_callback_peer_stream', $fn1 = function ($stream) use ($deferred) {
            $deferred->resolve($stream);
        });
        try {
            $stream = await(\React\Promise\Timer\timeout($deferred->promise(), 3)->then(function ($stream) use ($uuid) {
                unset($this->uuidToDeferred[$uuid]);
                return $stream;
            }, function ($e) use ($uuid, $fn, $fn1) {
                if ($fn) {
                    $this->removeListener('controlConnection', $fn);
                }
                $this->removeListener($uuid . '_callback_peer_stream', $fn1);

                unset($this->uuidToDeferred[$uuid]);
                if ($e instanceof TimeoutException) {
                    throw new \RuntimeException(
                        'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                        \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                    );
                }
                throw $e;
            }));
            $stream->on('error', function ($e) use ($stream) {
                $stream->close();
            });
            return $stream;
        } catch (\Throwable $e) {
            $error = $e;
        }

        $stream = new Stream\ThroughStream;

        Loop::futureTick(function () use ($error, $stream) {
            $stream->emit('error', [$error]);
        });

        $stream->on('error', function ($e) use ($stream) {
            $stream->close();
        });

        return $stream;
    }
}
