<?php


class smsLogin implements smsInterface
{
    
    public function make($mobile)
    {
    
        return "这是手机号{$mobile}的登录短信";
        
    }
    
    
}
