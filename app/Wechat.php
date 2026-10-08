<?php
declare(strict_types=1);
namespace Okp;
final class Wechat {
 public function __construct(private Settings $settings,private Database $db,private ?\Closure $transport=null){}
 public function config():array{return $this->settings->get('wechat');}
 public function enabled():bool{$s=$this->config();return !empty($s['enabled'])&&!empty($s['app_id'])&&!empty($s['secret'])&&!empty($s['page']);}
 public function appId():string{return $this->config()['app_id']??'';}
 private function request(string $path,?array $body=null):array {
  if($this->transport)return ($this->transport)($path,$body);
  $ch=curl_init('https://api.weixin.qq.com'.$path);$data='';
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,string $chunk)use(&$data){if(strlen($data)+strlen($chunk)>2097152)return 0;$data.=$chunk;return strlen($chunk);}]);
  if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
  $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
  if($ok===false||$status!==200)throw new Problem('微信服务暂时不可用，请稍后重试',503);return ['body'=>$data,'status'=>$status];
 }
 public function openid(string $code):string {
  if(!$this->enabled()||$code===''||strlen($code)>256)throw new Problem('微信登录凭证无效');$s=$this->config();
  $r=$this->request('/sns/jscode2session?'.http_build_query(['appid'=>$s['app_id'],'secret'=>$s['secret'],'js_code'=>$code,'grant_type'=>'authorization_code'],'','&',PHP_QUERY_RFC3986));$v=json_decode($r['body'],true);
  if(!is_array($v)||!empty($v['errcode'])||!preg_match('/^[A-Za-z0-9_-]{1,128}$/',$v['openid']??''))throw new Problem('微信验证失败，请重新点击确认');return $v['openid'];
 }
 private function token():string {
  $s=$this->config();$name='wx-token-'.substr(hash('sha256',$s['app_id'].':'.$s['secret']),0,32);$lock='okp-'.$name;
  if((int)$this->db->one('SELECT GET_LOCK(?,10) AS locked',[$lock])['locked']!==1)throw new Problem('微信服务繁忙，请稍后重试',503);
  try{
   $cache=$this->settings->get($name);if(($cache['expires']??0)>time())return $cache['token'];
   $r=$this->request('/cgi-bin/stable_token',['grant_type'=>'client_credential','appid'=>$s['app_id'],'secret'=>$s['secret'],'force_refresh'=>false]);$v=json_decode($r['body'],true);
   if(empty($v['access_token']))throw new Problem('获取微信凭证失败（错误码 '.(int)($v['errcode']??0).'），请核对小程序配置',503);
   $this->settings->put($name,['token'=>$v['access_token'],'expires'=>time()+max(30,(int)($v['expires_in']??7200)-120)]);return $v['access_token'];
  }finally{$this->db->run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
 public static function imageType(string $data):?string {
  if(str_starts_with($data,"\x89PNG\r\n\x1a\n"))return 'image/png';
  if(str_starts_with($data,"\xff\xd8\xff")&&str_ends_with($data,"\xff\xd9"))return 'image/jpeg';return null;
 }
 public function qrcode(string $ticket):array {
  if(!$this->enabled())throw new Problem('微信扫码登录尚未配置',503);$s=$this->config();
  $r=$this->request('/wxa/getwxacodeunlimit?access_token='.rawurlencode($this->token()),['scene'=>$ticket,'page'=>$s['page'],'check_path'=>true,'env_version'=>$s['env']??'release','width'=>320]);
  $type=self::imageType($r['body']);if(!$type){$v=json_decode($r['body'],true);$code=(int)($v['errcode']??0);if(in_array($code,[40001,40014,42001],true)){$name='wx-token-'.substr(hash('sha256',$s['app_id'].':'.$s['secret']),0,32);$this->settings->put($name,[]);}throw new Problem('获取小程序码失败（微信错误码 '.$code.'），请管理员核对配置',503);}return ['image'=>$r['body'],'type'=>$type];
 }
}
