<?php

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

define('GC_PROJECT_ROOT', dirname(__DIR__));

function gc_require_class($className, $relativeFile)
{
    if (!class_exists($className, false)) {
        $file = GC_PROJECT_ROOT . '/' . $relativeFile;
        if (!is_file($file)) {
            throw new RuntimeException('Required source file is missing: ' . $relativeFile);
        }
        require_once $file;
    }
    if (!class_exists($className, false)) {
        throw new RuntimeException('Expected class was not declared: ' . $className);
    }
}
