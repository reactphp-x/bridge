<?php

namespace Reactphp\Framework\Bridge\Interface;

interface CallInterface
{
    public function call($closure, $params = null, $data = []);

}