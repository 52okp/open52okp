<?php
declare(strict_types=1);
namespace Okp;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
final class View {
 public function __construct(private Application $app){}
 public function render(string $template,array $data=[],int $status=200):ResponseInterface {
  $app=$this->app;$e=fn($v)=>Http::escape($v);$csrf=$app->browser->csrf();$user=$app->users->current();$flash=$app->browser->takeFlash();
  $icon=require $app->config->path('resources/views/icons.php');$assets=json_decode(file_get_contents($app->config->path('resources/assets.json')),true);
  extract($data,EXTR_SKIP);ob_start();require $app->config->path('resources/views/'.$template.'.php');$content=ob_get_clean();ob_start();require $app->config->path('resources/views/layout.php');$html=ob_get_clean();
  $r=new Response($status);$r->getBody()->write($html);return $r->withHeader('Content-Type','text/html; charset=utf-8');
 }
}
