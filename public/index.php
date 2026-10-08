<?php
declare(strict_types=1);
ini_set('display_errors','0');
// OKP_UPDATE_GATE_V1: shared for the whole request; the updater takes an exclusive lock.
$root=dirname(__DIR__);
if(is_file($root.'/storage/update-maintenance')){http_response_code(503);header('Retry-After: 10');header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');exit('系统正在更新或恢复，请稍后重试。');}
$updateGate=fopen($root.'/storage/update-gate.lock','c');
if(!$updateGate||!flock($updateGate,LOCK_SH|LOCK_NB)){http_response_code(503);header('Retry-After: 10');header('Cache-Control: no-store');exit('系统维护中，请稍后重试。');}
register_shutdown_function(static function()use($updateGate){flock($updateGate,LOCK_UN);fclose($updateGate);});
try{$application=require dirname(__DIR__).'/bootstrap.php';$application->http()->run();}
catch(Throwable $e){http_response_code(503);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');echo '<!doctype html><meta charset="utf-8"><title>账号中心待就绪</title><h1>账号中心尚未就绪</h1><p>请管理员检查安装步骤、数据库连接及 PHP 扩展。安装入口仅允许命令行使用。</p>';error_log('Account bootstrap: '.get_class($e).' at '.$e->getFile().':'.$e->getLine());}
