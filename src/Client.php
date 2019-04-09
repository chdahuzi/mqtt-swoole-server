<?php
class Client {
    private $fd;
    private $connectInfo;
    public function __construct($fd, $connectInfo) {
        $this->fd = $fd;
        $this->connectInfo = $connectInfo;
    }
}