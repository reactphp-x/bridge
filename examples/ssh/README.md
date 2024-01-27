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
// php examples/ssh/call_client_ssh.php local:8022 peer:22
php examples/ssh/call_client_ssh.php 0.0.0.0:8022 127.0.0.1:22
```

## test

in client2
```
ssh -p 8022 root@127.0.0.1
```