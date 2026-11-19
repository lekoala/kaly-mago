<?php

require dirname(__DIR__) . '/vendor/autoload.php';

(new Mago\Sdk\Worker(Kaly\Mago\KalyExtension::create()))->run();
