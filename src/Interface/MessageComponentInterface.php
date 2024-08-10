<?php

namespace ReactphpX\Bridge\Interface;

use React\Stream\DuplexStreamInterface;

interface MessageComponentInterface
{
    /**
     * When a new connection is opened it will be passed to this method
     * @param  DuplexStreamInterface $stream The socket/connection that just connected to your application
     * @param  $info The init info
     * @throws \Exception
     */
    function onOpen(DuplexStreamInterface $stream, $info = null);

    /**
     * This is called before or after a socket is closed (depends on how it's closed).  SendMessage to $conn will not result in an error if it has already been closed.
     * @param  DuplexStreamInterface $stream The socket/connection that is closing/closed
     * @param  string $reason The socket/connection closing/closed $reason
     * @throws \Exception
     */
    function onClose(DuplexStreamInterface $stream, $reason = null);

    /**
     * If there is an error with one of the sockets, or somewhere in the application where an Exception is thrown,
     * the Exception is sent back down the stack, handled by the Server and bubbled back up the application through this method
     * @param  DuplexStreamInterface $stream
     * @param  \Exception          $e
     * @throws \Exception
     */
    function onError(DuplexStreamInterface $stream, \Exception $e);

    /**
     * Triggered when a client sends data through the socket
     * @param  DuplexStreamInterface $stream The socket/connection that sent the message to your application
     * @param  string                       $msg  The message received
     * @throws \Exception
     */
    function onMessage(DuplexStreamInterface $stream, $msg);
}
