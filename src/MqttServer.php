<?php

class Client {
    private $fd;
    private $connectInfo;
    public function __construct($fd, $connectInfo) {
        $this->fd = $fd;
        $this->connectInfo = $connectInfo;
    }
}

class Packet {
    private $data;
    private $pos;

    public function __construct(&$data) {
        $this->data = $data;
        $this->pos = 0;
    }

    public function getFixedHeader() {
        if (strlen($this->data) < 2) {
            throw new Exception("illegal MQTT protocol\n");
        }
        $byte = ord($this->data[0]);
        $this->pos++;
        $header['type'] = ($byte & 0xF0) >> 4;
        $header['reserved'] = ($byte & 0x0F);
        $header['dup'] = ($byte & 0x08) >> 3;
        $header['qos'] = ($byte & 0x06) >> 1;
        $header['retain'] = $byte & 0x01;
        $header['length'] = $this->getLength();
        return $header;
    }

    public function getConnectInfo() {
        $length = $this->getMSBAndLSBValue();
        $protocolName = substr($this->data, $this->pos, $length);
        if ($protocolName != 'MQTT') {
            throw new Exception("illegal MQTT Control Packet type\n");
        }
        $connectInfo['protocolName'] = $protocolName;
        $this->pos += $length;
        $connectInfo['version'] = ord(substr($this->data, $this->pos, 1));
        $this->pos += 1;
        $byte = ord($this->data[$this->pos]); // Connect Flags
        $connectInfo['userNameFlag'] = ($byte & 0x80 == 0x80);
        $connectInfo['passwordFlag'] = ($byte & 0x40 == 0x40);
        $connectInfo['willRetain'] = ($byte & 0x20 == 0x20);
        $connectInfo['willQos'] = ($byte & 0x18 >> 3);
        $connectInfo['willFlag'] = ($byte & 0x04 == 0x04);
        $connectInfo['cleanSession'] = ($byte & 0x02 == 0x02);
        $connectInfo['reserved'] = ($byte & 0x01 == 0x01);
        if ($connectInfo['reserved'] != 0) {
            throw new Exception("illegal MQTT Control Packet type\n");
        }
        $this->pos += 1;
        $connectInfo['keepalive'] = $this->getMSBAndLSBValue();
        $connectInfo['clientId'] = $this->getClientId();
        return $connectInfo;
    }

    // MSB+LSB
    public function getMSBAndLSBValue() {
        $value = 256 * ord($this->data[$this->pos+0]) + ord($this->data[$this->pos+1]);
        $this->pos +=2;
        return $value;
    }

    public function getByte() {
        $byte = ord(substr($this->data, $this->pos, 1));
        $this->pos += 1;
        return $byte;
    }

    public function getString() {
        $length = $this->getMSBAndLSBValue();
        $string = substr($this->data, $this->pos, $length);
        $this->pos += $length;
        return $string;
    }

    public function getRemain() {
        $remain = substr($this->data, $this->pos);
        $this->pos = strlen($this->data)-1;
        return $remain;
    }

    public function getLength() {
        $dataSize = strlen($this->data);
        $multiplier = 1;
        $value = 0;
        do {
            if ($this->pos > ($dataSize-1)) {
                throw new Exception("illegal MQTT protocol\n");
            }
            $digit = ord($this->data{$this->pos});
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $this->pos++;
        } while (($digit & 128) != 0);
        return $value;
    }

    public function getClientId() {
        $length = $this->getMSBAndLSBValue($this->data, $this->pos);
        $clientId = substr($this->data, $this->pos, $length);
        $this->pos += $length;
        return $clientId;
    }
}

class MqttServer {

    private $server;
    private $clients = []; // [fd]=>Client
    private $topicFds = []; // [topic]=>[fd]=>fd

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
                'log_file' => '/web/iot-swoole-server/runtime/log/swoole.log',
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
        $server->start();
    }

    function handleCommand($fd, $data) {
        $packet = new Packet($data);
        $header = $packet->getFixedHeader();
        var_dump($header);
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
                    throw new Exception('illegal MQTT Control Packet type\n');
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
                    throw new Exception('illegal MQTT Control Packet type\n');
                }
                $packetIdentifier = $packet->getMSBAndLSBValue();
                $topic = $packet->getString();
                $this->unsubscribe($topic, $fd);
                $resp = $this->makeUNSUBACKData($packetIdentifier);
                $this->server->send($fd, $resp);
                break;
            case self::COMMAND_PINGREQ:
                $resp = $this->makePINGREQData();
                echo "PINGREQ\n";
                $this->server->send($fd, $resp);
                break;
            case self::COMMAND_DISCONNECT:
                break;
            default:
                throw new Exception('illegal MQTT Control Packet type\n');
                break;
        }
    }

    private function publish($topic, $data) {
        if (!array_key_exists($topic, $this->topicFds)) {
            return;
        }
        $fds = $this->topicFds[$topic];
        if (!$fds) {
            return;
        }
        foreach ($fds as $k => $fd) {
            $this->server->send($fd, $data);
        }
    }

    private function subscribe($topic, $fd) {
        $this->topicFds[$topic][$fd] = $fd;
    }

    private function unsubscribe($topic, $fd) {
        unset($this->topicFds[$topic][$fd]);
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
}

(new MqttServer())->start();

