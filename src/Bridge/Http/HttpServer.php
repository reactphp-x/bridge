<?php

namespace Reactphp\Framework\Bridge\Http;

use Reactphp\Framework\Bridge\Interface\MessageComponentInterface;
use React\Stream\DuplexStreamInterface;
use Reactphp\Framework\Bridge\Info;
use GuzzleHttp\Psr7\Message;

class HttpBridge implements MessageComponentInterface
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
        $this->streams->attach($stream, new Info([
            'remote_address' => $info['remote_address'] ?? '',
            'local_address' => $info['local_address'] ?? '',
            'http_headers_received' => false,
            'http_buffer' => '',
        ]));
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        if ($this->streams[$stream]['http_headers_received']) {
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

            if (strpos($this->streams[$stream]['http_buffer'], "\r\n\r\n") !== false) {
                $httpBuffer = $this->streams[$stream]['http_buffer'];
                unset($this->streams[$stream]['http_buffer']);
                $request = Message::parseRequest(substr($httpBuffer, 0, strpos($httpBuffer, "\r\n\r\n")));
                $this->streams[$stream]['http_headers_received'] = true;
                $this->component->onOpen($stream, [
                    'remote_address' => $this->streams[$stream]['remote_address'],
                    'local_address' => $this->streams[$stream]['local_address'],
                    'request' => $request,
                ]);
                if (strpos($httpBuffer, "\r\n\r\n") + 4 < strlen($httpBuffer)) {
                    $this->component->onMessage($stream, substr($httpBuffer, strpos($httpBuffer, "\r\n\r\n") + 4));
                }
            }
        }
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        $this->streams->detach($stream);
        if ($this->streams[$stream]['http_headers_received']) {
            $this->component->onClose($stream, $reason);
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
