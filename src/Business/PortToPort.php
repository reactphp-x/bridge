<?php

namespace ReactphpX\Bridge\Business;

use ReactphpX\Bridge\Interface\CallInterface;
use function React\Async\async;
use ReactphpX\Bridge\Business\ServerStream\Factory as ServerStreamFactory;

// one stream from uuid address port to another uuid address port

final class PortToPort
{
    protected $call;
    protected $fromUuid;

    protected $fromAddress;
    protected $inMapBuffer;

    protected $toUuid;
    protected $toAddress;
    protected $toSecretKey;
    protected $outMapBuffer;

    protected $protocol;

    protected $serverStream;

    public static function create(CallInterface $call, $protocol = 'tcp')
    {
        return new static($call, $protocol);
    }


    public function __construct(CallInterface $call, $protocol = 'tcp')
    {
        $this->call = $call;
        $this->protocol = $protocol;
    }

    public function from($fromUuid, $address, $inMapBuffer = null)
    {
        $this->fromUuid = $fromUuid;
        $this->fromAddress = $address;
        $this->inMapBuffer = $inMapBuffer;
        return $this;
    }


    public function to($uuid, $address, $outMapBuffer = null, $toSecretKey = null)
    {
        $this->toUuid = $uuid;
        $this->toAddress = $address;
        $this->toSecretKey = $toSecretKey;
        $this->outMapBuffer = $outMapBuffer;
        return $this;
    }

    public function start()
    {
        if ($this->serverStream) {
            return;
        }
        $serverStream = ServerStreamFactory::createServerStream($this->protocol . '://' . (strpos(':', $this->fromAddress) === false ? "0.0.0.0:{$this->fromAddress}" : $this->fromAddress));
        $serverStream->on('stream', async(function ($connection, $info) {
            // 这里可对connection 的流量解析后在转发到client(对于http 协议分析domain 转化为相对应的uuid)
            StreamToPort::create($this->call)
                ->from($this->fromUuid, $info['local_address'] ?? '', $connection, $this->inMapBuffer)
                ->to($this->toUuid, $this->toAddress, $this->outMapBuffer, $this->toSecretKey)
                ->start();
        }));
        $serverStream->on('error', function ($error) {
            echo "serverStream error: {$error->getMessage()}\n";
        });
        $serverStream->start();
        $this->serverStream = $serverStream;
        return $this;
    }

    public function stop()
    {
        if ($this->serverStream) {
            $this->serverStream->stop();
            $this->serverStream = null;
        }
    }

    public function getStatus()
    {
        return $this->serverStream ? $this->serverStream->getStatus() : false;
    }
}
