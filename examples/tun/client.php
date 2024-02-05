<?php

use React\EventLoop\Loop;
use function React\Async\async;

$client = require __DIR__ . '/../client.php';

function run_command($Command)
{
    echo '+ ', $Command, "\n";

    $rc = 0;

    passthru($Command, $rc);

    if ($rc != 0)
        echo '+ Command returned ', $rc, "\n";

    return ($rc == 0);
}

$client->on('controllerConnected', function ($data) use ($client) {

    $ip = $data['something'];
    $br = ((php_sapi_name() == 'cli') ? '' : '<br />');

    global $TUN;

    if (is_resource($TUN)) {
        return;
    }

    // Try to create a new TAP-Device
    if (!is_resource($TUN = tuntap_new('', TUNTAP_DEVICE_TUN)))
        die('Failed to create TAP-Device' . "\n");

    $Interface = tuntap_name($TUN);

    echo 'Created ', $Interface, "\n";

    run_command('ip link set ' . $Interface . ' up');
    run_command("ip addr add $ip/24 dev " . $Interface);
    run_command("iptables -t nat -A POSTROUTING -p all -d $ip/24 -j SNAT --to-source $ip");

    try {
        Loop::addSignal(\defined('SIGINT') ? \SIGINT : 2, $f1 = static function () use ($ip): void {
            if (\PHP_VERSION_ID >= 70200 && \stream_isatty(\STDIN)) {
                echo "\r";
            }

            echo "Received SIGINT, stopping loop\n";
            run_command("iptables -t nat -D POSTROUTING -p all -d $ip/24 -j SNAT --to-source $ip");
            Loop::stop();
        });
        Loop::addSignal(\defined('SIGTERM') ? \SIGTERM : 15, $f2 = static function () use ($ip): void {
            echo "Received SIGTERM, stopping loop\n";
            run_command("iptables -t nat -D POSTROUTING -p all -d $ip/24 -j SNAT --to-source $ip");
            Loop::stop();
        });
    } catch (\Exception $e){
        echo "Notice: No signal handler support, installing ext-ev or ext-pcntl recommended for production use.";
    }

    // Loop::removeSignal(\defined('SIGINT') ? \SIGINT : 2, $f1 ?? 'printf');
    // Loop::removeSignal(\defined('SIGTERM') ? \SIGTERM : 15, $f2 ?? 'printf');


    // Read Frames from the device
    echo 'Waiting for frames...', $br, "\n";


    $ipTostreams = [];

    Loop::addReadStream($TUN, async(function ($TUN) use ($client, &$ipTostreams) {
        // Try to read next frame from device
        $Data = $buffer =  fread($TUN, 8192);
        $Data = substr($Data, 4);
        if (($Length = strlen($Data)) < 20) {
            trigger_error('IPv4-Frame too short');

            return false;
        }

        // Parse default header
        $Byte = ord($Data[0]);
        $ipVersion = (($Byte >> 4) & 0xF);
        $ipHeaderLength = ($Byte & 0xF);

        if ($ipVersion != 4) {
            trigger_error('IP-Frame is version ' . $ipVersion . ', NOT IPv4');

            return false;
        } elseif (($ipHeaderLength < 5) || ($ipHeaderLength * 4 > $Length)) {
            trigger_error('IPv4-Frame too short for header');

            return false;
        }
        $ipSourceAddress = (ord($Data[12]) << 24) | (ord($Data[13]) << 16) | (ord($Data[14]) << 8) | ord($Data[15]);
        $ipSourceAddress = long2ip($ipSourceAddress);
        echo "ipSourceAddress: $ipSourceAddress\n";
        $ipTargetAddress = (ord($Data[16]) << 24) | (ord($Data[17]) << 16) | (ord($Data[18]) << 8) | ord($Data[19]);
        $ipTargetAddress = long2ip($ipTargetAddress);
        echo "ipTargetAddress: $ipTargetAddress\n";

        if ($client->getStatus() !== 1) {
            echo "client not ready\n";
            if (isset($ipTostreams[$ipTargetAddress])) {
                echo "close stream\n";
                $ipTostreams[$ipTargetAddress]->close();
                unset($ipTostreams[$ipTargetAddress]);
            }
            return;
        }

        if (isset($ipTostreams[$ipTargetAddress])) {
            if ($ipTostreams[$ipTargetAddress] === '') {
                echo "stream is connecting\n";
            } else {
                echo "write to stream\n";
                $ipTostreams[$ipTargetAddress]->write($buffer);
            }
        } else {
            echo "create stream\n";
            $ipTostreams[$ipTargetAddress] = '';
            $stream = $client->call(function ($stream, $info) {
                global $TUN;
                if (!isset($TUN) || !is_resource($TUN)) {
                    Loop::futureTick(function () use ($stream) {
                        $stream->emit('error', [new \Exception('TUN not found')]);
                    });
                    return $stream;
                }
                $stream->on('data', function ($data) use ($TUN) {
                    fwrite($TUN, $data);
                });
                return $stream;
            }, [
                'something' => $ipTargetAddress
            ]);

            $stream->write($buffer);


            $stream->on('data', function ($data) use ($TUN) {
                fwrite($TUN, $data);
            });

            $stream->on('error', function ($e) {
                echo "file: " . $e->getFile() . "\n";
                echo "line: " . $e->getLine() . "\n";
                echo $e->getMessage() . "\n";
            });

            $stream->on('close', function () use (&$ipTostreams, $ipTargetAddress) {
                echo "tun stream close\n";
                unset($ipTostreams[$ipTargetAddress]);
            });
            $ipTostreams[$ipTargetAddress] = $stream;
        }
    }));
});
