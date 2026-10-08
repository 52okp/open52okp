<?php
declare(strict_types=1);
namespace Okp;
final class EmailCodes {
 public function __construct(private Database $db,private Crypto $crypto,private RateLimiter $rate,private Mailer $mailer){}
 public function send(string $purpose,string $email,string $binding,string $ip):string {
  $email=Users::email($email);if(!in_array($purpose,['register','reset','link-email'],true))throw new Problem('验证码用途无效');
  $this->rate->require('mail-ip:'.$ip,5,300);$this->rate->require('mail-address:'.$email,1,60);
  $id=Crypto::random(16);$code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
  $this->db->run('UPDATE email_codes SET consumed=1 WHERE email=? AND browser_hash=? AND purpose=?',[$email,$binding,$purpose]);
  $this->db->run('INSERT INTO email_codes (id,purpose,email,browser_hash,code_hash,expires_at) VALUES (?,?,?,?,?,?)',[$id,$purpose,$email,$binding,$this->crypto->hash($id.':'.$code),time()+300]);
  $u=$this->db->one('SELECT id,enabled,email_verified FROM users WHERE email=?',[$email]);
  if($purpose==='reset'&&(!$u||!$u['enabled']||!$u['email_verified']))return $id;
  try{$this->mailer->send($email,$code,$purpose);}catch(\Throwable $e){$this->db->run('UPDATE email_codes SET consumed=1 WHERE id=?',[$id]);throw $e;}return $id;
 }
 public function consume(string $id,string $purpose,string $email,string $binding,string $code):bool {
  // Return false instead of throwing inside the transaction: failed attempts must be committed.
  return $this->db->transaction(function()use($id,$purpose,$email,$binding,$code){
   $r=$this->db->one('SELECT * FROM email_codes WHERE id=? FOR UPDATE',[$id]);
   if(!$r||$r['consumed']||$r['expires_at']<=time()||$r['purpose']!==$purpose||$r['email']!==$email||!hash_equals($r['browser_hash'],$binding)||$r['attempts']>=5)return false;
   $this->db->run('UPDATE email_codes SET attempts=attempts+1 WHERE id=?',[$id]);
   if(!preg_match('/^[0-9]{6}$/',$code)||!hash_equals($r['code_hash'],$this->crypto->hash($id.':'.$code)))return false;
   $this->db->run('UPDATE email_codes SET consumed=1 WHERE id=?',[$id]);return true;
  });
 }
}
