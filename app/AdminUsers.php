<?php
declare(strict_types=1);
namespace Okp;

/** Read application evidence without treating authorization as a platform sync acknowledgement. */
final class AdminUsers {
 public function __construct(private Database $db){}
 private function evidence():string {
  return "SELECT user_id,client_id,auth_time AS recorded_at,0 AS exchanged FROM auth_codes UNION ALL SELECT user_id,client_id,auth_time,1 FROM access_tokens UNION ALL SELECT user_id,detail,created_at,IF(event='application.first-token',1,0) FROM audit_events WHERE event IN ('application.first-authorized','application.first-token')";
 }
 public function listing(string $q,string $client,string $relation,int $page):array {
  $clients=$this->db->all('SELECT id,name,enabled FROM clients ORDER BY name,id');
  if($client!==''&&!in_array($client,array_column($clients,'id'),true))throw new Problem('筛选应用不存在',400);
  if(!in_array($relation,['all','recorded','unrecorded'],true))throw new Problem('应用关联筛选无效',400);
  if($client===''&&$relation!=='all')throw new Problem('请先选择应用',400);
  $where=[];$args=[];
  if($q!==''){$where[]='(u.email LIKE ? OR u.username LIKE ? OR u.display_name LIKE ?)';$args=['%'.$q.'%','%'.$q.'%','%'.$q.'%'];}
  if($client!==''){$where[]=($relation==='unrecorded'?'NOT ':'').'EXISTS (SELECT 1 FROM ('.$this->evidence().') association WHERE association.user_id=u.id AND association.client_id=?)';$args[]=$client;}
  $sql=$where?' WHERE '.implode(' AND ',$where):'';
  $total=(int)$this->db->one('SELECT COUNT(*) AS total FROM users u'.$sql,$args)['total'];
  $pages=max(1,(int)ceil($total/50));$page=max(1,min($page,$pages));
  $members=$this->db->all('SELECT u.id,u.username,u.email,u.display_name,u.enabled,u.role,u.created_at FROM users u'.$sql.' ORDER BY u.created_at DESC,u.id LIMIT 50 OFFSET '.(($page-1)*50),$args);
  $members=$this->decorate($members,$clients);
  return ['members'=>$members,'applicationOptions'=>$clients,'totalMembers'=>$total,'totalPages'=>$pages,'page'=>$page,'q'=>$q,'clientFilter'=>$client,'relationFilter'=>$relation];
 }
 private function decorate(array $members,array $clients):array {
  $ids=array_column($members,'id');$origins=[];$associations=[];
  if($ids){
   $marks=implode(',',array_fill(0,count($ids),'?'));
   foreach($this->db->all("SELECT user_id,detail FROM audit_events WHERE event='user.registration-source' AND user_id IN ($marks) ORDER BY id",$ids) as $row){$origins[$row['user_id']]??=json_decode($row['detail'],true);}
   foreach($this->db->all('SELECT user_id,client_id,MIN(recorded_at) AS first_at,MAX(recorded_at) AS last_at,MAX(exchanged) AS exchanged FROM ('.$this->evidence().") association WHERE user_id IN ($marks) GROUP BY user_id,client_id",$ids) as $row){$associations[$row['user_id']][$row['client_id']]=$row;}
  }
  $names=array_column($clients,'name','id');
  foreach($members as &$member){
   $origin=$origins[$member['id']]??null;
   $member['registration_source']=$origin?((($origin['method']??'')==='admin'?'管理员创建':(($origin['method']??'')==='wechat'?'微信扫码注册':'邮箱注册'))):'历史来源未记录';
   $originClient=$origin['client_id']??null;
   $member['registration_platform']=$origin?($originClient===null?'52okp 账号中心':($names[$originClient]??$originClient)):'无法确认注册应用';
   $member['applications']=$associations[$member['id']]??[];
  }unset($member);
  return $members;
 }
 public function details(string $id):array {
  $member=$this->db->one('SELECT id,username,email,display_name,enabled,role,created_at FROM users WHERE id=?',[$id]);
  if(!$member)throw new Problem('用户不存在',404);
  $clients=$this->db->all('SELECT id,name,enabled FROM clients ORDER BY name,id');
  $member=$this->decorate([$member],$clients)[0];
  $clients=array_merge(array_values(array_filter($clients,fn($client)=>isset($member['applications'][$client['id']]))),array_values(array_filter($clients,fn($client)=>!isset($member['applications'][$client['id']]))));
  return ['member'=>$member,'applicationOptions'=>$clients];
 }
}
