<?php
declare(strict_types=1);
namespace Okp;
final class Audit {
 public function __construct(private Database $db,private Crypto $crypto){}
 public function log(string $event,?string $user=null,string $ip='',string $detail=''):void{$this->db->run('INSERT INTO audit_events (event,user_id,ip_hash,detail,created_at) VALUES (?,?,?,?,?)',[$event,$user,$this->crypto->hash($ip),mb_substr($detail,0,255),time()]);}
 public function registrationSource(string $user,string $method,?string $client=null):void {
  if(!in_array($method,['email','wechat','admin'],true)||($client!==null&&!$this->db->one('SELECT id FROM clients WHERE id=?',[$client])))throw new Problem('注册来源无效');
  $this->log('user.registration-source',$user,'',json_encode(['method'=>$method,'client_id'=>$client],JSON_THROW_ON_ERROR));
 }
 public function retainApplicationEvidence():void {
  // Preserve existing historical evidence before credential cleanup; never infer registration origins.
  $this->db->transaction(function(){
   foreach(['application.first-authorized'=>'SELECT user_id,client_id,auth_time FROM auth_codes UNION ALL SELECT user_id,client_id,auth_time FROM access_tokens','application.first-token'=>'SELECT user_id,client_id,auth_time FROM access_tokens'] as $event=>$evidence){
    $this->db->run('INSERT INTO audit_events(event,user_id,ip_hash,detail,created_at) SELECT ?,e.user_id,?,e.client_id,e.first_at FROM (SELECT user_id,client_id,MIN(auth_time) AS first_at FROM ('.$evidence.') history GROUP BY user_id,client_id) e LEFT JOIN audit_events saved ON saved.user_id=e.user_id AND saved.detail=e.client_id AND saved.event=? WHERE saved.id IS NULL',[$event,str_repeat('0',64),$event]);
   }
  });
 }
}
