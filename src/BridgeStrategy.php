<?php

namespace Reactphp\Framework\Bridge;

use Reactphp\Framework\Bridge\Interface\MessageComponentInterface;
use Reactphp\Framework\Bridge\Tcp\TcpBridgeInterface;
use Reactphp\Framework\Bridge\Http\HttpBridgeInterface;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
use Reactphp\Framework\Bridge\DecodeEncode\WebsocketDecodeEncode;
use React\Stream\DuplexStreamInterface;
use Reactphp\Framework\Bridge\Info;

class BridgeStrategy implements MessageComponentInterface
{
    protected $components;
    protected $streams;

    public $maxHeaderSize = 8192;

    public function __construct($components)
    {
        $this->components = $components;
        $this->streams = new \SplObjectStorage();
    }

    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        $this->streams->attach($stream, new Info([
            'local_address' => $info['local_address'],
            'remote_address' => $info['remote_address'],
            'buffer' => '',
            'component' => null
        ]));
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        if ($this->streams->contains($stream)) {
            if ($this->streams[$stream]['component']) {
                $this->streams[$stream]['component']->onClose($stream, $reason);
            }
            $this->streams->detach($stream);
        }
    }

    public function onError(DuplexStreamInterface $stream, \Exception $e)
    {
        if ($this->streams->contains($stream)) {
            if ($this->streams[$stream]['component']) {
                $this->streams[$stream]['component']->onError($stream, $e);
            }
        }
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        if ($this->streams->contains($stream)) {
            if ($this->streams[$stream]['component']) {
                $this->streams[$stream]['component']->onMessage($stream, $msg);
            } else {
                $this->streams[$stream]['buffer'] .= $msg;
                $buffer = $this->streams[$stream]['buffer'];
                if (strlen($buffer) > $this->maxHeaderSize) {
                    $stream->end();
                    return;
                }

                if (strpos($buffer, "\r\n") !== false && strpos($buffer, "HTTP") !== false) {
                    $component = $this->getComponent(HttpBridgeInterface::class);
                    if (!$component) {
                        $stream->end();
                        return;
                    }
                } else {
                    $component = $this->getComponent(TcpBridgeInterface::class);
                    if (!$component) {
                        $stream->end();
                        return;
                    }
                }

                $this->streams[$stream]['component'] = $component;
                $this->streams[$stream]['component']->onOpen($stream, [
                    'remote_address' => $this->streams[$stream]['remote_address'],
                    'local_address' => $this->streams[$stream]['local_address'],
                ]+ $this->getComponentInfo($component));
                unset($this->streams[$stream]['buffer']);
                $this->streams[$stream]['component']->onMessage($stream, $buffer);
            }
        }
    }

    protected function getComponent($class)
    {
        foreach ($this->components as $component) {
            if ($component instanceof $class) {
                return $component;
            }
        }
        return null;
    }

    protected function getComponentInfo($component)
    {
        $info = [];
        if ($this->components instanceof \SplObjectStorage) {
            $info = $this->components[$component];
        }

        if ($info) {
            return $info;
        } 

        if ($component instanceof TcpBridgeInterface) {
            return [
                'decodeEncodeClass' => TcpDecodeEncode::class
            ];
        } else if ($component instanceof HttpBridgeInterface) {
            return [
                'decodeEncodeClass' => WebsocketDecodeEncode::class
            ];
        }
        else {
            throw new \InvalidArgumentException('Unknown component');
        }
    }
}
