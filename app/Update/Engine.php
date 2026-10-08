<?php
declare(strict_types=1);
namespace Okp\Update;
use RuntimeException;
/** Standalone recovery engine: no application bootstrap is needed to restore files. */
final class Engine {
 private string $dir;
 public function __construct(public readonly string $root){$this->dir=$root.'/storage/updates';if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new RuntimeException('更新存储目录不可写');}
 public static function atomic(string $path,string $body,int $mode=0600):void {$tmp=$path.'.tmp-'.bin2hex(random_bytes(6));$f=fopen($tmp,'xb');if(!$f)throw new RuntimeException('更新文件无法写入');try{if(fwrite($f,$body)!==strlen($body)||!fflush($f))throw new RuntimeException('更新文件写入失败');if(function_exists('fsync'))fsync($f);}finally{fclose($f);}chmod($tmp,$mode);if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('更新文件原子替换失败');}}
 public function identity():array {$v=json_decode(file_get_contents($this->root.'/update-version.json'),true);if(!is_array($v)||!Remote::version($v['version']??'')||!preg_match('/^[a-z][a-z0-9-]{1,47}$/D',$v['product']??''))throw new RuntimeException('本地更新身份文件无效');return $v;}
 public function state():array {return is_file($this->dir.'/state.json')?json_decode(file_get_contents($this->dir.'/state.json'),true,64,JSON_THROW_ON_ERROR):[];}
 public function idle():bool{$s=$this->state();return !$s||in_array($s['phase'],['complete','failed','rolled_back'],true);}
 public function status():array{$s=$this->state();return ['job'=>$s?array_intersect_key($s,array_flip(['id','phase','message','created_at','updated_at','version','bytes','total','history'])):null,'worker_seen'=>is_file($this->dir.'/heartbeat')?(int)file_get_contents($this->dir.'/heartbeat'):0];}
 private function save(array $s):void{self::atomic($this->dir.'/state.json',json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
 private function phase(array &$s,string $phase,string $message):void{$s['phase']=$phase;$s['message']=$message;$s['updated_at']=time();$s['history'][]=['at'=>time(),'phase'=>$phase,'message'=>$message];$s['history']=array_slice($s['history'],-40);$this->save($s);}
 public function preflight():array {
  foreach(['curl','zip','openssl','sodium','pdo_mysql','mbstring'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('更新需要 PHP 扩展 '.$ext);
  if(!is_writable($this->root)||!is_writable($this->dir))throw new RuntimeException('更新执行用户需要项目代码目录和 storage 的写权限');
  if(disk_free_space($this->root)<134217728)throw new RuntimeException('至少需要 128 MiB 可用空间');
  foreach(Package::inventory($this->root) as $name=>$hash){$this->safe($name);if(!is_writable($this->root.'/'.$name)||!is_writable(dirname($this->root.'/'.$name)))throw new RuntimeException('代码文件不可写：'.$name);}
  return ['代码目录权限正常','PHP 扩展齐全','磁盘空间预检通过','数据库结构保持不变，不执行迁移'];
 }
 private function mutex(){ $h=fopen($this->dir.'/worker.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if(is_resource($h))fclose($h);throw new RuntimeException('已有更新任务正在执行');}return $h; }
 public function enqueue(array $release):string {
  $lock=$this->mutex();try{if(!$this->idle())throw new RuntimeException('已有待处理任务，请查看执行状态');$this->preflight();$v=$this->identity();if($release['product']!==$v['product']||$release['from']!==$v['version'])throw new RuntimeException('起始版本不匹配，请重新检查更新');$id=bin2hex(random_bytes(12));$s=['id'=>$id,'version'=>$release['version'],'release'=>$release,'phase'=>'queued','created_at'=>time(),'updated_at'=>time(),'history'=>[],'bytes'=>0,'total'=>$release['size']];$this->phase($s,'queued','任务已创建，正在启动后台安装');return $id;}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public function failQueued(string $id,string $message):void {
  $lock=$this->mutex();try{$s=$this->state();if(($s['id']??'')===$id&&($s['phase']??'')==='queued')$this->phase($s,'failed',$message);}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function safe(string $name):string {
  if(!Package::allowed($name))throw new RuntimeException('更新目标超出代码白名单');$at=$this->root;foreach(explode('/',$name) as $part){$at.='/'.$part;if(is_link($at))throw new RuntimeException('更新路径包含符号链接');}return $at;
 }
 private function copy(string $source,string $dest,string $hash):void {if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0755,true))throw new RuntimeException('无法创建代码目录');if(!hash_equals($hash,hash_file('sha256',$source)))throw new RuntimeException('源文件在安装过程中发生变化');if(is_file($dest)&&hash_equals($hash,hash_file('sha256',$dest)))return;self::atomic($dest,file_get_contents($source),0644);if(!hash_equals($hash,hash_file('sha256',$dest)))throw new RuntimeException('文件替换校验失败');}
 private function health():void {
  if(!function_exists('proc_open'))throw new RuntimeException('CLI PHP 需启用 proc_open 以隔离验证新版本');
  $code='$a=require $argv[1]."/bootstrap.php";$a->db->one("SELECT version FROM schema_versions WHERE version=1");$a->oauth()->jwt->jwks();$a->http();foreach(["home","login","admin","updates"] as $view){if(!is_file($a->config->path("resources/views/".$view.".php")))exit(4);}echo "OK";';
  $pipes=[];$proc=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0','-d','opcache.enable_cli=0','-r',$code,$this->root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->root);
  if(!is_resource($proc))throw new RuntimeException('无法启动隔离健康检查');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$start=time();$exit=-1;
  do{$out.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);$info=proc_get_status($proc);if(!$info['running']){$exit=$info['exitcode'];break;}if(time()-$start>30){proc_terminate($proc);break;}usleep(50000);}while(true);$out.=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);if($exit!==0||$out!=='OK')throw new RuntimeException('新版本健康检查失败');
 }
 private function restore(array &$s,string $job,?callable $health):void {
  $journal=json_decode(file_get_contents($job.'/journal.json'),true,64,JSON_THROW_ON_ERROR);$this->phase($s,'recovering','正在恢复更新前的代码');
  foreach($journal['old'] as $name=>$hash)$this->copy($job.'/backup/'.$name,$this->safe($name),$hash);
  foreach($journal['new'] as $name=>$hash)if(!isset($journal['old'][$name])){$path=$this->safe($name);if(is_file($path)&&!unlink($path))throw new RuntimeException('无法移除未完成更新文件');}
  if($health)$health();else $this->health();$this->phase($s,'rolled_back','更新未完成，已恢复旧代码；数据库和配置保持不变');
 }
 public function work(?Remote $remote=null,?callable $health=null):void {
  try{$lock=$this->mutex();}catch(RuntimeException){return;}$gate=null;
  try{self::atomic($this->dir.'/heartbeat',(string)time());$s=$this->state();if(!$s||in_array($s['phase'],['complete','failed','rolled_back'],true)){if(is_file($this->root.'/storage/update-maintenance'))unlink($this->root.'/storage/update-maintenance');return;}
   if(!preg_match('/^[a-f0-9]{24}$/D',$s['id']??''))throw new RuntimeException('任务标识损坏');$job=$this->dir.'/'.$s['id'];
   if($s['phase']!=='queued'){
    if(is_file($job.'/journal.json')){$gate=$this->maintenance();$this->restore($s,$job,$health);unlink($this->root.'/storage/update-maintenance');}
    else{$this->phase($s,'failed','上次任务在代码切换前中断，旧版本未修改，可重新检查更新');if(is_file($this->root.'/storage/update-maintenance'))unlink($this->root.'/storage/update-maintenance');}return;
   }
   if(!$remote)throw new RuntimeException('更新令牌尚未配置');$this->preflight();if(!$health&&!function_exists('proc_open'))throw new RuntimeException('CLI PHP 未启用 proc_open，无法健康检查');$v=$this->identity();
   if($s['release']['from']!==$v['version']||$remote->project!==$v['product'])throw new RuntimeException('本地版本或项目已改变，请重新检查更新');
   if(!mkdir($job,0700))throw new RuntimeException('任务目录无法创建');$this->phase($s,'downloading','正在从更新中心下载并校验固定版本');
   $m=$remote->download($s['release'],$job,function($bytes)use(&$s){$s['bytes']=$bytes;$s['updated_at']=time();$this->save($s);});$this->phase($s,'verifying','正在验证文件清单和解压安全');$new=Package::unpack($job.'/'.$m['package'],$job.'/stage',$m,$this->root,$v['product'],$v['version']);
   if(!is_dir($this->dir.'/recovery'))mkdir($this->dir.'/recovery',0700);foreach(['Engine','Package','Remote'] as $class)$this->copy(__DIR__.'/'.$class.'.php',$this->dir.'/recovery/'.$class.'.php',hash_file('sha256',__DIR__.'/'.$class.'.php'));
   $gate=$this->maintenance();$this->phase($s,'backup','维护窗口已开启，正在备份旧代码');$old=Package::inventory($this->root);
   $backupBytes=0;foreach($old as $name=>$hash)$backupBytes+=filesize($this->safe($name));if(disk_free_space($job)<$backupBytes*2+67108864)throw new RuntimeException('磁盘空间不足以备份和恢复');
   foreach($old as $name=>$hash)$this->copy($this->safe($name),$job.'/backup/'.$name,$hash);
   self::atomic($job.'/journal.json',json_encode(['old'=>$old,'new'=>$new],JSON_THROW_ON_ERROR));$this->phase($s,'installing','旧版本已备份，正在切换代码');
   foreach($new as $name=>$hash)$this->copy($job.'/stage/'.$name,$this->safe($name),$hash);
   foreach($old as $name=>$hash)if(!isset($new[$name])&&!unlink($this->safe($name)))throw new RuntimeException('旧代码文件清理失败');
   $this->phase($s,'health','正在独立进程中检查新版本与数据库连接');if($health)$health();else $this->health();$this->phase($s,'complete','更新成功，已保留上一个版本的代码备份');unlink($this->root.'/storage/update-maintenance');
  }catch(\Throwable $e){if(isset($s)&&$s){$message=$e instanceof RuntimeException?$e->getMessage():'更新失败，请检查任务目录与权限';if(isset($job)&&is_file($job.'/journal.json')){try{$this->phase($s,'recovering',$message.'；准备恢复旧代码');$gate??=$this->maintenance();$this->restore($s,$job,$health);unlink($this->root.'/storage/update-maintenance');}catch(\Throwable){$this->phase($s,'recovery_required','自动恢复尚未成功，保持维护状态；修复磁盘或权限后按更新文档执行恢复');}}else{$this->phase($s,'failed',$message);if(is_file($this->root.'/storage/update-maintenance'))unlink($this->root.'/storage/update-maintenance');}}}
  finally{if(is_resource($gate)){flock($gate,LOCK_UN);fclose($gate);}flock($lock,LOCK_UN);fclose($lock);}
 }
 private function maintenance(){self::atomic($this->root.'/storage/update-maintenance','1');$gate=fopen($this->root.'/storage/update-gate.lock','c');if(!$gate||!flock($gate,LOCK_EX))throw new RuntimeException('无法进入维护窗口');return $gate;}
}
