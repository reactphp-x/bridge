<?php

namespace Reactphp\Framework\Bridge;

use React\Stream\DuplexStreamInterface;
use React\Socket\ConnectorInterface;
use React\Stream;
use SplObjectStorage;
use React\Promise\Deferred;
use Ramsey\Uuid\Uuid;
use React\Promise\Timer\TimeoutException;
use Laravel\SerializableClosure\SerializableClosure;
use function React\Async\await;
use React\EventLoop\Loop;
use Reactphp\Framework\Bridge\Interface\DecodeEncodeInterface;
use Reactphp\Framework\Bridge\Info;
use Reactphp\Framework\Bridge\DecodeEncode\WebsocketDecodeEncode;
use Reactphp\Framework\Bridge\Connector\WebsocketConnector;
use Reactphp\Framework\Bridge\Connector\TcpConnector;

final class Client extends AbstractClient
{
    use \Evenement\EventEmitterTrait;

    public static $debug = false;

    private $uri;
    protected $uuid;

    /**
     * @var DecodeEncodeInterface
     */
    protected $decodeEncode;
    protected $decodeEncodeClass;

    /**
     * @var DuplexStreamInterface
     */
    protected $controlConnection;
    protected SplObjectStorage $connections;

    private $uuidToDeferred = [];

    protected $connector;

    // 0 close 1 open 2 shutdown
    protected $status = 0;



    public function __construct($uri, $uuid, DecodeEncodeInterface $decodeEncode = null)
    {
        $this->uuid = $uuid;
        $this->decodeEncode = $decodeEncode ?? new WebsocketDecodeEncode;
        $this->decodeEncodeClass = get_class($this->decodeEncode);
        $this->connections = new SplObjectStorage;
        $this->uri = $uri;
        $this->createConnector($uri);
    }

    private function createConnector($uri)
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);
        $host = parse_url($uri, PHP_URL_HOST);
        $port = parse_url($uri, PHP_URL_PORT);

        if ($scheme == 'ws' || $scheme == 'wss') {
            $this->setConnector(new WebsocketConnector($this));
        } else if ($scheme == 'tcp' || $scheme == 'tls') {
            $this->setConnector(new TcpConnector($this));
        } else {
            throw new \Exception('scheme not support');
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

        $timer = Loop::addPeriodicTimer(10, function () use ($stream, $msg) {
            $this->ping($stream)->then(function () {
            }, function ($e) use ($stream, $msg) {
                echo $msg . ' ping error ' . $e->getMessage() . PHP_EOL;
                $stream->close();
            });
        });
        $stream->on('close', function () use ($timer) {
            Loop::cancelTimer($timer);
        });
    }

    public function onMessage(DuplexStreamInterface $stream, $message)
    {
        if ($this->controlConnection === $stream) {
            if ($messages = $this->decodeEncode->decode($message)) {
                foreach ($messages as $message) {
                    $this->handleControlData($stream, $message);
                }
            }
        } elseif ($this->connections->contains($stream)) {
            if ($messages = $this->connections[$stream]['decodeEncode']->decode($message)) {
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
            if ($this->status != 2){
                $this->status = 0;
            }
            Loop::addTimer(3, function () {
                $this->start();
            });
        } elseif ($this->connections->contains($stream)) {
            echo 'tunnelConnection close' . PHP_EOL;
            foreach ($this->connections[$stream]['streams'] as $tunnelStream) {
                $tunnelStream->close();
            }
            $this->connections->detach($stream);
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
        return \React\Promise\Timer\timeout($deferred->promise(), 1)->then(function () use ($uuid) {
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
            echo 'controlMessage: ' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        $cmd = $message['cmd'] ?? '';
        $uuid = $message['uuid'] ?? '';
        if ($cmd == 'init') {
            $this->emit('success', [$message['data'] ?? []]);
        } 
        else if($cmd == 'controllerConnected') {
            $this->emit('controllerConnected', [$message['data'] ?? []]);
        }
        else if ($cmd == 'createTunnelConnection') {
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
        }
    }


    protected function handleTunnelData($conn, $message)
    {
        if (!is_array($message)) {
            return;
        }

        if (self::$debug) {
            echo 'tunnelMessage: ' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        $decodeEncode = $this->connections[$conn]['decodeEncode'];
        $uuid = $message['uuid'];
        $cmd = $message['cmd'];
        if ($cmd == 'init') {
            $this->connections[$conn]['remote_address'] = $message['data']['remote_address'] ?? '';
        } else if ($cmd == 'callback') {
            $serialized = $message['data']['serialized'];
            $closure = unserialize($serialized)->getClosure();
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
                                'trace' => $e->getTrace(),
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
        if ($this->status == 1 || $this->status == 2) {
            return;
        }

        $this->status = 0;
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
        $closure = $closure->bindTo(null, null);
        $error = null;

        $deferred = new Deferred;
        $uuid = Uuid::uuid4()->toString();
        $this->uuidToDeferred[$uuid] = $deferred;
        $fn = function ($controlConnection) use ($closure, $params, $uuid, $data) {
            $controlConnection->write($this->decodeEncode->encode([
                'cmd' => 'callback_peer_stream',
                'uuid' => $this->uuid,
                'data' => [
                    'event' => $uuid . '_' . 'callback_peer_stream',
                    'serialized' => serialize(new SerializableClosure($closure)),
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
