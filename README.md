# reactphp-x-bridge

one client call client service

## Install
```
composer require reactphp-x/bridge -vvv
```
## Usage

### server
```php

require __DIR__ . '/vendor/autoload.php';

use ReactphpX\Bridge\Server;
use ReactphpX\Bridge\Pool;
use ReactphpX\Bridge\Verify\VerifyUuid;
use ReactphpX\Bridge\Http\HttpBridge;
use ReactphpX\Bridge\WebSocket\WsBridge;
use ReactphpX\Bridge\Tcp\TcpBridge;
use ReactphpX\Bridge\BridgeStrategy;
use ReactphpX\Bridge\Io\Tcp;

Server::$debug = true;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165' => '10.10.10.1',//value 是自定义的标识符，可以是空
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb' => '10.10.10.2',
    '41c5ee60-0628-4b11-9439-a10ba19cbcdd' => '10.10.10.3'
]));

$pool = new Pool($server, [
    'max_connections' => 20,
    'connection_timeout' => 2,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);

new Tcp('0.0.0.0:' . ($argv[1] ?? '8010'), new BridgeStrategy([
    new TcpBridge($server),
    new HttpBridge(new WsBridge($server))
]));

return $pool;


```
### client

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ReactphpX\Bridge\Client;

Client::$debug = true;

$uri = $argv[1] ?? 'tcp://192.168.1.9:8010';
$client = new Client($uri, $argv[2] ?? '8d24e2ba-c6f8-4bb6-8838-cacd37f64165');
$client->start();

return $client;

```

### server call client
```php
// 调用客户端
$stream = $pool->call(function($stream){
    // 这里代码运行在客户端
    $stream->on('data',function($data) use ($stream) {
       echo $data."\n"; // 收到hello
       $stream->end('world');
    });
    return $stream;
}, [
    'uuid' => 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb',
]);

$stream->write('hello');

$stream->on('data', function($data){
   echo $data."\n"; // 收到world
});

$stream->on('close', function(){
   echo "stream close\n";
});

```

### client call client
```php
$stream = $client->call(function($stream){
    // 运行在另外一个客户端
     $stream->on('data',function($data) use ($stream) {
       echo $data."\n"; // 收到hello
       $stream->end('world');
    });
    return $stream;
}, [
    'uuid' => '8d24e2ba-c6f8-4bb6-8838-cacd37f64165',
    // ‘something’ => '10.8.0.1'
]);
$stream->write('hello');

$stream->on('data', function($data){
   echo $data."\n"; // 收到world
});

$stream->on('close', function(){
   echo "stream close\n";
});
```

## License
MIT

