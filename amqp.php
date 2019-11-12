<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class amqp
{
    
    protected static $config;
    
    protected static $connection;
    
    protected static $channel;
    
    public function __construct()
    {
        self::$config =  [
            'host' => '127.0.0.1',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest'
        ];
        
        self::$connection = self::getConnection();
        
        self::$channel = self::getChannel();
        
    }
    
    public static function getConnection()
    {
        if ( !self::$connection ) {
            self::$connection = new AMQPStreamConnection(self::$config['host'], self::$config['port'], self::$config['user'], self::$config['password']);
        }
        
        return self::$connection;
    } 
    
    public static function getChannel()
    {
        if ( !self::$channel ) {
            
            self::$channel = self::$connection->channel();
        }
        
        return self::$channel;   
    }
    
    public function publish($exchangeName, $queueName, array $data)
    {
        try {
            self::$channel->exchange_declare($exchangeName, 'direct', false, true, false);
            self::$channel->queue_declare($queueName, false, true, false, false);
            $message = new AMQPMessage( self::setData($data), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            
            self::$channel->basic_publish($message, $exchangeName);
            
            return ['msg' => 'success'];
        } catch (Exception $e) {
            return ['msg' => 'error_publish', 'info' => $e];
        }
    }
    
    public function consume($exchangeName, $queueName, Closure $closure)
    {
        
        self::$channel->queue_declare($exchangeName, false, true, false, false);
        self::$channel->queue_bind($queueName, $exchangeName);
        
        
        $callback = function($msg) use ( $closure ) {
            return $closure(self::getData($msg->body));
        };
        
        self::$channel->basic_consume($queueName, '', false, true, false, false, $callback);
        
        while (self::$channel->is_consuming()) {
            self::$channel->wait();
        }
    }
    
    protected static function setData(array $data)
    {
        return serialize($data);
    }
    
    protected static function getData($serialize)
    {
        return unserialize($serialize);
    }
    
    public function __destruct()
    {
        self::$connection->close();
        self::$channel->close();
    }
    
} 