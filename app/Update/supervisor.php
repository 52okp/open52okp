<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=$argv[1]??'';$id=$argv[2]??'';
if(!preg_match('/^[a-f0-9]{24}$/D',$id)||!is_dir($root.'/storage/updates'))exit(2);
$dir=$root.'/storage/updates';$lock=fopen($dir.'/supervisor.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(3);
try {
 $state=json_decode(file_get_contents($dir.'/state.json'),true,64,JSON_THROW_ON_ERROR);
 if(($state['id']??'')!==$id||($state['phase']??'')!=='queued')exit(4);
 file_put_contents($dir.'/started-'.$id,(string)time(),LOCK_EX);
 $run=static function()use($root):void {
  $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
  $p=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0',$root.'/bin/update-worker.php'],[0=>['file',$null,'r'],1=>['file',$root.'/storage/updates/worker.log','a'],2=>['file',$root.'/storage/updates/worker.log','a']],$pipes,$root);
  if(!is_resource($p))throw new RuntimeException('Worker could not start');
  proc_close($p);
 };
 $run();
 // A killed child releases its locks; run standalone recovery once, without the web bootstrap.
 $state=json_decode(file_get_contents($dir.'/state.json'),true,64,JSON_THROW_ON_ERROR);
 if(($state['id']??'')===$id&&!in_array($state['phase'],['queued','complete','failed','rolled_back'],true))$run();
} catch(Throwable $e) {fwrite(STDERR,"后台更新进程异常，请检查权限、PHP CLI 扩展及任务状态。\n");}
finally {
 // A startup/bootstrap failure must not leave an unstartable queued task blocking retries.
 $source=is_file($root.'/storage/update-maintenance')&&is_file($dir.'/recovery/Engine.php')?$dir.'/recovery':$root.'/app/Update';
 try{foreach(['Remote','Package','Engine'] as $class)require_once $source.'/'.$class.'.php';$e=new \Okp\Update\Engine($root);if(method_exists($e,'failQueued'))$e->failQueued($id,'后台启动未完成，请检查 PHP CLI 扩展、数据库及目录权限后重试');}catch(Throwable){}
 flock($lock,LOCK_UN);fclose($lock);
}
