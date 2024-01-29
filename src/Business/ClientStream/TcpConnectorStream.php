<?php

namespace Reactphp\Framework\Bridge\Business\ClientStream;


use React\Socket\Connector;
use React\Socket\ConnectorInterface;


class TcpConnectorStream extends AbstractConnectorStream
{

    protected $connector;
    protected $stream;

    public function __construct(ConnectorInterface $connector = null)
    {
        $this->connector = $connector ?? new Connector();
    }

    public function connect($uri)
    {
        if ($this->status == 1) {
            return \React\Promise\reject(new \RuntimeException('Already connecting'));
        }

        if ($this->status == 2) {
            return \React\Promise\resolve($this->stream);
        }

        $this->status = 1;

        return $this->connector->connect($uri)->then(function ($stream) {
            if ($this->status == 0) {
                $stream->close();
                throw new \RuntimeException('Connection cancelled');
            }
            $this->status = 2;
            $this->stream = $stream;
            $stream->on('close', function () {
                $this->disconnect();
            });
            return $stream;
        }, function ($e) {
            $this->status = 0;
            throw $e;
        });
    }

    public function disconnect()
    {
        if ($this->status != 0) {
            $this->status = 0;
            if ($this->stream) {
                $this->stream->close();
                $this->stream = null;
            }
        }
    }
}
