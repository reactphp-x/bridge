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
php examples/helloworld/client.php server:ip:8010 c4b34f0d-44fa-4ef5-9d28-ccef218d74fb 8d24e2ba-c6f8-4bb6-8838-cacd37f64165

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