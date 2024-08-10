<?php

namespace ReactphpX\Bridge\WebSocket;

use ReactphpX\Bridge\Http\HttpBridgeInterface;
use ReactphpX\Bridge\Interface\MessageComponentInterface;
use ReactphpX\Bridge\Info;
use React\Stream\DuplexStreamInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use GuzzleHttp\Psr7\Message;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use React\EventLoop\LoopInterface;

class WsBridge implements HttpBridgeInterface
{
    protected $component;
    protected $streams;

    /**
     * @var \Ratchet\RFC6455\Messaging\CloseFrameChecker
     */
    private $closeFrameChecker;

    /**
     * @var \Ratchet\RFC6455\Handshake\ServerNegotiator
     */
    private $handshakeNegotiator;

    /**
     * @var \Closure
     */
    private $ueFlowFactory;

    /**
     * @var \Closure
     */
    private $pongReceiver;

    /**
     * @var \Closure
     */
    private $msgCb;


    public function __construct(MessageComponentInterface $component)
    {
        if (bin2hex('✓') !== 'e29c93') {
            throw new \DomainException('Bad encoding, unicode character ✓ did not match expected value. Ensure charset UTF-8 and check ini val mbstring.func_autoload');
        }


        $this->component = $component;
        $this->streams = new \SplObjectStorage();
        $this->msgCb = function (DuplexStreamInterface $stream, MessageInterface $msg) {
            $this->component->onMessage($stream, $msg);
        };

        $this->closeFrameChecker   = new CloseFrameChecker;
        $this->handshakeNegotiator = new ServerNegotiator(new RequestVerifier);
        $this->handshakeNegotiator->setStrictSubProtocolCheck(true);
        if ($component instanceof WsServerInterface) {
            $this->handshakeNegotiator->setSupportedSubProtocols($component->getSubProtocols());
        }
        $this->pongReceiver = function () {
        };

        $reusableUnderflowException = new \UnderflowException;
        $this->ueFlowFactory = function () use ($reusableUnderflowException) {
            return $reusableUnderflowException;
        };
    }

    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        $request = $info['request'] ?? null;
        if (null === $request) {
            throw new \UnexpectedValueException('$request can not be null');
        }

        $response = $this->handshakeNegotiator->handshake($request)->withHeader('X-Powered-By', 'reactphp-x/Bridge/alpha');

        $stream->write(Message::toString($response));
        if (101 !== $response->getStatusCode()) {
            return $stream->close();
        }

        $middleInStream  = new ThroughStream();
        $middleOutStream = new ThroughStream(function ($data) use (&$streamer) {
            if ($data instanceof Frame) {
                $streamer->sendFrame($data);
            } else if ($data instanceof MessageInterface) {
                $streamer->sendMessage($data->getPayload(), true, $data->isBinary());
            } else {
                $streamer->sendMessage($data);
            }
        });

        $middleStream = new CompositeStream($middleInStream, $middleOutStream);

        $middleStream->on('close', function () use ($stream) {
            if ($stream) {
                $stream->end(new Frame(pack('n', 1000), true, Frame::OP_CLOSE));
            }
        });

        $streamer = new MessageBuffer(
            $this->closeFrameChecker,
            function (MessageInterface $msg) use ($middleStream) {
                $cb = $this->msgCb;
                $middleStream->emit('data', [$msg->getPayload()]);
                $cb($middleStream, $msg);
            },
            function (FrameInterface $frame) use ($middleStream) {
                $this->onControlFrame($frame, $middleStream);
            },
            true,
            $this->ueFlowFactory,
            null,
            null,
            [$stream, 'write']
        );

        $info['streamer'] = $streamer;
        $info['middle_stream'] = $middleStream;

        $this->streams->attach($stream, $info);
        $this->component->onOpen($middleStream, $info);
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        if ($this->streams->contains($stream)) {
            $middleStream = $this->streams[$stream]['middle_stream'];
            $middleStream->close();
            $this->streams->detach($stream);
            $this->component->onClose($middleStream, $reason);
        }
    }

    public function onError(DuplexStreamInterface $stream, \Exception $e)
    {
        if ($this->streams->contains($stream)) {
            $this->streams[$stream]['middle_stream']->emit('error', [$e]);
            $this->component->onError($this->streams[$stream]['middle_stream'], $e);
        } else {
            $stream->end(new Frame(pack('n', 1000), true, Frame::OP_CLOSE));
        }
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        $this->streams[$stream]['streamer']->onData($msg);
    }

    public function enableKeepAlive(LoopInterface $loop, $interval = 30)
    {
        $lastPing = new Frame(uniqid(), true, Frame::OP_PING);
        $pingedConnections = new \SplObjectStorage;
        $splClearer = new \SplObjectStorage;

        $this->pongReceiver = function (FrameInterface $frame, $wsConn) use ($pingedConnections, &$lastPing) {
            if ($frame->getPayload() === $lastPing->getPayload()) {
                $pingedConnections->detach($wsConn);
            }
        };

        $loop->addPeriodicTimer((int)$interval, function () use ($pingedConnections, &$lastPing, $splClearer) {
            foreach ($pingedConnections as $wsConn) {
                $wsConn->close();
            }
            $pingedConnections->removeAllExcept($splClearer);

            $lastPing = new Frame(uniqid(), true, Frame::OP_PING);

            foreach ($this->streams as $stream) {
                $stream->write($lastPing);
                $pingedConnections->attach($wsConn);
            }
        });
    }

    private function onControlFrame(FrameInterface $frame, DuplexStreamInterface $stream)
    {
        switch ($frame->getOpCode()) {
            case Frame::OP_CLOSE:
                $stream->end($frame);
                break;
            case Frame::OP_PING:
                $stream->write(new Frame($frame->getPayload(), true, Frame::OP_PONG));
                break;
            case Frame::OP_PONG:
                $pongReceiver = $this->pongReceiver;
                $pongReceiver($frame, $stream);
                break;
        }
    }
}
