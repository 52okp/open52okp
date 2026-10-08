<?php
declare(strict_types=1);
namespace Okp;
final class Installer {
 public static function prepare(string $root):void {
  $path=$root.'/.env';if(!is_file($path))throw new \RuntimeException('请先复制 .env.example 为 .env，填写数据库及 APP_URL');
  $env=file_get_contents($path);if(preg_match('/^APP_KEY=\s*$/m',$env)){$env=preg_replace('/^APP_KEY=\s*$/m','APP_KEY='.base64_encode(random_bytes(32))."\n",$env);if(file_put_contents($path,$env,LOCK_EX)===false)throw new \RuntimeException('无法保存 APP_KEY');chmod($path,0600);}
  foreach(['storage','storage/keys','storage/sessions'] as $dir){if(!is_dir($root.'/'.$dir)&&!mkdir($root.'/'.$dir,0700,true))throw new \RuntimeException('无法创建存储目录');}
  $private=$root.'/storage/keys/private.pem';$public=$root.'/storage/keys/public.pem';
  if(is_file($private)!==is_file($public))throw new \RuntimeException('签名密钥不完整，请恢复备份；不要覆盖已有密钥');
  if(!is_file($private)){$key=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$key||!openssl_pkey_export($key,$pem))throw new \RuntimeException('RSA 密钥生成失败，请检查 OpenSSL 配置');$details=openssl_pkey_get_details($key);if(file_put_contents($private,$pem,LOCK_EX)===false||file_put_contents($public,$details['key'],LOCK_EX)===false)throw new \RuntimeException('密钥保存失败');chmod($private,0600);chmod($public,0644);}
 }
 public static function schema(Application $a):void {$sql=file_get_contents($a->config->path('database/schema.sql'));$sql=preg_replace('/^--.*$/m','',$sql);foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$a->db->pdo->exec($statement);}
}
