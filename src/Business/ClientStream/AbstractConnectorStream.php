<?php

namespace Reactphp\Framework\Bridge\Business\ClientStream;

abstract class AbstractConnectorStream implements ConnectorStreamInterface
{   
    // 0 = close
    // 1 = connecting
    // 2 = connected
    // 3 = connecting failed
    protected $status = 0;

    public function getStatus()
    {
        return $this->status;
    }
}