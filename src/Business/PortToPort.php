<?php

namespace Reactphp\Framework\Bridge\Business;

use Reactphp\Framework\Bridge\Interface\CallInterface;
use function React\Async\async;
use Reactphp\Framework\Bridge\Business\ServerStream\Factory as ServerStreamFactory;

// one stream from uuid address port to another uuid address port

final class PortToPort
{
    protected $call;
    protected $fromUuid;

    protected $fromAddress;
    protected $inMapBuffer;

    protected $toUuid;
    protected $toAddress;
    protected $outMapBuffer;

    protected $protocol;

    protected $serverStream;


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


    public function to($uuid, $address, $outMapBuffer = null)
    {
        $this->toUuid = $uuid;
        $this->toAddress = $address;
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
            (new StreamToClient($this->call))
                ->from($this->fromUuid, $info['local_address'] ?? '', $connection, $this->inMapBuffer)
                ->to($this->toUuid, $this->toAddress, $this->outMapBuffer)
                ->start();
        }));
        $serverStream->on('error', function ($error) {
            echo "serverStream error: {$error->getMessage()}\n";
        });
        $serverStream->start();
        $this->serverStream = $serverStream;
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
