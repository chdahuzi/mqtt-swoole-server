<?php
require_once __DIR__.'/Packet.php';
require_once __DIR__.'/Client.php';

class MqttServer {

    private $server;
    private $clients = []; // [fd]=>Client
    private $subscribeFds = []; // [topic]=>[fd]=>fd

    const COMMAND_CONNECT = 1;
    const COMMAND_CONNACK = 2;
    const COMMAND_PUBLISH = 3;
    const COMMAND_PUBACK = 4;
    const COMMAND_PUBREC = 5;
    const COMMAND_PUBREL = 6;
    const COMMAND_PUBCOMP = 7;
    const COMMAND_SUBSCRIBE = 8;
    const COMMAND_SUBACK = 9;
    const COMMAND_UNSUBSCRIBE = 10;
    const COMMAND_UNSUBACK = 11;
    const COMMAND_PINGREQ = 12;
    const COMMAND_PINGRESP = 13;
    const COMMAND_DISCONNECT = 14;

    const QOS0 = 0;
    const QOS1 = 1;
    CONST QOS2 = 2;

    public function start() {
        $this->server = $server = new Swoole\Server("0.0.0.0", 1883);
        $server->set(
            array(
                'open_mqtt_protocol' => 1,
                'worker_num' => 1,
                'task_worker_num' => 4,
                'log_file' => __DIR__.'/../runtime/log/swoole.log',
                'log_level' => SWOOLE_LOG_INFO,
            )
        );
        $server->on('connect', function ($server, $fd){
            echo "connection open: {$fd}\n";
        });
        $server->on('receive', function ($server, $fd, $reactor_id, $data) {
            try {
                $this->handleCommand($fd, $data);
            } catch (Exception $e) {
                echo $e->getMessage();
                $this->server->close($fd);
            }
        });
        $server->on('close', function ($server, $fd) {
            echo "connection close: {$fd}\n";
            unset($this->clients["$fd"]);
        });
        $server->on('Task', [$this, 'onTask']);
        $server->on('Finish', [$this, 'onFinish']);

        $server->start();
    }

    function handleCommand($fd, $data) {
        $packet = new Packet($data);
        $header = $packet->getFixedHeader();
        switch ($header['type']) {
            case self::COMMAND_CONNECT:
                $connectInfo = $packet->getConnectInfo();
                $clientId = $connectInfo['clientId'];
                echo "clientId($clientId)\n";
                $resp = $this->makeCONNACKData();
                $this->server->send($fd, $resp);
                $client = new Client($fd, $connectInfo);
                $this->clients["$fd"] = $client;
                break;
            case self::COMMAND_PUBLISH:
                $topic = $packet->getString();
                $msg = $packet->getRemain();
                echo "topic($topic)\n";
                echo "$msg\n";
                $this->publish($topic, $data);
                break;
            case self::COMMAND_SUBSCRIBE:
                if ($header['reserved'] != 0x2) {
                    throw new Exception('reserved is no 2\n');
                }
                $packetIdentifier = $packet->getMSBAndLSBValue();
                $topic = $packet->getString();
                $qos = $packet->getByte();
                $this->subscribe($topic, $fd);
                $resp = $this->makeSUBACKData($packetIdentifier, $qos);
                $this->server->send($fd, $resp);
                break;
            case self::COMMAND_UNSUBSCRIBE:
                if ($header['reserved'] != 0x2) {
                    throw new Exception('reserved is no 2\n');
                }
                $packetIdentifier = $packet->getMSBAndLSBValue();
                $topic = $packet->getString();
                $this->unsubscribe($topic, $fd);
                $resp = $this->makeUNSUBACKData($packetIdentifier);
                $this->server->send($fd, $resp);
                break;
            case self::COMMAND_PINGREQ:
                $resp = $this->makePINGREQData();
                $this->server->send($fd, $resp);
                break;
            case self::COMMAND_DISCONNECT:
                break;
            default:
                throw new Exception('illegal MQTT Control Packet command\n');
                break;
        }
    }

    private function publish($topic, $data) {
        if (!array_key_exists($topic, $this->subscribeFds)) {
            return;
        }
        $fds = $this->subscribeFds[$topic];
        if (!$fds) {
            return;
        }
        $json = json_encode(['cmd'=>'publish','fds'=>$fds,'data'=>$data]);
        $this->server->task($json);
    }

    private function subscribe($topic, $fd) {
        $this->subscribeFds[$topic][$fd] = $fd;
    }

    private function unsubscribe($topic, $fd) {
        unset($this->subscribeFds[$topic][$fd]);
    }

    private function makeCONNACKData() {
        return chr(32) . chr(2) . chr(0) . chr(0);
    }

    private function makePINGREQData() {
        return chr(208) . chr(0);
    }

    private function makeSUBACKData($packageIdentifier, $qos) {
        $data = chr(0x90);
        $length = 3; // 暂时只支持一个主题,报文标识符MSB+LSB+topic
        $data .= chr($length);
        $byte = intval($packageIdentifier/256);
        $data .= chr($byte);
        $byte = intval($packageIdentifier%256);
        $data .= chr($byte);
        $data .= chr($qos);

        return $data;
    }

    /*
     * UNSUBACK
     * byte 1 MQTT Controll Package type+Reserved 0xB0
     * byte 2 Remaining Length 0x02
     *
     * byte 1 Packet Identifier MSB
     * byte 2 Packet Identifier LSB
     */
    private function makeUNSUBACKData($packageIdentifier) {
        $data = chr(0xB0);
        $data .= chr(2);
        $byte = intval($packageIdentifier/256);
        $data .= chr($byte);
        $byte = intval($packageIdentifier%256);
        $data .= chr($byte);
        return $data;
    }

    public function onTask(Swoole\Server $server, $worker_id, $task_id, $data) {
        $arr = json_decode($data, true);
        if (!$arr) {
            return;
        }
        $cmd = $arr['cmd'];
        switch ($cmd) {
            case 'publish':
                $fds = $arr['fds'];
                foreach ($fds as $k => $fd) {
                    $server->send($fd, $arr['data']);
                }
                break;
            default:
                break;
        }

        return $server->finish($data);
    }

    public function onFinish(Swoole\Server $server, $task_id, $data) {
        echo 'Task finished #' . $task_id . '  #' . $data . PHP_EOL;
    }
}




