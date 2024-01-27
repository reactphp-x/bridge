## start server
```
php examples/tcp_server.php

```

## start client1
```
php examples/tcp_client.php

```

## start client2 in another computer
```
// php examples/ssh/port_to_port.php local:63306 peer:3306
php examples/ssh/port_to_port.php 0.0.0.0:63306 127.0.0.1:3306
```

## test

in client2 login to client1 mysql by port 633006
