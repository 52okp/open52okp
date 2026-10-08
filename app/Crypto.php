<?php
declare(strict_types=1);
namespace Okp;
final class Crypto {
 private string $key;
 public function __construct(Config $config){$this->key=base64_decode($config->values['key'],true);}
 public static function random(int $bytes=32):string{return bin2hex(random_bytes($bytes));}
 public static function uuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);$h=bin2hex($d);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
 public static function b64(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
 public function hash(string $value):string{return hash_hmac('sha256',$value,$this->key);}
 public function encrypt(string $value):string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new \RuntimeException('加密失败');return base64_encode($iv.$tag.$cipher);}
 public function decrypt(string $value):string{$data=base64_decode($value,true);if($data===false||strlen($data)<28)throw new \RuntimeException('加密配置损坏');$plain=openssl_decrypt(substr($data,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16));if($plain===false)throw new \RuntimeException('无法解密配置，请核对 APP_KEY');return $plain;}
}
