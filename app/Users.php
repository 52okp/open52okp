<?php
declare(strict_types=1);
namespace Okp;
use OTPHP\TOTP;
final class Users {
 public function __construct(private Database $db,private Crypto $crypto,private RateLimiter $rate,private Audit $audit){}
 public static function email(string $email):string{$email=mb_strtolower(trim($email));if(strlen($email)>191||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new Problem('请输入有效邮箱（最长 191 字节）');return $email;}
 public static function password(string $password):void{if(strlen($password)<12||strlen($password)>72||!preg_match('/[a-z]/',$password)||!preg_match('/[A-Z]/',$password)||!preg_match('/[0-9]/',$password))throw new Problem('密码需为 12～72 字节，并包含大写字母、小写字母和数字');}
 public function byId(string $id,bool $lock=false):?array{return $this->db->one('SELECT * FROM users WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);}
 public function current():?array {
  $s=$_SESSION['user']??null;if(!$s)return null;
  $u=$this->byId($s['id']);if(!$u||!$u['enabled']||(int)$u['session_version']!==$s['version']||$s['at']<time()-28800||$s['seen']<time()-1800){unset($_SESSION['user']);return null;}
  $_SESSION['user']['seen']=time();return $u;
 }
 public function authenticate(string $login,string $password,string $ip):?array {
  $login=mb_strtolower(trim($login));$this->rate->require('password-ip:'.$ip,30,300);$this->rate->require('password-name:'.$login,8,300);
  $u=$this->db->one('SELECT * FROM users WHERE username=? OR email=? LIMIT 1',[$login,$login]);
  $dummy='$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
  $ok=password_verify($password,$u['password_hash']??$dummy);
  if(!$ok||!$u||!$u['enabled']){$this->audit->log('login.password.failed',null,$ip);return null;}
  return $u;
 }
 public function create(string $email,string $password,string $name,?string $sourceClient=null,string $method='email'):array {
  $email=self::email($email);self::password($password);$name=trim($name);if($name===''||mb_strlen($name)>80)throw new Problem('昵称需为 1～80 个字符');
  return $this->db->transaction(function()use($email,$password,$name,$sourceClient,$method){$id=Crypto::uuid();
  try{$this->db->run('INSERT INTO users (id,username,email,display_name,password_hash,email_verified,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?)',[$id,$email,$email,$name,password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),time(),time()]);}
  catch(\PDOException $e){if($e->getCode()==='23000')throw new Problem('该邮箱或账号已存在，请登录或找回密码');throw $e;}
  $this->audit->registrationSource($id,$method,$sourceClient);return $this->byId($id);});
 }
 public function fromWechat(string $app,string $openid,?string $target=null,?string $sourceClient=null):array {
  return $this->db->transaction(function()use($app,$openid,$target,$sourceClient){
   if($target){$u=$this->byId($target,true);if(!$u||!$u['enabled'])throw new Problem('账号不可用',403);}
   $id=hash('sha256',$app."\0".$openid);
   // A dedicated unique-key row serializes identity creation, even when no identity exists yet.
   $this->db->run('INSERT INTO rate_limits(id,hits,window_start) VALUES (?,0,?) ON DUPLICATE KEY UPDATE hits=hits',[$id,time()]);
   $identity=$this->db->one('SELECT * FROM identities WHERE id=? FOR UPDATE',[$id]);
   if($identity){if($target&&$identity['user_id']!==$target)throw new Problem('此微信已经绑定其他账号，不能自动合并',409);$u=$this->byId($identity['user_id'],true);if(!$u||!$u['enabled'])throw new Problem('该账号不可用',403);return $u;}
   if($target){if($this->db->one('SELECT id FROM identities WHERE user_id=? AND app_id=?',[$target,$app]))throw new Problem('当前账号已绑定其他微信，不能覆盖',409);$uid=$target;}
   else{$uid=Crypto::uuid();$name=$this->nextWechatName();$this->db->run('INSERT INTO users(id,username,display_name,created_at,updated_at) VALUES (?,?,?,?,?)',[$uid,'wx_'.Crypto::random(12),$name,time(),time()]);$this->audit->registrationSource($uid,'wechat',$sourceClient);}
   $this->db->run('INSERT INTO identities(id,app_id,openid,user_id) VALUES (?,?,?,?)',[$id,$app,$openid,$uid]);return $this->byId($uid);
  },5);
 }
 private function nextWechatName():string {
  // The persistent counter shares the account creation transaction; failed creation consumes no number.
  $key='wechat-display-name-sequence';
  $this->db->run('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=value',[$key,$this->crypto->encrypt(json_encode(['next'=>10052],JSON_THROW_ON_ERROR))]);
  $row=$this->db->one('SELECT value FROM settings WHERE name=? FOR UPDATE',[$key]);
  $state=json_decode($this->crypto->decrypt($row['value']),true,32,JSON_THROW_ON_ERROR);$number=$state['next']??null;
  if(!is_int($number)||$number<10052||$number>=PHP_INT_MAX)throw new \RuntimeException('微信昵称编号状态无效，请管理员核查');
  $this->db->run('UPDATE settings SET value=? WHERE name=?',[$this->crypto->encrypt(json_encode(['next'=>$number+1],JSON_THROW_ON_ERROR)),$key]);
  return 'wx_'.$number;
 }
 public function verifyTotp(string $id,string $code,string $ip):bool {
  $this->rate->require('totp:'.$id,8,300);$this->rate->require('totp-ip:'.$ip,30,300);
  return $this->db->transaction(function()use($id,$code){
   $u=$this->byId($id,true);if(!$u||!$u['enabled']||!$u['totp_secret'])return false;
   if(preg_match('/^[0-9]{6}$/',$code)){
    $totp=TOTP::createFromSecret($this->crypto->decrypt($u['totp_secret']),new OAuth\Clock());$step=intdiv(time(),30);
    foreach([$step,$step-1,$step+1] as $candidate)if($candidate>(int)$u['totp_last_step']&&hash_equals($totp->at($candidate*30),$code)){$this->db->run('UPDATE users SET totp_last_step=? WHERE id=?',[$candidate,$id]);return true;}
   }
   $codes=json_decode($u['recovery_hashes']??'[]',true);$hash=$this->crypto->hash($code);
   foreach($codes as $i=>$saved)if(hash_equals($saved,$hash)){unset($codes[$i]);$this->db->run('UPDATE users SET recovery_hashes=? WHERE id=?',[json_encode(array_values($codes)),$id]);return true;}
   return false;
  });
 }
 public function revokeSessions(string $id):void{$this->db->run('UPDATE users SET session_version=session_version+1,updated_at=? WHERE id=?',[time(),$id]);$this->db->run('UPDATE access_tokens SET revoked=1 WHERE user_id=?',[$id]);$this->db->run('UPDATE auth_codes SET revoked=1 WHERE user_id=?',[$id]);}
}
