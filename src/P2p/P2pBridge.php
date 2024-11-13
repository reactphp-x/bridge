<?php

namespace ReactphpX\Bridge\P2p;

use React\Stream\DuplexStreamInterface;
use ReactphpX\Bridge\Interface\MessageComponentInterface;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use React\EventLoop\Loop;
use function React\Async\async;

use labalityowo\kcp\KCP;
use labalityowo\Bytebuffer\Buffer;

class P2pBridge implements P2pBridgeInterface
{

    protected $component;
    protected $client;
    protected $socket;
    public $localAddress;
    public $remoteAddress;


    protected $addressToStream = [];


    protected $kcpTimer = null;
    protected $addressToKCP = [];

    protected $uuidOrIpToAddress = [];
    protected $addressDeferred = [];
    protected $addressTimer = [];

    public function __construct(MessageComponentInterface $component, $client)
    {
        $this->component = $component;
        $this->client = $client;
    }

    public function onOpen(DuplexStreamInterface $stream, $info = null)
    {
        $this->component->onOpen($stream, $info);
    }

    public function onClose(DuplexStreamInterface $stream, $reason = null)
    {
        $this->component->onClose($stream, $reason);
    }

    public function onError(DuplexStreamInterface $conn, \Exception $e)
    {
        $this->component->onError($conn, $e);
    }

    public function onMessage(DuplexStreamInterface $stream, $msg)
    {
        $this->component->onMessage($stream, $msg);
    }

    public function createUdpServer($localAddress, $remoteAddress)
    {

        if (str_contains($localAddress, '//')) {
            $localAddress = explode('//', $localAddress)[1];
        }

        if (str_contains($remoteAddress, '//')) {
            $remoteAddress = explode('//', $remoteAddress)[1];
        }

        $this->localAddress = $localAddress;
        $this->remoteAddress = $remoteAddress;

        var_dump($this->localAddress, $this->remoteAddress);

        if ($this->socket) {
            if ($this->socket->getLocalAddress() == $localAddress) {
                return;
            } else {
                $this->socket->close();
            }
        }

        $factory = new \React\Datagram\Factory();
        $factory->createServer($localAddress)->then(function (\React\Datagram\Socket $server) use ($localAddress) {
            $this->kcpTimer = Loop::addPeriodicTimer(0.05, async(function () {
                foreach ($this->addressToKCP as $address => $kcp) {
                    $kcp->update((int)(hrtime(true) / 1000000));

                    $buffer = Buffer::allocate(1024 * 1024 * 50);
                    $size = $kcp->recv($buffer);
                    if ($size > 0) {
                        $message = $buffer->slice(0, $size)->toString();
                        $this->addressToStream[$address]->emit('data', [$message]);
                        $this->onMessage($this->addressToStream[$address], $message);
                    }
                    echo 'kcp update getWaitSnd ' .$kcp->getWaitSnd(). "\n";
                }
            }));
            $server->on('close', function () {
                foreach ($this->addressToStream as $address => $stream) {
                    $stream->close();
                    $this->onClose($stream);
                }
                if ($this->kcpTimer) {
                    Loop::cancelTimer($this->kcpTimer);
                    $this->kcpTimer = null;
                }
                $this->addressToStream = [];
            });
            $this->socket = $server;
            echo 'local_server ppppppppp' . '-' . $server->getLocalAddress() . "\n";
            $server->on('message', function ($message, $address, $server) {
                echo 'from client ppppppppp' . $address . ': ' . $message . PHP_EOL;
                if (isset($this->addressToStream[$address])) {
                    $this->addressToStream[$address]->emit('touch', []);

                    if (isset($this->addressDeferred[$address])) {
                        $this->addressDeferred[$address]->resolve($this->addressToStream[$address]);
                        unset($this->addressDeferred[$address]);
                    }
                    $this->destroyAddressTimer($address);

                    if ($message == 'pending' || $message == 'connected') {
                        return;
                    }

                    // kcp 
                    $kcp = $this->addressToKCP[$address];
                    $kcp->input(Buffer::new($message));

                    // $this->addressToStream[$address]->emit('data', [$message]);
                    // $this->onMessage($this->addressToStream[$address], $message);
                } else {
                    if ($message == 'pending') {
                        $this->socket->send('connected', $address);
                    } else if ($message == 'connected') {
                        $this->socket->send('connected', $address);
                        $middleInStream  = new ThroughStream();
                        $middleOutStream = new ThroughStream(function ($data) use ($address) {
                            // kcp
                            $kcp = $this->addressToKCP[$address];
                            $kcp->send(Buffer::new($data));
                            $kcp->flush();
                            // $this->socket->send($data, $address);
                        });
                        $middleStream = new CompositeStream($middleInStream, $middleOutStream);
                        $this->onOpen($middleStream, [
                            'type' => 'p2p',
                            'local_address' => $this->socket->getLocalAddress(),
                            'remote_address' => $address
                        ]);


                        $middleStream->on('close', function () use ($address) {

                            echo "$address close\n";

                            // 清除 stream
                            unset($this->addressToStream[$address]);

                            // 清除 timer
                            $this->destroyAddressTimer($address);

                            // 清除 deferred
                            if (isset($this->addressDeferred[$address])) {
                                $this->addressDeferred[$address]->reject(new \Exception('close'));
                                unset($this->addressDeferred[$address]);
                            }

                            // 清除 kcp
                            if (isset($this->addressToKCP[$address])) {
                                unset($this->addressToKCP[$address]);
                            }

                            // 清除对应关系
                            $uuidOrIpToAddress = $this->uuidOrIpToAddress;
                            $uuidOrIp = array_search($address, $uuidOrIpToAddress);
                            if ($uuidOrIp) {
                                unset($this->uuidOrIpToAddress[$uuidOrIp]);
                            }
                        });

                        $this->addressToStream[$address] = $middleStream;

                        $this->addressToKCP[$address] = $kcp = new KCP(11, 22, function (Buffer $buffer) use ($middleStream, $address) {
                            // $middleStream->write($buffer->toString());
                            $this->socket->send($buffer->toString(), $address);

                        });

                        $kcp->setNodelay(true, 5, true);
                        $kcp->setWndSize(1024, 1024);
                        $kcp->setInterval(5);

                        if (isset($this->addressDeferred[$address])) {
                            $this->addressDeferred[$address]->resolve($middleStream);
                            unset($this->addressDeferred[$address]);
                        }

                        $this->destroyAddress($address);
                        $this->destroyAddressTimer($address);
                    }
                }
            });
        });
    }

