<?php

namespace Reactphp\Framework\Bridge;

use Reactphp\Framework\Bridge\DecodeEncode\WebsocketDecodeEncode;
use Reactphp\Framework\Pool\AbstractConnectionPool;
use Reactphp\Framework\Bridge\Interface\CallInterface;
use Reactphp\Framework\Bridge\Interface\CreateConnectionInterface;
use Reactphp\Framework\Bridge\Interface\DecodeEncodeInterface;
use Reactphp\Framework\Bridge\Info;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Async\async;
use function React\Async\await;
use Ramsey\Uuid\Uuid;
use React\Stream;
use React\Promise\Timer\TimeoutException;
use function React\Promise\reject;
use function React\Promise\resolve;
use Reactphp\Framework\Pool\Exception;

class Pool extends AbstractConnectionPool implements CallInterface
{

    protected $connectTimeout = 3;
    protected $connections;
    protected $uri;
    protected $createConnection;

    const CLOSE = 'close';
    const IDLE = 'idle';
    const BUSY = 'busy';
    const REMOVING = 'removing';


    public function __construct(
        CreateConnectionInterface $createConnection,
        $config = [],
        LoopInterface $loop = null
    ) {
        $this->connectTimeout = $config['connect_timeout'] ?? 3;
        $this->createConnection = $createConnection;
        $this->createConnection->setCall($this);
        $this->connections = new \SplObjectStorage;

        parent::__construct($config, $loop);
    }

