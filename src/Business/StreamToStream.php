<?php

namespace Reactphp\Framework\Bridge\Business;

use React\Stream\DuplexStreamInterface;
use React\Promise\PromiseInterface;
use React\Stream\ThroughStream;

final class StreamToStream
{
    protected $fromStream;
    protected $toStream;
    protected $buffer = '';
    protected $fn;
    protected $status = false;
    protected $errorMsg;

    protected $inMapBuffer;
    protected $outMapBuffer;

    public static function create()
    {
        return new static;
    }

    public function from($fromStream, $inMapBuffer = null)
    {
        assert($fromStream instanceof DuplexStreamInterface);
        $this->inMapBuffer = $inMapBuffer;

        $this->fromStream = $fromStream;
        $this->fn = function ($data) {
            if ($this->inMapBuffer) {
                $data = call_user_func($this->inMapBuffer, $data);
            }
            $this->buffer .= $data;
        };
        $this->status = true;
        $this->fromStream->on('data', $this->fn);
        $this->fromStream->on('close', function () {
            $this->status = false;
            $this->fn = null;
            $this->buffer = '';
        });
        return $this;
    }

    public function to($stream, $outMapBuffer = null)
    {
        if (!$this->outMapBuffer) {
            $this->outMapBuffer = $outMapBuffer;
        }

        $this->toStream = $stream;
        if (is_callable($stream)) {
            $this->to($stream());
            return;
        } elseif ($stream instanceof PromiseInterface) {
            $stream->then(function ($stream) {
                $this->to($stream);
            }, function ($error) {
                $this->fromStream->emit('error', [$error]);
            });
            return;
        }

        assert($stream instanceof DuplexStreamInterface);
        if (!$this->status) {
            $stream->end();
            return;
        }

        $inMapBuffer = $this->inMapBuffer;
        if ($inMapBuffer) {
            $inMapBuffer = $inMapBuffer->bindTo(null, null);
        }
        $outMapBuffer = $this->outMapBuffer;
        if ($outMapBuffer) {
            $outMapBuffer = $outMapBuffer->bindTo(null, null);
        }

        $inStream = new ThroughStream($inMapBuffer);
        $outStream = new ThroughStream($outMapBuffer);


        if ($this->fn) {
            $this->fromStream->removeListener('data', $this->fn);
            $this->fn = null;
        }

        if ($this->buffer) {
            $stream->write($this->buffer);
            $this->buffer = '';
        }

        $this->fromStream->pipe($inStream)->pipe($stream, [
            'end' => true
        ]);

        $stream->pipe($outStream)->pipe($this->fromStream, [
            'end' => true
        ]);

        $stream->on('error', function ($e) {
            var_dump($e->getMessage());
            $this->errorMsg = $e->getMessage();
            $this->status = 3;
        });
        $stream->on('close', function () {
            $this->fromStream->close();
        });
    }

    public function getInfo()
    {
        return [
            'status' => $this->status,
            'errorMsg' => $this->errorMsg
        ];
    }
}
