<?php

namespace Reactphp\Framework\Bridge\Interface;

interface DecodeEncodeInterface
{

    public function encode($data);

    public function decode($data);
    
}