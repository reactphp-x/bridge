<?php

use Reactphp\Framework\Bridge\Interface\CallInterface;
use React\Stream\DuplexStreamInterface;
use React\Socket\SocketServer;
use function React\Async\async;
use React\Stream\ThroughStream;
use React\Datagram\Factory;

class PortToPort
{
    protected $call;

    protected $fromAddress;
    protected $inMapBuffer;

    protected $toUuid;
    protected $toAddress;
    protected $outMapBuffer;

    protected $protocol;


    public function __construct(CallInterface $call, $protocol = 'tcp')
    {
        $this->call = $call;
        $this->protocol = $protocol;

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
        if ($this->protocol == 'udp') {
            $this->startUdp();
            return;
        }

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
            $stream = $this->call->call(function ($stream) use ($inMapBuffer, $outMapBuffer, $toAddress) {
                $data = '';
                $stream->on('data', $fn = function ($buffer) use (&$data, $inMapBuffer) {
                    // $buffer = preg_replace('/Host: ' . '192.168.1.9:8090' . '.*\r\n/', "Host: 192.168.1.9:8080\r\n", $buffer);
                    if ($inMapBuffer) {
                        $data .= $inMapBuffer($buffer);
                    } else {
                        $data .= $buffer;
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
            }, [
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

    protected function startUdp()
    {
        $factory = new Factory;
        $factory->createServer(strpos(':', $this->fromAddress) === false ? "0.0.0.0:{$this->fromAddress}" : $this->fromAddress)->then(function ($server) {
            $server->on('message', function($message, $address, $server) {
                $server->send('hello ' . $address . '! echo: ' . $message, $address);
        
                echo 'client ' . $address . ': ' . $message . PHP_EOL;
            });
        });
    }

}
