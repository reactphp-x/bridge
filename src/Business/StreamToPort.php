<?php

namespace ReactphpX\Bridge\Business;

use ReactphpX\Bridge\Interface\CallInterface;
use ReactphpX\Bridge\Business\ClientStream\Factory;
use ReactphpX\Bridge\SerializableClosure;

final class StreamToPort
{
    protected $call;
    protected $fromUuid;
    protected $fromAddress;

    protected $fromStream;

    protected $inMapBuffer;
    protected $outMapBuffer;

    protected $toUuid;
    protected $toAddress;
    protected $toSecretKey;

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

    public static function create(CallInterface $call)
    {
        return new static($call);
    }


    public function from($fromUuid, $fromAddress, $fromStream, $inMapBuffer = null)
    {
        $this->fromUuid = $fromUuid;
        $this->fromAddress = $fromAddress;
        $this->fromStream = $fromStream;
        $this->inMapBuffer = $inMapBuffer;

        return $this;
    }

    public function to($toUuid, $toAddress, $outMapBuffer = null, $toSecretKey = null)
    {
        $this->toUuid = $toUuid;
        $this->toAddress = $toAddress;
        $this->toSecretKey = $toSecretKey;

        $this->outMapBuffer = $outMapBuffer;
        return $this;
    }


    public function start()
    {
        if ($this->status != 0) {
            return;
        }

        $this->status = 1;
        $inMapBuffer = $this->inMapBuffer;
        $toAddress = $this->toAddress;
        $outMapBuffer = $this->outMapBuffer;

        $dotLength = explode('.', $this->toUuid);
        // ip
        if (count($dotLength) == 4) {
            $data = [
                'something' => $this->toUuid
            ];
        } else {
            $data = [
                'uuid' => $this->toUuid
            ];
        }
       

        StreamToStream::create()->from($this->fromStream, $inMapBuffer)->to($toStream = $this->call->call(SerializableClosure::serialize(function ($stream) use ($toAddress) {
            StreamToStream::create()->from($stream)->to(Factory::createConnector($toAddress)->connect($toAddress)->then(null, function ($error) use ($stream) {
                $stream->emit('error', [$error]);
            }));
            return $stream;
        }, $this->toSecretKey), $data), $outMapBuffer);
        $this->status = 2;
        $toStream->on('error', function ($e) {
            $this->errorMsg = $e->getMessage();
            $this->status = 3;
        });
        $this->fromStream->on('close', function () use ($toStream) {
            $this->stop();
            $toStream->end();
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
