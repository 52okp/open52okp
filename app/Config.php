<?php
declare(strict_types=1);
namespace Okp;
final class Config {
 public function __construct(public readonly string $root, public readonly array $values) {
  $url=$values['url']??'';
  if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_QUERY)!==null || parse_url($url,PHP_URL_FRAGMENT)!==null || !in_array(parse_url($url,PHP_URL_PATH),[null,''],true)) throw new \RuntimeException('APP_URL 必须为完整站点来源地址，不含路径');
  if (($values['env']??'production')==='production' && !str_starts_with($url,'https://')) throw new \RuntimeException('生产 APP_URL 必须使用 HTTPS');
  if (strlen(base64_decode($values['key']??'',true)?:'')!==32) throw new \RuntimeException('请先执行 php bin/console install 初始化 APP_KEY');
 }
 public function url():string{return $this->values['url'];}
 public function issuer():string{return $this->url().'/realms/52okp';}
 public function production():bool{return $this->values['env']==='production';}
 public function path(string $path):string{return $this->root.'/'.$path;}
}
