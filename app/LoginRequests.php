<?php
declare(strict_types=1);
namespace Okp;
final class LoginRequests {
 public function __construct(private Database $db,private Crypto $crypto,private RateLimiter $rate,private Wechat $wechat,private Users $users){}
 public function create(string $binding,array $payload,string $name,string $ip,string $intent='LOGIN',?string $target=null):array {
  $this->rate->require('create:'.$ip,20,60);$id=Crypto::random(16);$key=Crypto::random(24);
  $this->db->run('INSERT INTO login_requests (id,browser_hash,key_hash,intent,payload,client_name,target_user,app_id,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$binding,$this->crypto->hash($key),$intent,json_encode($payload,JSON_THROW_ON_ERROR),$name,$target,$this->wechat->enabled()?$this->wechat->appId():'',time(),time()+300]);
  return ['ticket'=>$this->find($id),'key'=>$key];
 }
 public function find(string $id,bool $lock=false):?array {if(!preg_match('/^[a-f0-9]{32}$/',$id))return null;return $this->db->one('SELECT * FROM login_requests WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);}
 public function bound(string $id,string $binding,bool $lock=false,bool $allowExpired=false):array {$t=$this->find($id,$lock);if(!$t||!hash_equals($t['browser_hash'],$binding))throw new Problem('登录请求无效，请重新发起',404);if(!$allowExpired&&$t['expires_at']<=time())throw new Problem('登录请求已过期，请重新发起',410);return $t;}
 private function validWechat(?array $t):bool{return $t&&$t['expires_at']>time()&&$this->wechat->enabled()&&$t['app_id']===$this->wechat->appId();}
 public function details(string $id,string $ip):array {
  $this->rate->require('details:'.$ip,60,60);$t=$this->find($id);if(!$this->validWechat($t))throw new Problem('登录请求已失效，请在网页刷新',410);
  return ['platform'=>$t['client_name'],'expiresAt'=>(int)$t['expires_at']*1000,'state'=>$t['state'],'intent'=>$t['intent']];
 }
 public function status(string $id,string $key):array {
  $t=$this->find($id);if(!$t||strlen($key)!==48||!hash_equals($t['key_hash'],$this->crypto->hash($key)))throw new Problem('登录请求无效',404);
  $this->rate->require('status:'.$id,40,60);return ['state'=>$t['expires_at']<=time()?'EXPIRED':$t['state'],'expiresAt'=>(int)$t['expires_at']*1000];
 }
 public function qrcode(string $id,string $key):array {
  $this->status($id,$key);$t=$this->find($id);if(!$this->validWechat($t)||$t['state']!=='WAITING')throw new Problem('二维码已失效，请刷新',410);
  if($t['qr_image']!==null)return ['image'=>$t['qr_image'],'type'=>$t['qr_type']];
  $lock='okp-qr-'.$id;if((int)$this->db->one('SELECT GET_LOCK(?,15) AS locked',[$lock])['locked']!==1)throw new Problem('二维码正在生成，请稍后重试',503);
  try{$t=$this->find($id);if(!$this->validWechat($t)||$t['state']!=='WAITING')throw new Problem('二维码已失效，请刷新',410);if($t['qr_image']!==null)return ['image'=>$t['qr_image'],'type'=>$t['qr_type']];$this->rate->require('qr-generate:'.$id,5,300);$qr=$this->wechat->qrcode($id);$this->db->run('UPDATE login_requests SET qr_image=?,qr_type=? WHERE id=?',[$qr['image'],$qr['type'],$id]);return $qr;}finally{$this->db->run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
 public function confirm(array $data,string $ip):void {
  $id=$data['ticket']??'';$code=$data['code']??'';$decision=$data['decision']??'';
  if(!is_string($id)||!is_string($code)||!in_array($decision,['confirm','cancel'],true)||($decision==='confirm'&&($data['accepted']??false)!==true))throw new Problem('请主动确认并同意账号说明');
  $this->rate->require('confirm-ip:'.$ip,15,60);$this->rate->require('confirm-ticket:'.$id,8,300);
  $t=$this->find($id);if(!$this->validWechat($t)||$t['state']!=='WAITING')throw new Problem('请求已处理或已失效',409);
  $openid=$this->wechat->openid($code);
  $this->db->transaction(function()use($id,$decision,$openid){$t=$this->find($id,true);if(!$this->validWechat($t)||$t['state']!=='WAITING')throw new Problem('请求已处理或已失效',409);$this->db->run('UPDATE login_requests SET state=?,openid=? WHERE id=?',[$decision==='confirm'?'CONFIRMED':'CANCELLED',$decision==='confirm'?$openid:null,$id]);});
 }
 public function consume(string $id,string $binding):array {
  return $this->db->transaction(function()use($id,$binding){$t=$this->bound($id,$binding,true);if(!$this->validWechat($t)||$t['state']!=='CONFIRMED')throw new Problem('请先在小程序确认，或刷新二维码重试',409);
   $payload=json_decode($t['payload'],true);if($t['intent']==='LINK'){$target=$this->users->byId($t['target_user'],true);if(!$target||!$target['enabled']||(int)$target['session_version']!==($payload['target_version']??null))throw new Problem('绑定会话已失效，请重新登录',403);}
   $u=$this->users->fromWechat($t['app_id'],$t['openid'],$t['intent']==='LINK'?$t['target_user']:null);
   $this->db->run("UPDATE login_requests SET state='CONSUMED' WHERE id=?",[$id]);return ['user'=>$u,'ticket'=>$t];
  });
 }
 public function cancel(string $id,string $binding):void{$this->db->transaction(function()use($id,$binding){$t=$this->bound($id,$binding,true,true);if(in_array($t['state'],['WAITING','CONFIRMED'],true))$this->db->run("UPDATE login_requests SET state='CANCELLED' WHERE id=?",[$id]);});}
}
