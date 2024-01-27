<?php

namespace Reactphp\Framework\Bridge\Connector;

use React\Socket\ConnectorInterface;
use function Ratchet\Client\connect;
use React\Stream;
use Reactphp\Framework\Bridge\Interface\ClientInterface;
use React\EventLoop\Loop;

class WebsocketConnector implements ConnectorInterface
{
    protected $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function connect($uri)
    {
        $query = $this->getQuery($uri);
        $uuid = $query['uuid'] ?? '';
        $uri = $this->removeQuery($uri);
        return connect($uri)->then(function ($conn) use ($uuid) {
            $read = new Stream\ThroughStream;
            $write = new Stream\ThroughStream;
            $stream = new Stream\CompositeStream($read, $write);
            $reflectionClass = new \ReflectionClass($conn);
            $protectedProperty = $reflectionClass->getProperty('_stream');
            $protectedProperty->setAccessible(true);

            $write->on('data', function ($data) use ($conn) {
                $conn->send($data);
            });

            $stream->on('close', function () use ($conn) {
                $conn->close();
            });

            $this->client->onOpen($stream, [
                'local_address' => str_replace('tcp://', '', $protectedProperty->getValue($conn)->getLocalAddress()),
                'uuid' => $uuid,
            ]);

            $conn->on('message', function ($msg) use ($read, $stream) {
                $read->write($msg);
                $this->client->onMessage($stream, $msg);
            });
            $conn->on('close', function ($code = null, $reason = null) use ($stream) {
                $stream->close();
                $this->client->onClose($stream, $reason);
            });
            $conn->on('error', function ($e) use ($stream) {
                $stream->emit('error', [$e]);
                $this->client->onError($stream, $e);
            });
            return $stream;
        }, function ($e) use ($uri) {
            echo "connection failed ". $uri . "\n";
            echo $e->getMessage() . "\n";
            throw $e;
        });
    }

    private function getQuery($uri)
    {
        $query = parse_url($uri, PHP_URL_QUERY);
        $array =array_filter(explode('&', $query));
        $result = [];
        foreach ($array as $item) {
            $tmp = explode('=', $item);
            $result[$tmp[0]] = $tmp[1];
        }
        return $result;
    }

    private function removeQuery($uri)
    {
        $query = parse_url($uri, PHP_URL_QUERY);
        $uri = str_replace('?' . $query, '', $uri);
        return $uri;
    }
}
