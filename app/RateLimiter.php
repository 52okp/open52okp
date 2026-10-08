<?php
declare(strict_types=1);
namespace Okp;
final class RateLimiter {
 public function __construct(private Database $db,private Crypto $crypto){}
 public function allow(string $key,int $limit,int $seconds):bool {
  $start=intdiv(time(),$seconds)*$seconds;$id=$this->crypto->hash($key.':'.$start);
  return $this->db->transaction(function()use($id,$limit,$start){$this->db->run('INSERT INTO rate_limits (id,hits,window_start) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1',[$id,$start]);return (int)$this->db->one('SELECT hits FROM rate_limits WHERE id=?',[$id])['hits']<=$limit;});
 }
 public function require(string $key,int $limit,int $seconds):void{if(!$this->allow($key,$limit,$seconds))throw new Problem('操作过于频繁，请稍后再试',429);}
}
