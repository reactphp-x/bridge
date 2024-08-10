<?php

namespace ReactphpX\Bridge\Interface;

interface CallInterface
{
    public function call($closure, $params = null, $data = []);

}