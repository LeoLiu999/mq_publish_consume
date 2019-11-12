<?php

require_once dirname(__FILE__).'/autoload.php';

$smsReceive = new smsReceive();

$smsReceive->receive();
