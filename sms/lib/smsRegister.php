<?php


class smsRegister implements smsInterface
{
    
    public function make($mobile)
    {
        return "这是{$mobile}的注册短信";    
    }
    
}