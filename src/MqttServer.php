<?php

class Client {
    private $fd;
    private $connectInfo;
    public function __construct($fd, $connectInfo) {
        $this->fd = $fd;
        $this->connectInfo = $connectInfo;
    }
}

class MqttServer {

    private $server;
    private $clients = [];

    const TYPE_CONNECT = 1;
    const TYPE_CONNACK = 2;
    const TYPE_PUBLISH = 3;
    const TYPE_PUBACK = 4;
    const TYPE_PUBREC = 5;
    const TYPE_PUBREL = 6;
    const TYPE_PUBCOMP = 7;
    const TYPE_SUBSCRIBE = 8;
    const TYPE_SUBACK = 9;
    const TYPE_UNSUBSCRIBE = 10;
    const TYPE_UNSUBACK = 11;
    const TYPE_PINGREQ = 12;
    const TYPE_PINGRESP = 13;
    const TYPE_DISCONNECT = 14;

    public function start() {
        $this->server = $server = new Swoole\Server("0.0.0.0", 1883);
        $server->set(
            array(
                'open_mqtt_protocol' => 1,
                'worker_num' => 1,
            )
        );
        $server->on('connect', function ($server, $fd){
            echo "connection open: {$fd}\n";
        });
        $server->on('receive', function ($server, $fd, $reactor_id, $data) {
            try {
                $this->handleData($fd, $data);
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

    function handleData($fd, $data) {
        $offset = 0;
        $header = $this->getFixedHeader($data, $offset);
        switch ($header['type']) {
            case self::TYPE_CONNECT:
                $connectInfo = $this->getConnectInfo($data, $offset);
                $clientId = $connectInfo['clientId'];
                echo "clientId($clientId)\n";
                $resp = $this->makeCONNACKData();
                $this->server->send($fd, $resp);
                $client = new Client($fd, $connectInfo);
                $this->clients["$fd"] = $client;
                var_dump($connectInfo);
                break;
            case self::TYPE_PUBLISH:
                $length = $this->getMSBAndLSBValue($data, $offset);
                $topic = substr($data, $offset, $length);
                $offset += $length;
                $msg = substr($data, $offset);
                echo "topic($topic)\n";
                echo "$msg\n";
                break;
            case self::TYPE_SUBSCRIBE:
                break;
            case self::TYPE_UNSUBSCRIBE:
                break;
            case self::TYPE_PINGREQ:
                $resp = $this->makePINGREQData();
                echo "PINGREQ\n";
                $this->server->send($fd, $resp);
                break;
            case self::TYPE_DISCONNECT:
                break;
            default:
                throw new Exception('illegal MQTT Control Packet type\n');
                break;
        }
    }

    private function makeCONNACKData() {
        return chr(32) . chr(2) . chr(0) . chr(0);
    }

    private function makePINGREQData() {
        return chr(208) . chr(0);
    }

    private function getFixedHeader($data, &$offset) {
        if (strlen($data) < 2) {
            throw new Exception("illegal MQTT protocol\n");
        }
        $byte = ord($data[0]);
        $offset++;
        $header['type'] = ($byte & 0xF0) >> 4;
        $header['dup'] = ($byte & 0x08) >> 3;
        $header['qos'] = ($byte & 0x06) >> 1;
        $header['retain'] = $byte & 0x01;
        $header['length'] = $this->getLength($data, $offset);
        return $header;
    }

    private function getConnectInfo(&$data, &$offset) {
        $length = $this->getMSBAndLSBValue($data, $offset);
        $protocolName = substr($data, $offset, $length);
        if ($protocolName != 'MQTT') {
            throw new Exception("illegal MQTT Control Packet type\n");
        }
        var_dump($protocolName);
        var_dump($offset);
        $connectInfo['protocolName'] = $protocolName;
        $offset += $length;
        $connectInfo['version'] = ord(substr($data, $offset, 1));
        $offset += 1;
        $byte = ord($data[$offset]);
        $connectInfo['willRetain'] = ($byte & 0x20 == 0x20);
        $connectInfo['willQos'] = ($byte & 0x18 >> 3);
        $connectInfo['willFlag'] = ($byte & 0x04 == 0x04);
        $connectInfo['cleanStart'] = ($byte & 0x02 == 0x02);
        $offset += 1;
        $connectInfo['keepalive'] = $this->getMSBAndLSBValue($data, $offset);
        $length = $this->getMSBAndLSBValue($data, $offset);
        $connectInfo['clientId'] = substr($data, $offset, $length);
        $offset += $length;
        return $connectInfo;
    }

    private function getLength($data, &$offset){
        $dataSize = strlen($data);
        $multiplier = 1;
        $value = 0;
        do {
            if ($offset > ($dataSize-1)) {
                throw new Exception("illegal MQTT protocol\n");
            }
            $digit = ord($data{$offset});
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $offset++;
        } while (($digit & 128) != 0);
        return $value;
    }

    // MSB+LSB
    function getMSBAndLSBValue(&$data, &$offset) {
        $value = 256 * ord($data[$offset+0]) + ord($data[$offset+1]);
        $offset +=2;
        return $value;
    }
}

(new MqttServer())->start();