    protected function log($data)
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        echo $data . "\n";
    }

    public function call($closure, $params = null, $data = [])
    {
        $this->log('call');
        $read = new Stream\ThroughStream;
        $write = new Stream\ThroughStream;
        $stream = new Stream\CompositeStream($read, $write);
        try {
            $serialized = is_string($closure) ? $closure : SerializableClosure::serialize($closure->bindTo(null, null));
            $connection = await($this->getConnection($params));
            $uuid = Uuid::uuid4()->toString();
            $this->connections[$connection]['streams']->attach($stream);
            $this->connections[$connection]['status'] = self::BUSY;
            $uuidToStream = $this->connections[$connection]['uuidToStream'];
            $uuidToStream[$uuid] = $stream;
            $this->connections[$connection]['uuidToStream'] = $uuidToStream;
            $controlUuidToStreamUuids = $this->connections[$connection]['controlUuidToStreamUuids'];
            $controlUuidToStreamUuids[$this->createConnection->getControlUuidByTunnelStream($connection)][] = $uuid;
            $this->connections[$connection]['controlUuidToStreamUuids'] = $controlUuidToStreamUuids;
            $connection->write($this->connections[$connection]['decodeEncode']->encode([
                'cmd' => 'callback',
                'uuid' => $uuid,
                'data' => [
                    'serialized' => $serialized
                ] + $data
            ]));

            $write->on('data', function ($data) use ($uuid, $connection) {
                $connection->write($this->connections[$connection]['decodeEncode']->encode([
                    'cmd' => 'data',
                    'uuid' => $uuid,
                    'data' => $data
                ]));
            });


            $stream->on('error', function ($e) use ($connection, $stream, $uuid) {
                if ($this->connections->contains($connection)) {
                    if ($this->connections[$connection]['streams']->contains($stream)) {
                        $this->connections[$connection]['streams']->detach($stream);
                        $connection->write($this->connections[$connection]['decodeEncode']->encode([
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
            $write->on('end', function () use ($connection, $stream, $uuid) {
                if ($this->connections->contains($connection)) {
                    if ($this->connections[$connection]['streams']->contains($stream)) {
                        $connection->write($this->connections[$connection]['decodeEncode']->encode([
                            'cmd' => 'end',
                            'uuid' => $uuid,
                        ]));
                        $this->connections[$connection]['streams']->detach($stream);
                    }
                }
            });
            $stream->on('close', function () use ($connection, $stream, $uuid) {
                if ($this->connections->contains($connection)) {
                    if ($this->connections[$connection]['streams']->contains($stream)) {
                        $connection->write($this->connections[$connection]['decodeEncode']->encode([
                            'cmd' => 'close',
                            'uuid' => $uuid,
                        ]));
                        $this->connections[$connection]['streams']->detach($stream);
                    }
                    $uuidToStream = $this->connections[$connection]['uuidToStream'];
                    unset($uuidToStream[$uuid]);
                    $this->connections[$connection]['uuidToStream'] = $uuidToStream;
                    if ($this->connections[$connection]['streams']->count() == 0) {
                        $this->connections[$connection]['status'] = self::IDLE;
                    }

                    $controlUuid = $this->createConnection->getControlUuidByTunnelStream($connection);
                    $controlUuidToStreamUuids = $this->connections[$connection]['controlUuidToStreamUuids'];
                    if (isset($controlUuidToStreamUuids[$controlUuid])) {
                        $controlUuidToStreamUuids[$controlUuid] = array_diff($controlUuidToStreamUuids[$controlUuid], [$uuid]);
                        if (count($controlUuidToStreamUuids[$controlUuid]) == 0) {
                            unset($controlUuidToStreamUuids[$controlUuid]);
                        }
                    }
                    $this->connections[$connection]['controlUuidToStreamUuids'] = $controlUuidToStreamUuids;

                    echo spl_object_hash($connection) . ' stream count ' . $this->connections[$connection]['streams']->count() . "\n";
                    echo "close $uuid\n";
                    $this->releaseConnection($connection);
                }
            });
        } catch (\Throwable $th) {
            $this->log([
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'msg' => $th->getMessage(),
                'trace' => $th->getTrace()

            ]);
            Loop::futureTick(function () use ($stream, $th) {
                $stream->emit('error', [$th]);
                $stream->close();
            });
        }

        return $stream;
    }

    public function getConnection($params = null)
    {
        if ($this->closed) {
            return reject(new Exception('pool is closed'));
        }

        $uuid = $params['uuid'] ?? '';
        $uuids = explode(',', $uuid);

        // 说明所有连接都是繁忙的，去重用数量最少的
        if ($this->current_connections >= $this->max_connections && $this->idle_connections->count() == 0 && $this->connections->count() > 0) {
            $currentConn = $this->getLowStreamCountConnection($uuids);
            if ($currentConn) {
                return \React\Promise\resolve($currentConn);
            }
        }



        // 去看下闲置连接是否有符合要求的
        if ($this->idle_connections->count() > 0) {
            foreach ($this->idle_connections as $connection) {
                if (in_array($this->createConnection->getControlUuidByTunnelStream($connection), $uuids)) {
                    if ($timer = $this->idle_connections[$connection]['timer']) {
                        Loop::cancelTimer($timer);
                    }
                    if ($ping = $this->idle_connections[$connection]['ping']) {
                        Loop::cancelTimer($ping);
                        $ping = null;
                    }
                    $this->idle_connections->detach($connection);
                    return resolve($connection);
                }
            }
        }

        // 说明没有空闲连接了，去创建连接
        if ($this->current_connections < $this->max_connections) {
            $this->current_connections++;
            return resolve($this->createConnection($params));
        }

        if ($this->max_wait_queue && $this->wait_queue->count() >= $this->max_wait_queue) {
            return reject(new Exception("over max_wait_queue: " . $this->max_wait_queue . '-current quueue:' . $this->wait_queue->count()));
        }

        $deferred = new Deferred();
        $this->wait_queue->attach($deferred, $params);

        if (!$this->wait_timeout) {
            return $deferred->promise();
        }

        $that = $this;

        return \React\Promise\Timer\timeout($deferred->promise(), $this->wait_timeout, $this->loop)->then(null, function ($e) use ($that, $deferred) {

            $that->wait_queue->detach($deferred);

            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)' . 'and wait queue ' . $that->wait_queue->count() . ' count',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    protected function getLowStreamCountConnection($uuids)
    {
        $connections = [];
        foreach ($this->connections as $connection) {
            if ($this->connections[$connection]['status'] == self::BUSY && in_array($this->createConnection->getControlUuidByTunnelStream($connection), $uuids)) {
                $connections[] = $connection;
            }
        }

        if (count($connections) == 0) {
            return null;
        }

        $currentConn = $connections[0];

        foreach ($connections as $connection) {
            if ($this->connections[$connection]['streams']->count() < $this->connections[$currentConn]['streams']->count()) {
                $currentConn = $connection;
            }
        }

        return $currentConn;
    }


    protected function createConnection($params = null)
    {
        $this->log('createConnection');
        return $this->createConnection->createConnection($params, $this->connectTimeout)->then(function ($data) {
            list($connection, $decodeEncodeClass) = $data;
            $this->addConnection($connection, $decodeEncodeClass);
            return $connection;
        }, function ($e) {
            $this->current_connections--;
            throw $e;
        });
    }


    protected function _quit($connection)
    {
        $connection->end();
    }



    public function releaseConnection($connection)
    {
        if ($this->closed) {
            $this->_close($connection);
            $this->current_connections--;
            return;
        }

        // 说明连接关闭了
        if ($this->isNotValidConnection($connection)) {
            return;
        }

        // 有正在处理的请求，不释放
        if ($this->connections->contains($connection) && $this->connections[$connection]['streams']->count() > 0) {
            return;
        }

        // 队列中等待的请求，是否能匹配上
        if ($this->wait_queue->count() > 0) {
            foreach ($this->wait_queue as $deferred) {
                $params = $this->wait_queue[$deferred];
                $uuids = explode(',', $params['uuid'] ?? '');
                if (in_array($this->createConnection->getControlUuidByTunnelStream($connection), $uuids)) {
                    $this->wait_queue->detach($deferred);
                    $deferred->resolve($connection);
                    return;
                }
            }
        }

        // 释放连接

        $ping = null;
        $timer = Loop::addTimer($this->keep_alive, function () use ($connection, &$ping) {
            if ($this->idle_connections->count() > $this->min_connections) {
                $this->_quit($connection);
                $this->idle_connections->detach($connection);
                $this->current_connections--;
                $this->tryCreateWaitQueueConnection();
            } else {
                $ping = Loop::addPeriodicTimer($this->keep_alive, function () use ($connection, &$ping) {
                    $this->_ping($connection)->then(null, function ($e) use ($ping) {
                        if ($ping) {
                            Loop::cancelTimer($ping);
                        }
                        $ping = null;
                    });
                });
                $this->_ping($connection)->then(null, function ($e) use ($ping) {
                    if ($ping) {
                        Loop::cancelTimer($ping);
                    }
                    $ping = null;
                });
            }
        });

        $this->idle_connections->attach($connection, [
            'timer' => $timer,
            'ping' => &$ping
        ]);

        // parent::releaseConnection($connection);
    }

    private function tryCreateWaitQueueConnection()
    {
        if ($this->wait_queue->count() > 0 && $this->current_connections < $this->max_connections) {
            $this->wait_queue->rewind();
            $deferred = $this->wait_queue->current();
            $params = $this->wait_queue[$deferred];
            $this->wait_queue->detach($deferred);
            $this->current_connections++;
            $deferred->resolve($this->createConnection($params));
        }
    }

    protected function isNotValidConnection($connection)
    {
        return !$this->connections->contains($connection);
    }

    protected function addConnection($connection, $decodeEncodeClass)
    {
        $this->connections->attach($connection, new Info([
            'status' => self::BUSY,
            'streams' => new \SplObjectStorage,
            'decodeEncode' => new $decodeEncodeClass,
            'uuidToStream' => [],
            'controlUuidToStreamUuids' => [],
        ]));

        $connection->on('data', function ($buffer) use ($connection) {
            if ($messages = $this->connections[$connection]['decodeEncode']->decode($buffer)) {
                foreach ($messages as $message) {
                    $this->handleTunnellData($connection, $message);
                }
            }
        });

        $connection->on('close', function () use ($connection) {

            if ($this->connections->contains($connection)) {
                // 说明不在idle
                if (!$this->idle_connections->contains($connection)) {
                    $this->current_connections--;
                } else {
                    if ($timer = $this->idle_connections[$connection]['timer']) {
                        Loop::cancelTimer($timer);
                    }
                    if ($ping = $this->idle_connections[$connection]['ping']) {
                        Loop::cancelTimer($ping);
                        $ping = null;
                    }
                    // trigger ping to close idle_connections
                    $this->_ping($connection)->then(null, function ($e) {
                    });
                }
                $streams = $this->connections[$connection]['streams'];
                $this->connections->detach($connection);
                foreach ($streams as $stream) {
                    $stream->close();
                }
            }
        });
    }

    protected function handleTunnellData($connection, $message)
    {
        if (!isset($message['cmd'])) {
            return;
        }
        $uuid = $message['uuid'];
        $cmd = $message['cmd'];
        if (in_array($cmd, ['data', 'end', 'close', 'error'])) {
            if (isset($this->connections[$connection]['uuidToStream'][$uuid])) {
                $stream = $this->connections[$connection]['uuidToStream'][$uuid];
                if ($cmd == 'data') {
                    $stream->emit('data', [$message['data']]);
                } else if ($cmd == 'end') {
                    $this->connections[$connection]['streams']->detach($stream);
                    $stream->emit('end');
                    $stream->end();
                } else if ($cmd == 'close') {
                    $this->connections[$connection]['streams']->detach($stream);
                    // friendly close
                    $stream->emit('end');
                    $stream->end();
                } else if ($cmd == 'error') {
                    $this->connections[$connection]['streams']->detach($stream);
                    $stream->emit('error', [new \Exception(is_array($message['data']) ? json_encode($message['data'], JSON_UNESCAPED_UNICODE) : $message['data'])]);
                    $stream->end();
                }
            }
        } else if ($cmd == 'ping') {
            $connection->write($this->connections[$connection]['decodeEncode']->encode([
                'cmd' => 'pong',
                'uuid' => $uuid,
            ]));
        } else if ($cmd == 'pong') {
            if (isset($this->uuidToDeferred[$uuid])) {
                $this->uuidToDeferred[$uuid]->resolve(true);
            }
        }
    }

    protected $uuidToDeferred = [];

    protected function _ping($connection)
    {
        $uuid = Uuid::uuid4()->toString();
        $deferred = new Deferred;
        $this->uuidToDeferred[$uuid] = $deferred;
        $connection->write($this->connections[$connection]['decodeEncode']->encode([
            'cmd' => 'ping',
            'uuid' => $uuid,
        ]));
        $that = $this;
        return \React\Promise\Timer\timeout($deferred->promise(), 2)->then(function () use ($uuid, $connection, $that) {
            unset($this->uuidToDeferred[$uuid]);
            if (!$that->idle_connections->contains($connection)) {
                $that->releaseConnection($connection);
            }
        }, function ($e) use ($uuid, $connection, $that) {
            $that->_close($connection);
            if ($that->idle_connections->contains($connection)) {
                $that->idle_connections->detach($connection);
                $that->current_connections--;
            }
            unset($this->uuidToDeferred[$uuid]);
            // 试着创建等待队列中的连接
            $this->tryCreateWaitQueueConnection();
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection timed out'
                );
            }
            throw $e;
        });
    }
}
