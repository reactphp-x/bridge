# reactphp-framework-bridge

one client call client service

## Install
```
composer require reactphp-framework-bridge -vvv
```
## Usage

### server
```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Bridge\Server;
use Reactphp\Framework\Bridge\Pool;
use Reactphp\Framework\Bridge\Verify\VerifyUuid;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
use Reactphp\Framework\Bridge\TcpBridge;

Server::$debug = true;

$server = new Server(new VerifyUuid([
    '8d24e2ba-c6f8-4bb6-8838-cacd37f64165' => '10.10.10.1',//value 是自定义的标识符，可以是空
    'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb' => '10.10.10.2',
    '41c5ee60-0628-4b11-9439-a10ba19cbcdd' => '10.10.10.3'
]), new TcpDecodeEncode);



$pool = new Pool($server, [
    'max_connections' => 20,
    'connection_timeout' => 2,
    'keep_alive' => 5,
    'wait_timeout' => 3
]);

$tcp = new TcpBridge('0.0.0.0:8010', $server);

```
### client

```php
<?php

require __DIR__ . '/../../vendor/autoload.php';

use Reactphp\Framework\Bridge\Client;
use Reactphp\Framework\Bridge\DecodeEncode\TcpDecodeEncode;
use React\EventLoop\Loop;
use function React\Async\async;

Client::$debug = true;
$uuid = $argv[1] ?? 'c4b34f0d-44fa-4ef5-9d28-ccef218d74fb';
echo "uuid: $uuid\n";

$uri = 'tcp://192.168.1.9:8010';
$client = new Client($uri, $uuid, new TcpDecodeEncode);
$client->start();
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

