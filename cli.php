<?php
ini_set('error_reporting', -1);
ini_set('display_errors', true);
ini_set('date.timezone', 'Asia/Shanghai');

define("_MODULE", 'cli');
define("_ROOT", __DIR__);
is_readable($auto = (_ROOT . "/vendor/autoload.php")) ? include($auto) : exit("composer dump-autoload --optimize\n");
(new esp\core\Dispatcher())->debug()->bootstrap()->run();