    public function destroyAddress($address) {

    }

    private function destroyAddressTimer($address)
    {
        if (isset($this->addressTimer[$address])) {
            Loop::cancelTimer($this->addressTimer[$address]);
            unset($this->addressTimer[$address]);
        }
    }

    public function peer($params)
    {

        $uuidOrIp = $params['uuid'] ?? $params['something'];

        if (isset($this->uuidOrIpToAddress[$uuidOrIp])) {
            if (!$this->hasPeer($uuidOrIp)) {
                return $this->addressDeferred[$this->uuidOrIpToAddress[$uuidOrIp]]->promise();
            } else {
                return \React\Promise\resolve($this->getPeer($uuidOrIp));
            }
        }
        $deferred = new \React\Promise\Deferred();

        $data = [];
        if (str_contains($uuidOrIp, '.')) {
            $data['something'] = $uuidOrIp;
        } else {
            $data['uuid'] = $uuidOrIp;
        }


        $remoteAddress = $this->remoteAddress;
        $stream = $this->client->call(function ($stream, $info, $client) use ($remoteAddress) {
            $client->p2pBridge->pendingPeer($remoteAddress);
            $stream->write([
                'local_address' => $client->p2pBridge->localAddress,
                'remote_address' => $client->p2pBridge->remoteAddress,
            ]);
            return $stream;
        }, $data, [
            'server_request' => true
        ]);

        $address = null;
        $stream->on('data', function ($data) use ($stream, $uuidOrIp, $deferred, &$address) {

            if ($this->equalPublicIp($data['remote_address'], $this->remoteAddress)) {
                $address = $data['local_address'];
            } else {
                $address = $data['remote_address'];
            }            

            $this->uuidOrIpToAddress[$uuidOrIp] = $address;
            if ($this->hasPeer($uuidOrIp)) {
                $deferred->resolve($this->getPeer($uuidOrIp));
            } else {
                $this->addressDeferred[$address] = $deferred;
                $this->pendingPeer($address);
            }
            $stream->close();
        });

        $stream->on('close', function () {
            echo "close\n";
        });

        return \React\Promise\Timer\timeout($deferred->promise(), 2.5)->then(null, function ($error) use (&$address) {
            if ($address) {
                $this->destroyAddressTimer($address);
            }
            throw $error;
        });
    }

    public function hasPeer($uuidOrIp)
    {
        return isset($this->uuidOrIpToAddress[$uuidOrIp]) && isset($this->addressToStream[$this->uuidOrIpToAddress[$uuidOrIp]]);
    }

    public function pendingPeer($address)
    {
        if (isset($this->addressTimer[$address])) {
            return;
        }

        $this->addressTimer[$address] = Loop::addPeriodicTimer(1, function () use ($address) {
            var_dump('pending', $address);
            $this->socket->send('pending', $address);
        });

        Loop::addTimer(60, function () use ($address) {
            $this->destroyAddressTimer($address);
        });
    }

    public function getPeer($uuidOrIp)
    {

        if (!$this->hasPeer($uuidOrIp)) {
            return null;
        }
        return $this->addressToStream[$this->uuidOrIpToAddress[$uuidOrIp]];
    }

    private function equalPublicIp($address1, $address2)
    {
        return explode(':', $address1)[0] == explode(':', $address2)[0];
    }
}
