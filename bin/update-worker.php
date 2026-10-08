<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
// Keep this recovery bootstrap compatible with installer=1.
$source=is_file($root.'/storage/update-maintenance')&&is_file($root.'/storage/updates/recovery/Engine.php')?$root.'/storage/updates/recovery':$root.'/app/Update';
foreach(['Remote','Package','Engine'] as $class)require_once $source.'/'.$class.'.php';
try{
 $engine=new \Okp\Update\Engine($root);if(($argv[1]??'')==='--status'){echo json_encode($engine->status(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;exit;}$state=$engine->state();
 if(!$state||$state['phase']!=='queued'){$engine->work();exit;}
 $a=require $root.'/bootstrap.php';$v=$engine->identity();$token=$a->settings->get('updates')['token']??'';
 $engine->work($token!==''?new \Okp\Update\Remote($v['product'],$token):null);
}catch(Throwable $e){fwrite(STDERR,"更新执行器未完成，请检查 CLI 扩展、数据库、配置和目录权限。\n");exit(1);}
