<?php
declare(strict_types=1);
namespace Okp;
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface};
use Slim\Psr7\Response;
final class Http {
 public static function json(array $data,int $status=200):ResponseInterface{$r=new Response($status);$r->getBody()->write(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));return $r->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Cache-Control','no-store');}
 public static function redirect(string $path):ResponseInterface{return (new Response(303))->withHeader('Location',$path)->withHeader('Cache-Control','no-store');}
 public static function input(Request $r,string $key,string $default=''):string{$v=((array)$r->getParsedBody())[$key]??$default;if(!is_string($v)||strlen($v)>8192)throw new Problem('提交参数无效');return $v;}
 public static function query(Request $r,string $key,string $default=''):string{$v=$r->getQueryParams()[$key]??$default;if(!is_string($v)||strlen($v)>8192)throw new Problem('请求参数无效');return $v;}
 public static function ip(Request $r,Config $config):string {
  // Nginx restores the client address from trusted EdgeOne sources. Never trust arbitrary X-Forwarded-For.
  return (string)($r->getServerParams()['REMOTE_ADDR']??'127.0.0.1');
 }
 public static function escape(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
