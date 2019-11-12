<?php


require_once __DIR__.'/../vendor/autoload.php';

class smsReceive
{
    
    protected  $queueName = 'sms_queue_durable';
    protected  $exchangeName = 'sms_exchange_durable';
    
    
    
    public function __construct()
    {
        
    }
    
    public function receive()
    {
        $amqp = new amqp();
        
        $amqp->consume($this->exchangeName, $this->queueName, function($data){
            $this->send($data);
        });
    }
    
    protected function send($data)
    {
        
        var_dump($data);
        
    }
}