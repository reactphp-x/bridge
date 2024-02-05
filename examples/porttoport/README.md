## start server
```
php examples/server.php 8010

```

## start client1
```
php examples/client.php server_ip:8010 8d24e2ba-c6f8-4bb6-8838-cacd37f64165

```

## start client2 in another computer
```
php examples/porttoport/client.php server_ip:8010 c4b34f0d-44fa-4ef5-9d28-ccef218d74fb 0.0.0.0:63306 8d24e2ba-c6f8-4bb6-8838-cacd37f64165 127.0.0.1:3306
```

## test

in client2 login to client1 mysql by port 63306
