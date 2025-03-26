<?php

namespace ReactphpX\Bridge\Pool;

interface ConnectionPoolInterface
{
    public function getConnection();
    public function releaseConnection($connection);
}