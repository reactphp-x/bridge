<?php

namespace ReactphpX\Bridge\P2p;

use React\Stream\DuplexStreamInterface;
use ReactphpX\Bridge\Interface\MessageComponentInterface;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use React\EventLoop\Loop;
use function React\Async\async;
use ReactphpX\Bandwidth\Bandwidth;

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

    protected $isSupportBandwidth = false;

    protected $isSupportKcp = true;
    protected $isSupportKcpExt = true;

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

    private function getMillisecond()
    {
        
        list($seconds, $nanoseconds) = hrtime();

        $seconds =  $seconds * 1e3 + floor($nanoseconds / 1e6);

        return $seconds;
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
            if ($this->isSupportKcp) {
                $this->kcpTimer = Loop::addPeriodicTimer(0.05, async(function () {
                    foreach ($this->addressToKCP as $address => $kcp) {

                        if ($this->isSupportKcpExt) {
                            kcp_update($kcp, $this->getMillisecond());
                            $size = 0;

                            while ($message = kcp_recv($kcp, 1024 * 1024 * 10)) {
                                $size = strlen($message);
                                $this->addressToStream[$address]->emit('data', [$message]);
                                $this->onMessage($this->addressToStream[$address], $message);
                                echo 'kcp ext update size getWaitSnd ' . $size .' '. kcp_waitsnd($kcp) . "\n";
                            }

                           

                        } else {
                            $kcp->update($this->getMillisecond());
                            $buffer = Buffer::allocate(1024 * 1024 * 50);
                            $size = $kcp->recv($buffer);
                            if ($size > 0) {
                                $message = $buffer->slice(0, $size)->toString();
                                $this->addressToStream[$address]->emit('data', [$message]);
                                $this->onMessage($this->addressToStream[$address], $message);
                            }
                            echo 'kcp update size getWaitSnd ' . $size .' '. $kcp->getWaitSnd() . "\n";
                        }
                       

                    }
                }));
            }
           
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
                // echo 'from client ppppppppp' . $address . ': ' . $message . PHP_EOL;
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
                    if ($this->isSupportKcp) {
                        // kcp 
                        $kcp = $this->addressToKCP[$address];

                        if ($this->isSupportKcpExt) {
                            kcp_input($kcp, $message);
                        } else {
                            $kcp->input(Buffer::new($message));
                        }
                        // $kcp->update($this->getMillisecond());
                    } else {
                        var_dump('222222222');
                        $this->addressToStream[$address]->emit('data', [$message]);
                        $this->onMessage($this->addressToStream[$address], $message);
                    }
                   
                    
                } else {
                    if ($message == 'pending') {
                        $this->socket->send('connected', $address);
                    } else if ($message == 'connected') {
                        $this->socket->send('connected', $address);
                        $middleInStream  = new ThroughStream();
                        $bandstream = new ThroughStream();
                        $middleOutStream = new ThroughStream(function ($data) use ($address, $bandstream) {

                            // kcp
                            // $kcp = $this->addressToKCP[$address];
                            // $kcp->send(Buffer::new($data));
                            // $kcp->flush();

                            $maxSegmentSize = 1024; // 每段最大字节数
                            // 将数据分割成小段
                            $dataLength = strlen($data);
                            echo "send data length: " . $dataLength . "\n";

                            for ($i = 0; $i < $dataLength; $i += $maxSegmentSize) {
                                $segment = substr($data, $i, $maxSegmentSize);
                                echo "send data segment length: " . strlen($segment) . "\n";

                                if ($this->isSupportBandwidth) {
                                    $bandstream->write($segment);
                                } else {
                                    if ($this->isSupportKcp) {
                                        $kcp = $this->addressToKCP[$address];
                                        if ($this->isSupportKcpExt) {
                                            kcp_send($kcp, $segment);
                                            kcp_flush($kcp);
                                        } else {
                                            $kcp->send(Buffer::new($segment));
                                            $kcp->flush();
                                        }
                                    } else {
                                        var_dump('111111111');
                                        $this->socket->send($segment, $address);
                                    }
                                }
                            }
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
                                if ($this->isSupportKcpExt) {
                                    kcp_release($this->addressToKCP[$address]);
                                }
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

                        if ($this->isSupportKcp) {

                            if ($this->isSupportKcpExt) {
                                $kcp = kcp_create(123, 111);
                                kcp_set_output_callback($kcp, function ($buffer = '', $len = 0) use ($address) {
                                    $this->socket->send($buffer, $address);
                                });
                                kcp_nodelay($kcp, 1, 10, 2, 1);
                                kcp_wndsize($kcp, 1024, 2048);
                                kcp_set_rx_minrto($kcp, 10);

                            } else {
                                $kcp = new KCP(11, 22, function (Buffer $buffer) use ($address) {
                                    $this->socket->send($buffer->toString(), $address);
                                });
                                $kcp->setNodelay(true, 1, true);
                                $kcp->setWndSize(1024, 2048);
                                $kcp->setInterval(5);
                                $kcp->setRxMinRto(10);
                            }

                            $this->addressToKCP[$address] = $kcp;
                          
    
                        }

                        (new Bandwidth(1024 * 1024 * 150, 1024 * 1024 * 150))->stream($bandstream)->on('data', function ($data) use ($address) {
                            echo "send data length: " . strlen($data) . "\n";
                            
                            if ($this->isSupportKcp) {
                                // kcp
                                $kcp = $this->addressToKCP[$address];

                                if ($this->isSupportKcpExt) {
                                    kcp_send($kcp, $data);
                                    kcp_flush($kcp);
                                } else {
                                    $kcp->send(Buffer::new($data));
                                    $kcp->flush();
                                }
                            } else {
                                $this->socket->send($data, $address);
                            }
                        });


                       
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

    public function destroyAddress($address) {}

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
