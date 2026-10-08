<?php
declare(strict_types=1);
namespace Okp\Update;
use RuntimeException;
/** Only the configured OSS origin receives the per-project credential. */
final class Remote {
 public const ORIGIN='https://app.52okp.com';
 public function __construct(public readonly string $project,private string $token,private ?\Closure $transport=null){
  if(!preg_match('/^[a-z][a-z0-9-]{1,47}$/D',$project)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('请先配置本项目的 64 位更新令牌');
 }
 public static function version(string $v):bool{return (bool)preg_match('/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$/D',$v);}
 public function base():string{return self::ORIGIN.'/api/v1/projects/'.$this->project;}
 private function fetch(string $url,string $dest,int $limit,?callable $progress=null):void {
  if(!str_starts_with($url,$this->base().'/')||str_contains($url,"\r")||str_contains($url,"\n"))throw new RuntimeException('更新地址不属于可信项目');
  if($this->transport){($this->transport)($url,$dest,$limit,$progress);if(!is_file($dest)||filesize($dest)>$limit)throw new RuntimeException('测试响应超出限制');return;}
  $file=fopen($dest,'xb');if(!$file)throw new RuntimeException('无法创建下载文件');chmod($dest,0600);$bytes=0;$last=0;$ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token,'Accept-Encoding: identity'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>300,CURLOPT_LOW_SPEED_LIMIT=>128,CURLOPT_LOW_SPEED_TIME=>30,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use($file,$limit,&$bytes,&$last,$progress){$n=strlen($chunk);if($bytes+$n>$limit)return 0;$written=fwrite($file,$chunk);if($written!==$n)return 0;$bytes+=$n;if($progress&&time()>$last){$last=time();$progress($bytes);}return $n;}]);
  try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);if($status===401)throw new RuntimeException('更新令牌无效、已撤销或与项目不匹配');if($status===404)throw new RuntimeException('更新中心暂无该项目的已发布版本，或此版本已被删除');if($ok===false||$status!==200)throw new RuntimeException('更新服务请求失败（HTTP '.$status.'），请检查网络、证书或 CDN 规则');}
  catch(\Throwable $e){fclose($file);curl_close($ch);@unlink($dest);throw $e;}fclose($file);curl_close($ch);
 }
 private function json(string $url):array{$file=tempnam(sys_get_temp_dir(),'okp-update-');unlink($file);try{$this->fetch($url,$file,4194304);$v=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('更新服务响应格式错误');return $v;}catch(\JsonException){throw new RuntimeException('更新服务未返回有效 JSON，请检查 CDN 拦截');}finally{if(is_file($file))unlink($file);}}
 public function check(string $current):array {
  if(!self::version($current))throw new RuntimeException('本地版本格式无效');$q=$this->json($this->base().'/updates?current_version='.$current.'&channel=stable');
  if(($q['api_version']??null)!==1||($q['project']??null)!==$this->project||($q['current_version']??null)!==$current||($q['channel']??null)!=='stable'||!is_bool($q['update_available']??null))throw new RuntimeException('更新查询身份或版本不匹配');
  $r=$this->release($q['release']??[]);$new=version_compare($r['version'],$current,'>');if($new!==$q['update_available'])throw new RuntimeException('更新版本状态不一致');return ['available'=>$new,'compatible'=>$r['from']===$current,'checked_at'=>time(),'release'=>$r];
 }
 public function release(array $r):array {
  if(($r['format']??null)!==1||($r['project']??null)!==$this->project||($r['product']??null)!==$this->project||($r['status']??null)!=='published'||($r['channel']??null)!=='stable'||($r['verification']??null)!=='github-sha256'||!preg_match('/^[a-f0-9]{64}$/D',$r['id']??'')||!self::version($r['version']??'')||!self::version($r['from']??'')||!version_compare($r['version'],$r['from'],'>'))throw new RuntimeException('发布元数据无效或未经来源校验');
  if(!is_string($r['notes']??null)||strlen($r['notes'])>65536)throw new RuntimeException('更新说明格式错误');
  $base=$this->base().'/releases/'.$r['id'];foreach(['download_url'=>'package','manifest_url'=>'manifest'] as $k=>$part)if(($r[$k]??'')!==$base.'/'.$part)throw new RuntimeException('拒绝重定向或其他来源的更新地址');
  foreach([$this->project.'-update.zip'=>'download_url','update-manifest.json'=>'manifest_url'] as $name=>$url){$asset=$r['assets'][$name]??[];$max=$url==='manifest_url'?4194304:209715200;if(!is_int($asset['size']??null)||$asset['size']<1||$asset['size']>$max||!preg_match('/^[a-f0-9]{64}$/D',$asset['sha256']??'')||($asset['url']??'')!==$r[$url])throw new RuntimeException('发布资产信息无效');}
  $zip=$r['assets'][$this->project.'-update.zip'];if(($r['size']??null)!==$zip['size']||($r['sha256']??null)!==$zip['sha256'])throw new RuntimeException('安装包摘要不一致');return $r;
 }
 public function download(array $release,string $dir,?callable $progress=null):array {
  $r=$this->release($release);$meta=$this->json($this->base().'/releases/'.$r['id']);$meta=$this->release($meta);
  foreach(['id','version','from','sha256','size','assets'] as $field)if($meta[$field]!==$r[$field])throw new RuntimeException('固定发布内容已改变，拒绝混用版本');
  foreach(['update-manifest.json'=>'manifest_url',$this->project.'-update.zip'=>'download_url'] as $name=>$url){$asset=$r['assets'][$name];$path=$dir.'/'.$name;$this->fetch($r[$url],$path,$asset['size'],$url==='download_url'?$progress:null);if(filesize($path)!==$asset['size']||!hash_equals($asset['sha256'],hash_file('sha256',$path)))throw new RuntimeException('下载大小或 SHA-256 校验失败');}
  try{$m=json_decode(file_get_contents($dir.'/update-manifest.json'),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('清单不是有效 JSON');}
  foreach(['product','version','from','size','sha256','notes'] as $field)if(($m[$field]??null)!==$r[$field])throw new RuntimeException('清单与发布快照不一致');if(($m['format']??null)!==2||($m['package']??null)!==$this->project.'-update.zip')throw new RuntimeException('清单协议不兼容');return $m;
 }
}
