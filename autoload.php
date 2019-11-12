<?php

require_once dirname(__FILE__).'/vendor/autoload.php';

function autoload($className)
{
    $pathArray = [
        'lib_path' => dirname(__FILE__).'/sms/lib',
        'sms_path' => dirname(__FILE__).'/sms',
        'dir_path' => dirname(__FILE__)
    ];
    
    foreach ($pathArray as $path) {
        $file = $path.DIRECTORY_SEPARATOR.$className.'.php';
        if ( file_exists($file) ) {
            include_once($file);
        }
    } 
    
    
}

spl_autoload_register('autoload');
