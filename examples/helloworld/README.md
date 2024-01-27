## start server
```
php examples/tcp_server.php

```

## start client1
```
php examples/tcp_client.php

```

## client2 to call client1
```
php examples/helloworld/call_client.php
```

## output

client1
```
tunnelMessage: {"cmd":"data","uuid":"e5cb191d-ac34-44ba-a789-eec478741dec","data":"hello"}
```
client2
```
tunnelMessage: {"cmd":"data","uuid":"d5a7d6ce-3642-49c8-84f1-2a2f758623e0","data":"world"}
```