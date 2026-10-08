<?php
declare(strict_types=1);
namespace Okp;
final class Browser {
 public function __construct(private Config $config,private Crypto $crypto){}
 public function start():void {
  if(session_status()===PHP_SESSION_ACTIVE)return;
  $dir=$this->config->path('storage/sessions');if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('会话目录不可写');
  ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.gc_maxlifetime','28800');
  session_save_path($dir);session_name($this->config->production()?'__Host-okp':'okp_dev');
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$this->config->production(),'httponly'=>true,'samesite'=>'Lax']);session_start();
  $_SESSION['csrf']??=Crypto::random();$_SESSION['binding']??=Crypto::random();
 }
 public function csrf():string{return $_SESSION['csrf'];}
 public function check(mixed $value):void{if(!is_string($value)||!hash_equals($this->csrf(),$value))throw new Problem('页面已失效，请刷新后重新操作',403);}
 public function binding():string{return $this->crypto->hash($_SESSION['binding']);}
 public function login(array $u):void{session_regenerate_id(true);$_SESSION['user']=['id'=>$u['id'],'version'=>(int)$u['session_version'],'at'=>time(),'seen'=>time()];$_SESSION['csrf']=Crypto::random();}
 public function logout():void{$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=Crypto::random();$_SESSION['binding']=Crypto::random();}
 public function flash(string $message):void{$_SESSION['flash']=$message;}
 public function takeFlash():string{$m=$_SESSION['flash']??'';unset($_SESSION['flash']);return $m;}
}
