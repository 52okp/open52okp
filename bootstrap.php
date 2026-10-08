<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
return new Okp\Application(new Okp\Config(__DIR__,require __DIR__.'/config/app.php'));
