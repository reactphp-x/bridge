<?php

namespace Reactphp\Framework\Bridge\Business;

use Reactphp\Framework\Bridge\Interface\CallInterface;
use React\Stream\ThroughStream;
use Reactphp\Framework\Bridge\Business\ClientStream\Factory;

final class StreamToStream
{
    protected $call;
    protected $fromUuid;
    protected $fromAddress;

    protected $fromStream;

    protected $inMapBuffer;
    protected $outMapBuffer;

    protected $toUuid;
    protected $toAddress;

    protected $status = 0;
    protected $errorMsg;

    // 0 = idle
    // 1 = connecting
    // 2 = connected
    // 3 = connecting failed
    // 4 = disconned


    public function __construct(CallInterface $call)
    {
        $this->call = $call;
    }


    public function from($fromUuid, $fromAddress, $fromStream, $inMapBuffer = null)
    {
        $this->fromUuid = $fromUuid;
        $this->fromAddress = $fromAddress;
        $this->fromStream = $fromStream;
        $this->inMapBuffer = $inMapBuffer;
        return $this;
    }

    public function to($toUuid, $toAddress, $outMapBuffer = null)
    {
        $this->toUuid = $toUuid;
        $this->toAddress = $toAddress;

        $this->outMapBuffer = $outMapBuffer;
        return $this;
    }


    public function start()
    {
        if ($this->status != 0) {
            return;
        }

        $this->status = 1;
        $data = '';
        $this->fromStream->on('data', $fn = function ($buffer) use (&$data) {
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

            Factory::createConnector($toAddress)->connect($toAddress)->then(function ($connection) use (&$data, $stream, $fn, $inStream, $outStream) {
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
            }, function (\Exception $e) use ($stream) {
                echo 'Error: ' . $e->getMessage() . PHP_EOL;
                $stream->emit('error', [$e]);
            });

            return $stream;
        }, [
            'uuid' => $this->toUuid
        ]);

        $this->status = 2;

        $this->fromStream->removeListener('data', $fn);
        $fn = null;

        if ($data) {
            $stream->write($data);
            $data = '';
        }

        $this->fromStream->pipe($stream, [
            'end' => true
        ]);
        $stream->pipe($this->fromStream, [
            'end' => true
        ]);
        $stream->on('error', function ($e) {
            $this->errorMsg = $e->getMessage();
            $this->status = 3;
        });
        $stream->on('close', function () {
            $this->fromStream->close();
        });
        $this->fromStream->on('close', function () use ($stream) {
            $this->stop();
            $stream->end();
        });

        return $this;
    }

    public function stop()
    {
        if ($this->status == 1) {
            $this->status = 4;
            $this->fromStream->close();
        }
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getInfo()
    {
        return [
            'fromUuid' => $this->fromUuid,
            'fromAddress' => $this->fromAddress,
            'toUuid' => $this->toUuid,
            'toAddress' => $this->toAddress,
            'status' => $this->status,
            'is_valid' => $this->status == 2,
            'errorMsg' => $this->errorMsg,
        ];
    }
}
