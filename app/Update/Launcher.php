<?php
declare(strict_types=1);
namespace Okp\Update;
use RuntimeException;
/** Starts one detached supervisor on BaoTa Linux; no scheduler or request-bound install. */
final class Launcher {
 public function __construct(private string $root){}
 public function preflight():string {
  if(PHP_OS_FAMILY==='Windows')throw new RuntimeException('一键后台更新适用于宝塔 Linux；Windows 开发环境请使用 CLI 测试');
  if(!function_exists('proc_open'))throw new RuntimeException('请在宝塔本站 PHP 的禁用函数中移除 proc_open，然后重启 PHP；无需配置计划任务');
  $php=PHP_BINDIR.'/php';
  $code='if(PHP_SAPI!=="cli")exit(2);foreach(["curl","zip","openssl","sodium","pdo_mysql","mbstring"] as $e)if(!extension_loaded($e))exit(3);if(!function_exists("proc_open"))exit(4);echo "OK";';
  $p=proc_open([$php,'-d','opcache.enable_cli=0','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->root);
  if(!is_resource($p))throw new RuntimeException('无法启动本站对应的 PHP CLI，请检查 PHP 安装');
  fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$start=microtime(true);$exit=-1;
  do{$out.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);$s=proc_get_status($p);if(!$s['running']){$exit=$s['exitcode'];break;}if(microtime(true)-$start>5){proc_terminate($p);break;}usleep(50000);}while(true);
  $out.=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
  if($exit!==0||$out!=='OK')throw new RuntimeException('本站 PHP CLI 预检失败：请确认同版本 PHP CLI 的 zip、curl、sodium、pdo_mysql、mbstring 和 proc_open 可用');
  return $php;
 }
 public function start(string $php,string $id):void {
  if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new RuntimeException('更新任务标识无效');
  $dir=$this->root.'/storage/updates';$ack=$dir.'/started-'.$id;
  // Keep the supervisor outside code being replaced, including upgrades from earlier versions.
  Engine::atomic($dir.'/supervisor.php',file_get_contents(__DIR__.'/supervisor.php'));
  $args=implode(' ',array_map('escapeshellarg',[$php,'-d','opcache.enable_cli=0',$dir.'/supervisor.php',$this->root,$id]));
  $command='nohup '.$args.' > '.escapeshellarg($dir.'/launcher.log').' 2>&1 < /dev/null &';
  $p=proc_open(['/bin/sh','-c',$command],[0=>['pipe','r'],1=>['file',$dir.'/launcher.log','a'],2=>['file',$dir.'/launcher.log','a']],$pipes,$this->root);
  if(!is_resource($p))throw new RuntimeException('后台更新未能启动，请检查 PHP 运行权限');
  fclose($pipes[0]);$exit=proc_close($p);
  for($i=0;$i<40;$i++){clearstatcache(true,$ack);if(is_file($ack))return;usleep(50000);}
  throw new RuntimeException('后台更新启动失败，请检查 storage/updates/launcher.log 和 PHP 权限后重试');
 }
}
