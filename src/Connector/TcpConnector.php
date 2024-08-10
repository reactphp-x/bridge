<?php

namespace ReactphpX\Bridge\Connector;

use React\Socket\ConnectorInterface;
use React\Stream;
use ReactphpX\Bridge\Interface\ClientInterface;
use React\Socket\Connector;

class TcpConnector implements ConnectorInterface
{
    protected $client;
    protected $connector;

    public function __construct(ClientInterface $client, ConnectorInterface $connector = null)
    {
        $this->client = $client;
        $this->connector = $connector ?? new Connector();
    }

    public function connect($uri)
    {
        $query = $this->getQuery($uri);
        $uuid = $query['uuid'] ?? '';
        $uri = $this->removeQuery($uri);
        return $this->connector->connect($uri)->then(function ($conn) use ($uuid) {
            $read = new Stream\ThroughStream;
            $write = new Stream\ThroughStream;
            $stream = new Stream\CompositeStream($read, $write);
            $write->on('data', function ($data) use ($conn) {
                $conn->write($data);
            });

            $stream->on('close', function () use ($conn) {
                $conn->close();
            });

            $this->client->onOpen($stream, [
                'local_address' => str_replace('tcp://', '', $conn->getLocalAddress()),
                'uuid' => $uuid,
            ]);

            $conn->on('data', function ($msg) use ($read, $stream) {
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
        $query = '';
        if (\strpos($uri, '?') !== false) {
            $query = (string)\substr($uri, \strpos($uri, '?') + 1);
        }

        $array =array_filter(explode('&', $query ?: ''));
        $result = [];
        foreach ($array as $item) {
            $tmp = explode('=', $item);
            $result[$tmp[0]] = $tmp[1];
        }
        return $result;
    }

    private function removeQuery($uri)
    {
        if (\strpos($uri, '?') !== false) {
            $uri = (string)\substr($uri, 0,\strpos($uri, '?'));
        }
        return $uri;
    }
}
