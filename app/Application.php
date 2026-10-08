<?php
declare(strict_types=1);
namespace Okp;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response;
final class Application {
 public Database $db; public Crypto $crypto; public RateLimiter $rate; public Settings $settings; public Audit $audit; public Users $users; public Browser $browser; public Wechat $wechat; public LoginRequests $requests; public EmailCodes $emails; public View $view; private ?OAuth\Server $server=null;
 public function __construct(public Config $config){
  $this->db=new Database($config->values['db']);$this->crypto=new Crypto($config);$this->rate=new RateLimiter($this->db,$this->crypto);$this->settings=new Settings($this->db,$this->crypto);$this->audit=new Audit($this->db,$this->crypto);$this->users=new Users($this->db,$this->crypto,$this->rate,$this->audit);$this->browser=new Browser($config,$this->crypto);$this->wechat=new Wechat($this->settings,$this->db);$this->requests=new LoginRequests($this->db,$this->crypto,$this->rate,$this->wechat,$this->users);$this->emails=new EmailCodes($this->db,$this->crypto,$this->rate,new Mailer($this->settings));$this->view=new View($this);
 }
 public function oauth():OAuth\Server{return $this->server??=new OAuth\Server($this->config,$this->db);}
 public function http():\Slim\App {
  $app=\Slim\Factory\AppFactory::create();$web=new WebController($this);$admin=new AdminController($this);
  foreach(['/' => 'home','/login'=>'login','/account'=>'account'] as $path=>$method)$app->get($path,fn($r)=>$web->$method($r));
  foreach(['/login/password'=>'password','/login/finish'=>'qrFinish','/login/refresh'=>'refresh','/login/cancel'=>'cancel','/email/send'=>'sendCode','/register'=>'register','/forgot-password'=>'resetPassword','/account/profile'=>'profile','/account/password'=>'changePassword','/account/email'=>'linkEmail','/account/wechat'=>'linkWechat','/logout'=>'logout'] as $path=>$method)$app->post($path,fn($r)=>$web->$method($r));
  $app->map(['GET','POST'],'/login/otp',fn($r)=>$web->otp($r));$app->map(['GET','POST'],'/account/totp',fn($r)=>$web->totp($r));
  $app->get('/register',fn($r)=>$web->emailForm($r,'register'));$app->get('/forgot-password',fn($r)=>$web->emailForm($r,'reset'));
  $docs=new ApiDocs($this);$app->get('/api',fn($r)=>$docs->page());$app->get('/api/spec',fn($r)=>Http::json($docs->spec()));
  $app->get('/privacy',fn($r)=>$this->view->render('privacy',['title'=>'账号与隐私说明']));
  $app->get('/admin',fn($r)=>$admin->index($r));$app->post('/admin/user',fn($r)=>$admin->user($r));$app->post('/admin/client',fn($r)=>$admin->client($r));$app->post('/admin/settings',fn($r)=>$admin->settings($r));
  $updates=new UpdatesController($this);$app->get('/admin/updates',fn($r)=>$updates->index($r));$app->get('/admin/updates/status',fn($r)=>$updates->status($r));foreach(['configure','check','install'] as $action)$app->post('/admin/updates/'.$action,fn($r)=>$updates->$action($r));
  $base='/realms/52okp';$oidc=$base.'/protocol/openid-connect';
  $app->get($base.'/account[/]',fn($r)=>Http::redirect('/account'));
  $app->get($base.'/.well-known/openid-configuration',fn($r)=>Http::json($this->oauth()->discovery()));
  $app->get($oidc.'/certs',fn($r)=>Http::json($this->oauth()->jwt->jwks()));
  $app->get($oidc.'/auth',fn($r)=>$web->authorize($r));$app->map(['GET','POST'],$oidc.'/logout',fn($r)=>$web->endSession($r));
  $app->post($oidc.'/token',function($r){$this->rate->require('token:'.Http::ip($r,$this->config),120,60);return $this->oauth()->token($r);});
  $app->map(['GET','POST'],$oidc.'/userinfo',fn($r)=>Http::json($this->oauth()->userinfo($r)));
  $app->post($oidc.'/token/introspect',fn($r)=>Http::json($this->oauth()->introspect($r)));
  $app->post($oidc.'/revoke',function($r){$this->oauth()->revoke($r);return new Response();});
  $app->get($base.'/wechat/request/{id}',fn($r,$s,$v)=>Http::json($this->requests->details($v['id'],Http::ip($r,$this->config))));
  $app->get($base.'/wechat/status/{id}',fn($r,$s,$v)=>Http::json($this->requests->status($v['id'],Http::query($r,'key'))));
  $app->get($base.'/wechat/qrcode/{id}',function($r,$s,$v){$qr=$this->requests->qrcode($v['id'],Http::query($r,'key'));$s->getBody()->write($qr['image']);return $s->withHeader('Content-Type',$qr['type']);});
  $app->post($base.'/wechat/confirm',function($r){if(!str_starts_with(strtolower($r->getHeaderLine('Content-Type')),'application/json'))throw new Problem('需要 JSON 请求');$this->requests->confirm((array)$r->getParsedBody(),Http::ip($r,$this->config));return Http::json(['message'=>'已处理，请返回网页继续']);});
  $app->addBodyParsingMiddleware();$app->addRoutingMiddleware();
  $app->add(function(Request $r,$handler)use($oidc){
   $path=$r->getUri()->getPath();$api=$path==='/api/spec'||str_starts_with($path,'/realms/52okp/wechat/')||str_contains($path,'/.well-known/')||(str_starts_with($path,$oidc)&&!in_array($path,[$oidc.'/auth',$oidc.'/logout'],true));
   try{
    if(strlen((string)$r->getBody())>65536)throw new Problem('请求过大',413);
    // Reject duplicate/array parameters before PHP's form parsing can collapse them.
    foreach([$r->getUri()->getQuery(),str_starts_with(strtolower($r->getHeaderLine('Content-Type')),'application/x-www-form-urlencoded')?(string)$r->getBody():''] as $raw){$seen=[];foreach(explode('&',$raw) as $pair){if($pair==='')continue;$key=urldecode(explode('=',$pair,2)[0]);if(isset($seen[$key])||!preg_match('/^[a-zA-Z0-9_-]+$/',$key))throw new Problem('重复或数组参数不被支持');$seen[$key]=true;}}
    if(!$api){$this->browser->start();if($r->getMethod()==='POST'){$raw=(string)$r->getBody();parse_str($raw,$body);$this->browser->check($r->getHeaderLine('X-CSRF-Token')?:($body['csrf']??null));}}
    $response=$handler->handle($r);
   }catch(\League\OAuth2\Server\Exception\OAuthServerException $e){$response=$e->generateHttpResponse(new Response());}
   catch(\Throwable $e){$status=$e instanceof Problem?$e->status:($e instanceof \Slim\Exception\HttpException?$e->getCode():500);if($status<400||$status>599)$status=500;$message=$status===500?'服务暂时不可用，请联系管理员':($e instanceof Problem?$e->getMessage():'页面或请求方法不存在');
    if($status===500){$id=bin2hex(random_bytes(6));error_log(date('c').' '.$id.' '.get_class($e).' '.$e->getFile().':'.$e->getLine().PHP_EOL,3,$this->config->path('storage/error.log'));$message.='（编号 '.$id.'）';}
    if($api||$path==='/email/send')$response=Http::json(['error'=>$message,'message'=>$message],$status);else{$this->browser->start();$response=$this->view->render('error',['title'=>'操作未完成','message'=>$message],$status);}
   }
   if($response->getStatusCode()===429)$response=$response->withHeader('Retry-After','60');
   return $response->withHeader('Cache-Control','no-store')->withHeader('Pragma','no-cache')->withHeader('X-Content-Type-Options','nosniff')->withHeader('Referrer-Policy','no-referrer')->withHeader('X-Frame-Options','DENY')->withHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
  });return $app;
 }
}
