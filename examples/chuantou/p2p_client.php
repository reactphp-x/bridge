<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;
use function React\Async\async;
use function React\Async\await;
use React\Promise\Deferred;
use React\EventLoop\Loop;

Client::$debug = true;
$uri = 'ws://192.168.1.9:8010';
// $client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb');
$client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb');
$client->start();

async(function () use ($client) {
    $deferred = new Deferred();
    $stream = $client->call(function ($stream, $info) {
        $stream->write([
            'local_address' => $info['local_address'],
            'remote_address' => $info['remote_address'],
        ]);
        return $stream;
    }, [
        'uuid' => 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb'
    ]);
    $self = [];
    $peer = [];
    $stream->on('data', function ($data) use (&$self, &$peer, $deferred) {
        if ($peer) {
            $deferred->resolve([$self, $peer]);
        } else {
            $self = $data;
        }
        // var_dump('self', $data);
    });

    $stream->on('close', function () {
        echo "close\n";
    });

    $stream->on('error', function ($e) {
        echo $e->getMessage() . "\n";
    });
   

    $peerStream = $client->call(function ($stream, $info) {
        $stream->write([
            'local_address' => $info['local_address'],
            'remote_address' => $info['remote_address'],
        ]);
        return $stream;
    }, [
        'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
    ]);

    $peerStream->on('data', function ($data) use (&$self, &$peer, $deferred) {
        if ($self) {
            $deferred->resolve([$self, $data]);
        } else {
            $peer = $data;
        }
        // var_dump('peer', $data);
    });

    $peerStream->on('close', function () {
        echo "close\n";
    });

    $peerStream->on('error', function ($e) {
        echo $e->getMessage() . "\n";
    });

    \React\Promise\Timer\timeout($deferred->promise(), 3)->then(function ($data) use ($client, $stream, $peerStream) {
        $stream->end();
        $peerStream->end();
        list($self, $peer) = $data;
        var_dump($self, $peer, 'hello world');
        async(function ($self, $peer) use ($client) {
            $stream = $client->call(function ($stream, $info) use ($self, $peer) {
                echo 'start create local udp server' . "\n";
                $factory = new React\Datagram\Factory();
                // 相当于客户端
                $factory->createServer($self['local_address'])->then(function (React\Datagram\Socket $server) use ($peer, $stream) {
                    echo 'local_server' . '-' . $server->getLocalAddress() . "\n";
                    $server->on('message', function ($message, $address, $server) use ($stream) {
                        //$server->send('hello ' . $address . '! echo: ' . $message, $address);
                        $stream->write('receive peer');
                        echo 'client ' . $address . ': ' . $message . PHP_EOL;
                    });
                    $i = 0;
                    $timer = Loop::addPeriodicTimer(1, function () use ($server, $peer, &$i) {
                        $i++;
                        $server->send('hello world ' . $i, $peer['remote_address']);
                    });
                    Loop::addTimer(10, function () use ($timer) {
                        Loop::cancelTimer($timer);
                    });
                });
                return $stream;
            }, [
                'uuid' => 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb'
            ]);
            $stream->on('data', function ($data) use ($stream) {
                $stream->end();
            });
        })($self, $peer);

        async(function ($self, $peer) use ($client) {
            $peerStream = $client->call(function ($stream, $info) use ($self, $peer) {
                echo 'start create peer udp server' . "\n";
                $factory = new React\Datagram\Factory();
                // 相当于服务端 维持着客户端的连接（根据ip来维持），这个连接上可以实现把不同功能的服务（一个流相当于一个服务），将这个流转发至peer相对应的端口上
                // 要实现流至上的服务模块及udp流上数据的完整性
                $factory->createServer($peer['local_address'])->then(function (React\Datagram\Socket $server) use ($self, $stream) {
                    echo 'peer_server' . '-' . $server->getLocalAddress() . "\n";
                    $server->on('message', function ($message, $address, $server) use ($stream) {
                        $stream->write('receive local');
                        // todo 接收数据（数据中有uuid）
                        // 每个uuid 抽象成一个stream

                        // $server->send('hello ' . $address . '! echo: ' . $message, $address);

                        echo 'client ' . $address . ': ' . $message . PHP_EOL;
                    });
                    $timer = Loop::addPeriodicTimer(1, function () use ($server, $self) {
                        $server->send('hello world', $self['remote_address']);
                    });
                    Loop::addTimer(10, function () use ($timer) {
                        Loop::cancelTimer($timer);
                    });
                });
                return $stream;
            }, [
                'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
            ]);
            $peerStream->on('data', function ($data) use ($peerStream) {
                $peerStream->end();
            });
        })($self, $peer);
    }, function ($e) use ($stream, $peerStream) {
        $stream->end();
        $peerStream->end();
        echo $e->getMessage() . "\n";
    });
})();
