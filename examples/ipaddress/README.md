## start server
```
php examples/tcp_server.php

```

## start client1
```
php examples/tcp_client.php

```

## start client2
```
php examples/ipaddress/call_client_ip_address.php

```

## ouput

in client2
```
array(2) {
  ["self"]=>
  array(2) {
    ["local_address"]=>
    string(17) "192.168.1.9:49656"
    ["remote_address"]=>
    string(23) "tcp://192.168.1.9:49656"
  }
  ["peer"]=>
  array(2) {
    ["local_address"]=>
    string(17) "192.168.1.9:37444"
    ["remote_address"]=>
    string(23) "tcp://192.168.1.9:37444"
  }
}
```