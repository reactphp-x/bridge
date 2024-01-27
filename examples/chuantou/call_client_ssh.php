<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;
use function React\Async\async;
use function React\Async\await;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\Connector;
Client::$debug = true;

$uri = 'ws://192.168.1.9:8010';
$client = new Client($uri, 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb');
$client->start();


$socket = new SocketServer('0.0.0.0:8022');
$socket->on('connection', async(function (\React\Socket\ConnectionInterface $connection) use ($client) {
    $data = '';
    $connection->on('data', $fn = function ($buffer) use (&$data) {
        echo '9999999999-'.$buffer;
        $data .= $buffer;
    });
    $stream = $client->call(function ($stream) {
        $data = '';
        $stream->on('data', $fn = function ($buffer) use (&$data) {
            echo '1000100101010-'.$buffer;
            $data .= $buffer;
        });
        (new Connector())->connect('192.168.1.9:22')->then(function ($connection) use ($stream, &$data, $fn) {
            $stream->pipe($connection, [
                'end' => true
            ]);

            $connection->pipe($stream, [
                'end' => true
            ]);

            $stream->on('close', function () use ($connection) {
                $connection->end();
            });

            $connection->on('close', function () use ($stream) {
                $stream->end();
            });

            if ($data) {
                $connection->write($data);
                $stream->removeListener('data', $fn);
                $data = '';
            }

        }, function ($e) use ($stream) {
            $stream->emit('error', [$e]);
        });
        return $stream;
    }, [
        'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165'
    ]);

    if ($data) {
        $stream->write($data);
        $connection->removeListener('data', $fn);
        $data = '';
    }

    $connection->pipe($stream, [
        'end' => true
    ]);

    $stream->pipe($connection, [
        'end' => true
    ]);

    $stream->on('close', function () use ($connection) {
        $connection->end();
    });

    $connection->on('close', function () use ($stream) {
        $stream->end();
    });
}));
