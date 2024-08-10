<?php

namespace ReactphpX\Bridge\Http;

use React\Stream\DuplexStreamInterface;
use ReactphpX\Bridge\Info;
use GuzzleHttp\Psr7\Message;

class HttpBridge implements HttpBridgeInterface
{

    protected $component;

    protected $streams;

    protected $maxHeaderSize = 8192;

    public function __construct(HttpBridgeInterface $component, $maxHeaderSize = 8192)
    {
        $this->component = $component;
        $this->maxHeaderSize = $maxHeaderSize;
        $this->streams = new \SplObjectStorage();
    }


    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        $this->streams->attach($stream, new Info($info + [
            'http_headers_received' => false,
            'http_buffer' => '',
        ]));
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        if ($this->streams[$stream]['http_headers_received']) {
            // if we already received the HTTP headers, emit the message as is
            $this->component->onMessage($stream, $msg);
        } else {
            $this->streams[$stream]['http_buffer'] .= $msg;
            if (strlen($this->streams[$stream]['http_buffer']) > $this->maxHeaderSize) {
                $stream->end(implode("\r\n", [
                    'HTTP/1.1 431 Request Header Fields Too Large',
                    'Connection: close',
                    '',
                    '',
                ]));
                return;
            }
            $p = strpos($this->streams[$stream]['http_buffer'], "\r\n\r\n");
            if ($p !== false) {
                $httpBuffer = $this->streams[$stream]['http_buffer'];
                unset($this->streams[$stream]['http_buffer']);
                $request = Message::parseRequest(substr($httpBuffer, 0, $p + 4));
                $this->streams[$stream]['http_headers_received'] = true;
                $this->component->onOpen($stream, $this->streams[$stream]->toArray() + [
                    'request' => $request,
                ]);

                // if there is a message body, emit it as well
                if ($p + 4 < strlen($httpBuffer)) {
                    $this->component->onMessage($stream, substr($httpBuffer, $p + 4));
                }
            }
        }
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        if ($this->streams->contains($stream)) {
            if ($this->streams[$stream]['http_headers_received']) {
                $this->component->onClose($stream, $reason);
            }
            $this->streams->detach($stream);
        }
    }

    public function onError(DuplexStreamInterface $stream, \Exception $e)
    {
        if ($this->streams->contains($stream) && $this->streams[$stream]['http_headers_received']) {
            $this->component->onError($stream, $e);
        } else {
            // 500 
            $stream->end(implode("\r\n", [
                'HTTP/1.1 500 Internal Server Error',
                'Connection: close',
                '',
                '',
            ]));
        }
    }
}
