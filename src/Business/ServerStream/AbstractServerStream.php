<?php

namespace Reactphp\Framework\Bridge\Business\ServerStream;

use React\EventLoop\LoopInterface;

abstract class AbstractServerStream implements ServerStreamInterface
{

    protected $uri;
    protected $context;
    protected $loop;

    protected $status = false;
    protected $info;


    public function __construct($uri, array $context = array(), LoopInterface $loop = null)
    {
        $this->uri = $uri;
        $this->context = $context;
        $this->loop = $loop;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setInfo($info) 
    {
        $this->info = $info;
    }
    
    public function getInfo()
    {
        return $this->info;
    }

}