<?php

namespace Reactphp\Framework\Bridge\Business\ServerStream;

use Evenement\EventEmitterInterface;

interface ServerStreamInterface extends EventEmitterInterface
{
    public function start();
    public function stop();

    public function getStatus();

}