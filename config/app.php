<?php
declare(strict_types=1);
return [
 'env' => $_ENV['APP_ENV'] ?? 'production',
 'url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
 'key' => $_ENV['APP_KEY'] ?? '',
 'db' => ['host'=>$_ENV['DB_HOST']??'127.0.0.1', 'port'=>$_ENV['DB_PORT']??'3306', 'database'=>$_ENV['DB_DATABASE']??'okp_account', 'username'=>$_ENV['DB_USERNAME']??'okp_account', 'password'=>$_ENV['DB_PASSWORD']??''],
];
