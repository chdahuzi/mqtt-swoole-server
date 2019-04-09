<?php
class Packet {
    private $data;
    private $pos;

    public function __construct(&$data) {
        $this->data = $data;
        $this->pos = 0;
    }

    public function getFixedHeader() {
        if (strlen($this->data) < 2) {
            throw new Exception("illegal MQTT protocol (".__LINE__.")\n");
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
        $connectInfo['protocolName'] = $this->getString();
        if ($connectInfo['protocolName'] != 'MQTT') {
            throw new Exception("protocol is not MQTT\n");
        }
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
            throw new Exception("reserved is not 0\n");
        }
        $this->pos += 1;
        $connectInfo['keepalive'] = $this->getMSBAndLSBValue();
        $connectInfo['clientId'] = $this->getString();
        return $connectInfo;
    }

    // MSB+LSB
    public function getMSBAndLSBValue() {
        $value = 256 * ord($this->data[$this->pos+0]) + ord($this->data[$this->pos+1]);
        $this->pos += 2;
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
                throw new Exception("illegal MQTT protocol (".__LINE__.")\n");
            }
            $digit = ord($this->data{$this->pos});
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $this->pos++;
        } while (($digit & 128) != 0);
        return $value;
    }
}