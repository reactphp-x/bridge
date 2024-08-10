<?php

namespace ReactphpX\Bridge;

use Evenement\EventEmitterTrait;
use React\Promise\Deferred;
use function React\Async\async;
use React\Promise\Timer\TimeoutException;
use Ramsey\Uuid\Uuid;
use React\Stream\DuplexStreamInterface;
use ReactphpX\Bridge\Interface\ServerInterface;
use ReactphpX\Bridge\Interface\CallInterface;
use ReactphpX\Bridge\Info;
use ReactphpX\Bridge\Interface\VerifyInterface;
use React\EventLoop\Loop;

class Server implements ServerInterface
{
    use EventEmitterTrait;

    public static $debug = false;

    protected $call;
    protected $verify;
    protected $clients;
    protected $controllerConnections;
    protected $tunnelConnections;
    protected $tmpConnections;
    protected $uuidToControlerConnections = [];

    protected $uuidToDeferred = [];

    public function __construct(VerifyInterface $verify)
    {
        $this->verify = $verify;

        // $this->uuids = $uuids;
        $this->clients = new \SplObjectStorage;

        $this->tmpConnections = new \SplObjectStorage;
        $this->controllerConnections = new \SplObjectStorage;
        $this->tunnelConnections = new \SplObjectStorage;
    }

    public function setCall(CallInterface $call)
    {
        $this->call = $call;
    }

    public function call($closure, $params = null, $data = [])
    {
        return $this->call->call($closure, $params, $data);
    }

    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        if (!isset($info['decodeEncodeClass'])) {
            // error
            throw new \InvalidArgumentException('decodeEncodeClass is required');
        }

        $decodeEncode = new $info['decodeEncodeClass'];

        $stream->on('data', function ($buffer) use ($stream) {
            if (self::$debug) {
                echo "=====> onMessage <========" . "\n";
                echo $buffer . "\n";
            }
        });

        $stream->write($decodeEncode->encode([
            'cmd' => 'init',
            'uuid' => Uuid::uuid4()->toString(),
            'data' => [
                'remote_address' => $info['remote_address'] ?? '',
            ]
        ]));


        $hash = spl_object_hash($stream);
        echo "New connection! {$hash}\n";
        $this->clients->attach($stream, new Info($info + [
            'decodeEncode' => $decodeEncode,
            'active_time' => time(),
        ]));
        $this->tmpConnections->attach($stream);
        echo "connection count({$this->clients->count()})\n";
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        // tmp data
        if ($this->tmpConnections->contains($stream)) {
            if ($messages = $this->clients[$stream]['decodeEncode']->decode($msg)) {
                foreach ($messages as $message) {
                    $this->handleTmpData($stream, $message);
                }
            }
        }
        // control data
        else if ($this->controllerConnections->contains($stream)) {
            if ($messages = $this->clients[$stream]['decodeEncode']->decode($msg)) {
                foreach ($messages as $message) {
                    $this->handleControlData($stream, $message);
                }
            }
        }
        // tunnel data pool to handle
        else if ($this->tunnelConnections->contains($stream)) {
            $this->tunnelConnections[$stream]['request_number'] = $this->tunnelConnections[$stream]['request_number'] + 1;
            $control = $this->tunnelConnections[$stream]['control'];
            if ($this->controllerConnections->contains($control)) {
                $this->controllerConnections[$control]['request_number'] = $this->controllerConnections[$control]['request_number'] + 1;
                $this->controllerConnections[$control]['tunnelConnections'][$stream]['request_number'] = $this->controllerConnections[$control]['tunnelConnections'][$stream]['request_number'] + 1;
            }
        }

