<?php

require_once dirname(__FILE__).'/autoload.php';

$container = new Container();

$container->bind('smsInterface', 'smsRegister');

$container->bind('send', 'smsSend');

$sms = $container->make('send', ['mobile' => '13691419875']);

$sms->send();
































