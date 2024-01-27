<?php

use Reactphp\Framework\Bridge\Interface\CallInterface;
use React\Stream\DuplexStreamInterface;
use React\Socket\SocketServer;
use function React\Async\async;
use React\Stream\ThroughStream;

class PortToPort
{
    protected $call;
    protected $mapBuffer;

    protected $fromAddress;
    protected $toUuid;
    protected $toAddress;


    public function __construct(CallInterface $call, $mapBuffer = null)
    {
        $this->call = $call;
        $this->mapBuffer = $mapBuffer;

    }

    public function from($address)
    {
        $this->fromAddress = $address;
        return $this;
    }


    public function to($uuid, $address)
    {
        $this->toUuid = $uuid;
        $this->toAddress = $address;
        return $this;
    }

    public function start()
    {
        $socket = new SocketServer(strpos(':', $this->fromAddress) === false ? "0.0.0.0:{$this->fromAddress}" : $this->fromAddress);
        $socket->on('connection', async(function (\React\Socket\ConnectionInterface $connection) {
            $data = '';
            $connection->on('data', $fn = function ($buffer) use (&$data) {
                $data .= $buffer;
            });
            $mapBuffer = $this->mapBuffer;
            if ($mapBuffer) {
                $mapBuffer = $mapBuffer->bindTo(null, null);
            }
            $toAddress = $this->toAddress;
            $stream = $this->call->call((function ($stream) use ($mapBuffer, $toAddress) {
                $data = '';
                $stream->on('data', $fn = function ($buffer) use (&$data, $mapBuffer) {
                    // $buffer = preg_replace('/Host: ' . '192.168.1.9:8090' . '.*\r\n/', "Host: 192.168.1.9:8080\r\n", $buffer);
                    $data .= $buffer;
                    if ($mapBuffer) {
                        $data = $mapBuffer($data);
                    }
                });
                $throhStream = new ThroughStream($mapBuffer);
                $connector = new \React\Socket\Connector();
                $connector->connect($toAddress)->then(function (\React\Socket\ConnectionInterface $connection) use (&$data, $stream, $fn, $throhStream) {
                    if ($data) {
                        $connection->write($data);
                        $data = '';
                    }
                    $stream->removeListener('data', $fn);
                    $stream->pipe($throhStream)->pipe($connection, [
                        'end' => true
                    ]);
                    $connection->pipe($stream, [
                        'end' => true
                    ]);
                    $connection->on('close', function () use ($stream) {
                        $stream->end();
                    });
                    $stream->on('close', function () use ($connection) {
                        $connection->end();
                    });
                }, function (Exception $e) {
                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                });

                return $stream;
            })->bindTo(null, null), [
                'uuid' => $this->toUuid
            ]);

            $connection->removeListener('data', $fn);
            $fn = null;

            if ($data) {
                $stream->write($data);
                $data = '';
            }

            $connection->pipe($stream, [
                'end' => true
            ]);
            $stream->pipe($connection, [
                'end' => true
            ]);
            $stream->on('close', function () use ($connection) {
                $connection->close();
            });
            $connection->on('close', function () use ($stream) {
                $stream->end();
            });
        }));
    }


    // public function __construct(DuplexStreamInterface $stream, CallInterface $call, callable $mapBuffer = null)
    // {
    //     $this->stream = $stream;
    //     $this->call = $call;

    //     if (!$mapBuffer) {
    //         $this->mapBuffer = function ($buffer) {
    //             return $buffer;
    //         };
    //     }
    // }

}
