## start server
```
php examples/server.php 8010 

```

## start client1
```
php examples/client.php server:ip:8010 8d24e2ba-c6f8-4bb6-8838-cacd37f64165

```

## start client2
```
php examples/ipaddress/client.php server:ip:8010 c4b34f0d-44fa-4ef5-9d28-ccef218d74fb 8d24e2ba-c6f8-4bb6-8838-cacd37f64165

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