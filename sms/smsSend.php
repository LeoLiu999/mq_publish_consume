<?php


require_once __DIR__.'/../vendor/autoload.php';

class smsSend
{
    
    protected $msg;
    protected $mobile;
    protected $exchangeName = 'sms_exchange_durable';
    protected $queueName = 'sms_queue_durable';
    
    public function __construct(smsInterface $sms, $mobile)
    {
        $this->mobile = $mobile;
        
        $this->msg = $sms->make($mobile);
        
    }    
    
    
    public function send()
    {
        $amqp = new amqp();
        
        $data = [
            'mobile'  => $this->mobile,            
            'message' => $this->msg  
        ];
        
        return $amqp->publish($this->exchangeName, $this->queueName, $data);
        
    }
    
   
    
}