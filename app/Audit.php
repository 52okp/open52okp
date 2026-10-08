<?php
declare(strict_types=1);
namespace Okp;
final class Audit {
 public function __construct(private Database $db,private Crypto $crypto){}
 public function log(string $event,?string $user=null,string $ip='',string $detail=''):void{$this->db->run('INSERT INTO audit_events (event,user_id,ip_hash,detail,created_at) VALUES (?,?,?,?,?)',[$event,$user,$this->crypto->hash($ip),mb_substr($detail,0,255),time()]);}
}
