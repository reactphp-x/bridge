<?php

use Reactphp\Framework\Bridge\Interface\CallInterface;
use React\Stream\DuplexStreamInterface;
use React\Socket\SocketServer;
use function React\Async\async;
use React\Stream\ThroughStream;

class PortToPort
{
    protected $call;

    protected $fromAddress;
    protected $inMapBuffer;

    protected $toUuid;
    protected $toAddress;
    protected $outMapBuffer;


    public function __construct(CallInterface $call)
    {
        $this->call = $call;
    }

    public function from($address, $inMapBuffer = null)
    {
        $this->fromAddress = $address;
        $this->inMapBuffer = $inMapBuffer;
        return $this;
    }


    public function to($uuid, $address, $outMapBuffer = null)
    {
        $this->toUuid = $uuid;
        $this->toAddress = $address;
        $this->outMapBuffer = $outMapBuffer;
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
            $inMapBuffer = $this->inMapBuffer;
            if ($inMapBuffer) {
                $inMapBuffer = $inMapBuffer->bindTo(null, null);
            }
            $toAddress = $this->toAddress;
            $outMapBuffer = $this->outMapBuffer;
            if ($outMapBuffer) {
                $outMapBuffer = $outMapBuffer->bindTo(null, null);
            }
            $stream = $this->call->call((function ($stream) use ($inMapBuffer, $outMapBuffer, $toAddress) {
                $data = '';
                $stream->on('data', $fn = function ($buffer) use (&$data, $inMapBuffer) {
                    // $buffer = preg_replace('/Host: ' . '192.168.1.9:8090' . '.*\r\n/', "Host: 192.168.1.9:8080\r\n", $buffer);
                    $data .= $buffer;
                    if ($inMapBuffer) {
                        $data = $inMapBuffer($data);
                    }
                });
                $inStream = new ThroughStream($inMapBuffer);
                $outStream = new ThroughStream($outMapBuffer);
                $connector = new \React\Socket\Connector();
                $connector->connect($toAddress)->then(function (\React\Socket\ConnectionInterface $connection) use (&$data, $stream, $fn, $inStream, $outStream) {
                    if ($data) {
                        $connection->write($data);
                        $data = '';
                    }
                    $stream->removeListener('data', $fn);
                    $stream->pipe($inStream)->pipe($connection, [
                        'end' => true
                    ]);
                    $connection->pipe($outStream)->pipe($stream, [
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
