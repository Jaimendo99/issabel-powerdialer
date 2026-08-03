#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') exit(2);
if ($argc !== 3) exit(2);

$moduleRoot = getenv('GC_MODULE_ROOT');
if ($moduleRoot === false || $moduleRoot === '') $moduleRoot = '/var/www/html/modules/gestion_clientes';
$configFile = getenv('GC_CONFIG_FILE');
if ($configFile === false || $configFile === '') $configFile = '/etc/issabel/gestion_clientes.conf.php';

try {
    if (!is_readable($configFile)) throw new RuntimeException('Config is not readable');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Invalid config');
    require_once $moduleRoot . '/libs/paloSantoGestionClientes.class.php';
    require_once $moduleRoot . '/libs/GestionClientesCallFinalizer.class.php';
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
    $finalizer = new GestionClientesCallFinalizer(new paloSantoGestionClientes($pdo));
    $finalizer->finalize($argv[1], $argv[2]);
    exit(0);
} catch (Exception $e) {
    error_log('[gestion_clientes_finalizer] ' . get_class($e) . ': ' . $e->getMessage());
    exit(1);
}