        if ($this->clients->contains($stream)) {
            $this->clients[$stream]['active_time'] = time();
        }
    }

    protected function handleTmpData($stream, $message)
    {
        if ($this->controllerConnections->contains($stream)) {
            $this->handleControlData($stream, $message);
            return;
        }

        if (!is_array($message)) {
            $stream->close();
            return;
        }
        $uuid = $message['uuid'] ?? null;
        $cmd = $message['cmd'] ?? null;
        // 注册控制器
        if ($cmd == 'registerController') {
            echo "$uuid registerController\n";
            if (!$this->verify->verify($uuid)) {
                $stream->close();
                echo "$uuid registerController fail\n";
                return;
            }

            if (isset($this->uuidToControlerConnections[$uuid]) && $this->uuidToControlerConnections[$uuid]->count() >= 2) {
                echo "$uuid registerController fail over 2\n";
                $stream->close();
                return;
            }
            if ($this->controllerConnections->contains($stream)) {
                echo "$uuid registerController fail already register\n";
                $stream->close();
                return;
            }
            $this->controllerConnections->attach($stream, new Info([
                'request_number' => 0,
                'uuid' => $uuid,
                'tunnelConnections' => new \SplObjectStorage,
            ]));
            $this->tmpConnections->detach($stream);
            if (!isset($this->uuidToControlerConnections[$uuid])) {
                $this->uuidToControlerConnections[$uuid] = new \SplObjectStorage;
            }
            $this->uuidToControlerConnections[$uuid]->attach($stream);

            $this->emit($uuid . '_controllerConnected', [$stream]);
            $stream->write($this->clients[$stream]['decodeEncode']->encode([
                'cmd' => 'controllerConnected',
                'uuid' => $uuid,
                'data' => [
                    'something' => $this->verify->getSomethingByUuid($uuid),
                ]
            ]));
            // ping
            echo "$uuid registerController success\n";
        } else if ($cmd == 'registerTunnel') {
            if (isset($this->uuidToDeferred[$uuid])) {
                $this->tmpConnections->detach($stream);
                $deferred = $this->uuidToDeferred[$uuid];
                $deferred->resolve($stream);
                echo "registerTunnel success\n";
            } else {
                $stream->close();
            }
        } else {
            echo "unknown tmp cmd $cmd\n";
            $stream->close();
        }
    }

    protected function handleControlData($controlStream, $message)
    {
        if (!is_array($message)) {
            $controlStream->close();
            return;
        }

        $uuid = $message['uuid'] ?? null;
        $cmd = $message['cmd'] ?? null;
        if ($cmd == 'ping') {
            $controlStream->write($this->clients[$controlStream]['decodeEncode']->encode([
                'cmd' => 'pong',
                'uuid' => $uuid,
            ]));
        } else if ($cmd == 'pong') {
            if (isset($this->uuidToDeferred[$uuid])) {
                $this->uuidToDeferred[$uuid]->resolve(true);
            }
        } else if ($cmd === 'callback_peer_stream') {
            async(function () use ($uuid, $message) {
                try {

                    $peerUuid = $message['data']['params']['uuid'] ?? null;
                    if (!$peerUuid) {
                        $something = $message['data']['params']['something'] ?? null;
                        $peerUuid = $this->verify->getUuidBySomething($something);
                    }
                    if (!$peerUuid || !$this->verify->verify($peerUuid)) {
                        echo "peerUuid verify fail\n";
                        return;
                    }

                    $stream = $this->call->call($message['data']['self_serialized'], [
                        'uuid' => $uuid,
                    ], [
                        'event' => $message['data']['event'] ?? ''
                    ]);

                    $data = '';
                    $stream->on('data', $fn = function ($buffer) use (&$data) {
                        $data .= $buffer;
                    });

                    $peerStream = $this->call->call($message['data']['peer_serialized'] ?? '', [
                        'uuid' => $peerUuid,
                    ], $message['data']['data'] ?? []);

                    if ($data) {
                        $peerStream->write($data);
                        $data = '';
                    }

                    $stream->removeListener('data', $fn);
                    $fn = null;

                    $stream->pipe($peerStream, [
                        'end' => true
                    ]);

                    $peerStream->pipe($stream, [
                        'end' => true
                    ]);
                    $peerStream->on('close', function () use ($stream) {
                        $stream->end();
                    });

                    $stream->on('close', function () use ($peerStream) {
                        $peerStream->end();
                    });

                    $peerStream->on('error', function ($e) use ($stream) {
                        $stream->emit('error', [$e]);
                    });

                    $stream->on('error', function ($e) use ($peerStream) {
                        echo "stream error {$e->getMessage()}\n";
                    });
                } catch (\Throwable $th) {
                    echo $th->getFile() . "\n";
                    echo $th->getLine() . "\n";
                    echo $th->getMessage() . "\n";
                }
            })();
        } else if ($cmd === 'extend_cmd') {
            $this->emit('extend_cmd', [$message, $controlStream]);
        }
    }

    public function getControlUuidByTunnelStream($tunnelStream)
    {
        if ($this->tunnelConnections->contains($tunnelStream)) {
            return $this->tunnelConnections[$tunnelStream]['control_uuid'];
        }
        return null;
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        echo "Server onClose\n";
        if ($this->tmpConnections->contains($stream)) {
            $this->tmpConnections->detach($stream);
        } else if ($this->controllerConnections->contains($stream)) {
            echo "controllerConnections onClose\n";
            $uuid = $this->controllerConnections[$stream]['uuid'];
            $this->controllerConnections->detach($stream);
            $this->uuidToControlerConnections[$uuid]->detach($stream);
            if ($this->uuidToControlerConnections[$uuid]->count() == 0) {
                unset($this->uuidToControlerConnections[$uuid]);
            }
        } else if ($this->tunnelConnections->contains($stream)) {
            echo "tunnelConnection onClose\n";
            $control = $this->tunnelConnections[$stream]['control'];
            if ($this->controllerConnections->contains($control)) {
                $this->controllerConnections[$control]['tunnelConnections']->detach($stream);
            }
            $this->tunnelConnections->detach($stream);
        }

        if ($this->clients->contains($stream)) {
            $this->clients->detach($stream);
        }
        echo "connection count({$this->clients->count()})\n";
    }

    public function onError(DuplexStreamInterface $stream, \Exception $e)
    {
        echo "Server onError\n";
        echo "An error has occurred: {$e->getMessage()}\n";
        echo $e->getTraceAsString() . "\n";
        $stream->close();
    }

    public function createConnection($params = null, $timeout = 3)
    {
        echo "createConnection-1\n";
        $deferred = new Deferred;
        $tunnelUuid = Uuid::uuid4()->toString();
        $this->uuidToDeferred[$tunnelUuid] = $deferred;

        $control = null;
        $event = null;
        $fn = function ($controlConnection) use ($tunnelUuid, &$control) {
            $control = $controlConnection;
            $controlConnection->write($this->clients[$control]['decodeEncode']->encode([
                'cmd' => 'createTunnelConnection',
                'uuid' => $tunnelUuid
            ]));
        };

        $uuid = $params['uuid'] ?? null;
        if ($uuid) {
            $uuids = explode(',', $uuid);
            $uuid = array_shift($uuids);
            if (isset($this->uuidToControlerConnections[$uuid]) && $this->uuidToControlerConnections[$uuid]->count() > 0) {
                $fn($this->getControllerConnection($this->uuidToControlerConnections[$uuid]));
                $fn = null;
            } else {
                $event = $uuid . '_controllerConnected';
                $this->once($event, $fn);
            }
        } else {
            $deferred->reject(new \RuntimeException('uuid is required'));
        }

        return \React\Promise\Timer\timeout($deferred->promise(), $timeout)->then(function ($connection) use ($tunnelUuid, &$control) {
            unset($this->uuidToDeferred[$tunnelUuid]);
            $this->tunnelConnections->attach($connection, new Info([
                'control_uuid' => $this->controllerConnections[$control]['uuid'],
                'control' => $control,
                'request_number' => 0,
            ]));
            $this->controllerConnections[$control]['tunnelConnections']->attach($connection, new Info([
                'request_number' => 0,
            ]));
            return [$connection, $this->clients[$connection]['decodeEncode']];
        }, function ($e) use ($tunnelUuid, $fn, $event, $uuids) {
            if ($fn) {
                $this->removeListener($event, $fn);
            }
            unset($this->uuidToDeferred[$tunnelUuid]);
            if ($e instanceof TimeoutException) {
                // continue try next client
                if (!empty($uuids)) {
                    return $this->createConnection([
                        'uuid' => implode(',', $uuids)
                    ], $e->getTimeout());
                }
                throw new \RuntimeException(
                    'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    public function getConnections()
    {
        return $this->controllerConnections;
    }

    private function getControllerConnection($connections)
    {
        $currentConnection = null;
        foreach ($connections as $connection) {
            if (!$currentConnection) {
                $currentConnection = $connection;
            } else {
                if ($this->controllerConnections[$connection]['request_number'] < $this->controllerConnections[$currentConnection]['request_number']) {
                    $currentConnection = $connection;
                }
            }
        }
        return $currentConnection;
    }

    public function enableKeepAlive($interval = 30)
    {
        Loop::addPeriodicTimer($interval, function () use ($interval) {
            foreach ($this->clients as $client) {
                if ($this->tunnelConnections->contains($client)) {
                    // tunnel connection ping
                    $info = $this->clients[$client];
                    // 空闲去 ping
                    if ((time() - $info['active_time']) > $interval) {
                        if (method_exists($this->call, '_ping')) {
                            $this->call->_ping($client)->then(function(){
                                echo "tunnel ping success\n";
                            }, function ($e) {
                                echo "tunnel ping fail\n";
                                echo $e->getMessage() . "\n";
                            });
                        }
                    }
                } else {
                    $info = $this->clients[$client];
                    if ((time() - $info['active_time']) > $interval) {
                        $this->ping($client, $info['decodeEncode'])->then(null, function ($e) use ($client) {
                            echo "client ping fail\n";
                            echo $e->getMessage() . "\n";
                            $client->close();
                        });
                    }
                }
            }
        });
    }

    protected function ping($conn, $decodeEncode)
    {
        $deferred = new Deferred;
        $uuid = Uuid::uuid4()->toString();
        $conn->write($decodeEncode->encode([
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
}
