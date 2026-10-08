<?php
declare(strict_types=1);
namespace Okp;
final class Settings {
 public function __construct(private Database $db,private Crypto $crypto){}
 public function get(string $name):array{$r=$this->db->one('SELECT value FROM settings WHERE name=?',[$name]);return $r?json_decode($this->crypto->decrypt($r['value']),true,32,JSON_THROW_ON_ERROR):[];}
 public function put(string $name,array $value):void{$this->db->run('INSERT INTO settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$name,$this->crypto->encrypt(json_encode($value,JSON_THROW_ON_ERROR))]);}
}
