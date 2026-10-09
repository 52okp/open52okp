<?php
declare(strict_types=1);
namespace Okp;
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface};
final class WebController {
 public function __construct(private Application $a){}
 private function ip(Request $r):string{return Http::ip($r,$this->a->config);}
 public function requireUser(bool $admin=false):array{$u=$this->a->users->current();if(!$u)throw new Problem('请先登录',401);if($admin&&$u['role']!=='admin')throw new Problem('仅管理员可执行此操作',403);return $u;}
 private function lockAccount(array $u):void{$current=$this->a->users->byId($u['id'],true);if(!$current||!$current['enabled']||(int)$current['session_version']!==(int)$u['session_version'])throw new Problem('账号状态已改变，请重新登录',403);}
 private function recent(array $u):void{if(($_SESSION['user']['at']??0)<time()-300)throw new Problem('此操作需要重新验证身份，请退出后重新登录',403);}
 public function home(Request $r):ResponseInterface{return $this->a->view->render('home',['title'=>'统一身份，各站主动登录']);}
 public function authorize(Request $r):ResponseInterface {
  $data=$this->a->oauth()->validate($r);$created=$this->a->requests->create($this->a->browser->binding(),['oauth'=>$data['query']],$data['name'],$this->ip($r));$this->remember($created);return Http::redirect('/login?request='.$created['ticket']['id']);
 }
 private function remember(array $created):void{$_SESSION['request_keys'][$created['ticket']['id']]=$created['key'];if(count($_SESSION['request_keys'])>10)array_shift($_SESSION['request_keys']);}
 public function login(Request $r):ResponseInterface {
  $id=Http::query($r,'request');
  if($id===''){$target=Http::query($r,'target')==='/admin'?'/admin':'/account';$created=$this->a->requests->create($this->a->browser->binding(),['return'=>$target],'52okp 账号中心',$this->ip($r));$this->remember($created);return Http::redirect('/login?request='.$created['ticket']['id']);}
  $t=$this->a->requests->bound($id,$this->a->browser->binding());if(!in_array($t['state'],['WAITING','CONFIRMED'],true))throw new Problem('此请求已经处理，请重新发起登录',409);
  return $this->loginPage($t);
 }
 private function loginPage(array $t,string $error='',string $tab='qr'):ResponseInterface {
  $key=$_SESSION['request_keys'][$t['id']]??'';$enabled=$this->a->wechat->enabled()&&$t['app_id']===$this->a->wechat->appId();
  return $this->a->view->render('login',['title'=>$t['intent']==='LINK'?'绑定微信':'登录','ticket'=>$t,'qrKey'=>$key,'wechatEnabled'=>$enabled,'error'=>$error,'tab'=>$tab]);
 }
 public function password(Request $r):ResponseInterface {
  $t=$this->a->requests->bound(Http::input($r,'request'),$this->a->browser->binding());if($t['intent']!=='LOGIN'||!in_array($t['state'],['WAITING','CONFIRMED'],true))throw new Problem('请求已处理',409);
  $u=$this->a->users->authenticate(Http::input($r,'username'),Http::input($r,'password'),$this->ip($r));if(!$u)return $this->loginPage($t,'账号或密码错误，或账号不可用','password');
  $this->a->db->transaction(function()use($t){$current=$this->a->requests->bound($t['id'],$this->a->browser->binding(),true);if(!in_array($current['state'],['WAITING','CONFIRMED'],true))throw new Problem('请求已处理',409);$this->a->db->run("UPDATE login_requests SET state='CONSUMED' WHERE id=?",[$t['id']]);});
  return $this->afterProof($u,$t,$r);
 }
 public function qrFinish(Request $r):ResponseInterface {
  $id=Http::input($r,'request');$t=$this->a->requests->bound($id,$this->a->browser->binding());
  if($t['intent']==='LINK'){$u=$this->requireUser();$this->recent($u);if($u['id']!==$t['target_user'])throw new Problem('绑定会话已改变，请重新发起',403);}
  $result=$this->a->requests->consume($id,$this->a->browser->binding());
  if($t['intent']==='LINK'){$this->a->audit->log('wechat.linked',$result['user']['id'],$this->ip($r));$this->a->browser->flash('微信绑定成功');return Http::redirect('/account');}
  return $this->afterProof($result['user'],$result['ticket'],$r);
 }
 private function afterProof(array $u,array $t,Request $r):ResponseInterface {
  if($u['totp_secret']){$_SESSION['pending_otp']=['user'=>$u['id'],'version'=>(int)$u['session_version'],'payload'=>json_decode($t['payload'],true),'expires'=>time()+300];return Http::redirect('/login/otp');}
  return $this->finish($u,json_decode($t['payload'],true),$r);
 }
 private function browserRedirect(ResponseInterface $response):ResponseInterface {
  // Chromium applies form-action 'self' to cross-origin redirects after a POST.
  // Destinations here come only from validated OAuth callbacks or stored logout targets.
  $destination=$response->getHeaderLine('Location');
  if(in_array($response->getStatusCode(),[302,303],true)&&preg_match('#^https?://#i',$destination))return $this->a->view->render('continue',['title'=>'正在返回应用','destination'=>$destination,'destinationHost'=>parse_url($destination,PHP_URL_HOST)]);
  return $response;
 }
 private function finish(array $u,array $payload,Request $r):ResponseInterface {
  return $this->a->db->transaction(function()use($u,$payload,$r){$version=$u['session_version'];$u=$this->a->users->byId($u['id'],true);if(!$u||!$u['enabled']||$u['session_version']!==$version)throw new Problem('账号不可用',403);
  // Complete the authorization before exposing any redirect; the library revalidates the client/callback.
  $response=isset($payload['oauth'])?$this->a->oauth()->complete($payload['oauth'],$u['id'],$r):Http::redirect($payload['return']??'/account');
  $this->a->browser->login($u);unset($_SESSION['pending_otp']);$this->a->audit->log('login.success',$u['id'],$this->ip($r));return $this->browserRedirect($response);});
 }
 public function otp(Request $r):ResponseInterface {
  $p=$_SESSION['pending_otp']??null;if(!$p||$p['expires']<=time())throw new Problem('验证请求已失效，请重新登录',410);
  if($r->getMethod()==='POST'){$u=$this->a->users->byId($p['user']);if(!$u||(int)$u['session_version']!==$p['version'])throw new Problem('账号状态已改变，请重新登录',403);if($this->a->users->verifyTotp($u['id'],Http::input($r,'code'),$this->ip($r)))return $this->finish($u,$p['payload'],$r);$error='动态验证码或恢复码无效';}
  return $this->a->view->render('otp',['title'=>'二次验证','error'=>$error??'']);
 }
 public function refresh(Request $r):ResponseInterface {
  $t=$this->a->requests->bound(Http::input($r,'request'),$this->a->browser->binding(),false,true);if(!in_array($t['state'],['WAITING','CONFIRMED','CANCELLED'],true))throw new Problem('请重新发起登录',409);$this->a->requests->cancel($t['id'],$this->a->browser->binding());
  $new=$this->a->requests->create($this->a->browser->binding(),json_decode($t['payload'],true),$t['client_name'],$this->ip($r),$t['intent'],$t['target_user']);$this->remember($new);return Http::redirect('/login?request='.$new['ticket']['id']);
 }
 public function cancel(Request $r):ResponseInterface {$id=Http::input($r,'request');$t=$this->a->requests->bound($id,$this->a->browser->binding());$this->a->requests->cancel($id,$this->a->browser->binding());$payload=json_decode($t['payload'],true);if(isset($payload['oauth'])){$q=$payload['oauth'];$this->a->oauth()->validate($r->withQueryParams($q));return $this->browserRedirect(Http::redirect($q['redirect_uri'].(str_contains($q['redirect_uri'],'?')?'&':'?').http_build_query(['error'=>'access_denied','state'=>$q['state']])));}return Http::redirect('/');}
 public function emailForm(Request $r,string $purpose):ResponseInterface {
  $context=$purpose==='register'?$this->registrationContext($r,Http::query($r,'request')):null;
  return $this->a->view->render('email-form',['title'=>$purpose==='register'?'注册账号':'找回密码','purpose'=>$purpose,'registrationRequest'=>$context['ticket']['id']??'','registrationPlatform'=>$context['name']??'52okp 账号中心']);
 }
 private function registrationContext(Request $r,string $id):?array {
  if($id==='')return null;$t=$this->a->requests->bound($id,$this->a->browser->binding(),false,true);
  if($t['intent']!=='LOGIN'||!in_array($t['state'],['WAITING','CONFIRMED'],true)||$t['created_at']<time()-1800)throw new Problem('注册来源请求已失效，请从应用重新发起',410);
  $payload=json_decode($t['payload'],true);$name='52okp 账号中心';
  if(isset($payload['oauth']))$name=$this->a->oauth()->validate($r->withQueryParams($payload['oauth']))['name'];
  return ['ticket'=>$t,'payload'=>$payload,'name'=>$name];
 }
 public function sendCode(Request $r):ResponseInterface {
  $purpose=Http::input($r,'purpose');if($purpose==='link-email'){$u=$this->requireUser();$this->recent($u);}
  $email=Users::email(Http::input($r,'email'));$id=$this->a->emails->send($purpose,$email,$this->a->browser->binding(),$this->ip($r));$_SESSION['email_codes'][$purpose]=$id;
  return Http::json(['message'=>$purpose==='reset'?'如果该邮箱已验证且账号可用，验证码将发送至邮箱':'验证码已发送，5 分钟内有效']);
 }
 public function register(Request $r):ResponseInterface {
  $context=$this->registrationContext($r,Http::input($r,'request'));
  $email=Users::email(Http::input($r,'email'));$password=Http::input($r,'password');Users::password($password);$name=trim(Http::input($r,'name'));if($name===''||mb_strlen($name)>80)throw new Problem('请输入 1～80 个字符的昵称');
  if(!$this->a->emails->consume($_SESSION['email_codes']['register']??'','register',$email,$this->a->browser->binding(),Http::input($r,'code')))throw new Problem('验证码无效、已过期或尝试次数过多');
  $u=$this->a->users->create($email,$password,$name,$context['payload']['oauth']['client_id']??null);$this->a->audit->log('user.registered',$u['id'],$this->ip($r));$this->a->browser->flash('注册成功，请登录');
  if($context&&isset($context['payload']['oauth'])){$this->a->requests->cancel($context['ticket']['id'],$this->a->browser->binding());$created=$this->a->requests->create($this->a->browser->binding(),$context['payload'],$context['name'],$this->ip($r));$this->remember($created);return Http::redirect('/login?request='.$created['ticket']['id']);}
  return Http::redirect('/login');
 }
 public function resetPassword(Request $r):ResponseInterface {
  $email=Users::email(Http::input($r,'email'));$password=Http::input($r,'password');Users::password($password);
  if(!$this->a->emails->consume($_SESSION['email_codes']['reset']??'','reset',$email,$this->a->browser->binding(),Http::input($r,'code')))throw new Problem('验证码无效或已过期');
  $this->a->db->transaction(function()use($email,$password,$r){$u=$this->a->db->one('SELECT * FROM users WHERE email=? AND enabled=1 AND email_verified=1 FOR UPDATE',[$email]);if(!$u)throw new Problem('验证码无效或账号不可用');$this->a->db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),$u['id']]);$this->a->users->revokeSessions($u['id']);$this->a->audit->log('password.reset',$u['id'],$this->ip($r));});
  $this->a->browser->logout();$this->a->browser->flash('密码已更新，旧登录凭证已撤销，请重新登录');return Http::redirect('/login');
 }
 public function account(Request $r):ResponseInterface {
  if(!$this->a->users->current())return Http::redirect('/login');$u=$this->requireUser();$identities=$this->a->db->all('SELECT app_id FROM identities WHERE user_id=?',[$u['id']]);
  return $this->a->view->render('account',['title'=>'我的账号','identities'=>$identities]);
 }
 public function profile(Request $r):ResponseInterface {$u=$this->requireUser();$name=trim(Http::input($r,'name'));if($name===''||mb_strlen($name)>80)throw new Problem('昵称需为 1～80 个字符');$this->a->db->run('UPDATE users SET display_name=?,updated_at=? WHERE id=?',[$name,time(),$u['id']]);$this->a->browser->flash('资料已更新');return Http::redirect('/account');}
 public function linkEmail(Request $r):ResponseInterface {
  $u=$this->requireUser();$this->recent($u);if($u['email']!==null)throw new Problem('更换已绑定邮箱需要管理员核验，本页仅绑定尚未设置的邮箱');$email=Users::email(Http::input($r,'email'));
  if(!$this->a->emails->consume($_SESSION['email_codes']['link-email']??'','link-email',$email,$this->a->browser->binding(),Http::input($r,'code')))throw new Problem('验证码无效或已过期');
  try{$this->a->db->run('UPDATE users SET email=?,email_verified=1,updated_at=? WHERE id=? AND email IS NULL',[$email,time(),$u['id']]);}catch(\PDOException $e){if($e->getCode()==='23000')throw new Problem('此邮箱已被使用，不会自动合并账号');throw $e;}
  $this->a->browser->flash('邮箱已绑定并验证');return Http::redirect('/account');
 }
 public function changePassword(Request $r):ResponseInterface {
  $u=$this->requireUser();$this->recent($u);$p=Http::input($r,'password');Users::password($p);
  $this->a->rate->require('password-change:'.$u['id'],8,300);if($u['password_hash']&&!password_verify(Http::input($r,'old_password'),$u['password_hash']))throw new Problem('原密码错误');if(!$u['password_hash']&&!$u['email_verified'])throw new Problem('请先绑定并验证邮箱');
  $this->a->db->transaction(function()use($u,$p){$this->lockAccount($u);$this->a->db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($p,PASSWORD_BCRYPT,['cost'=>12]),$u['id']]);$this->a->users->revokeSessions($u['id']);});$this->a->browser->logout();$this->a->browser->flash('密码已更新，请重新登录');return Http::redirect('/login');
 }
 public function linkWechat(Request $r):ResponseInterface {$u=$this->requireUser();$this->recent($u);if(!$this->a->wechat->enabled())throw new Problem('微信绑定尚未配置');$new=$this->a->requests->create($this->a->browser->binding(),['return'=>'/account','target_version'=>(int)$u['session_version']],'52okp 账号中心 · 绑定微信',$this->ip($r),'LINK',$u['id']);$this->remember($new);return Http::redirect('/login?request='.$new['ticket']['id']);}
 public function logout(Request $r):ResponseInterface {$u=$this->a->users->current();if($u)$this->a->db->transaction(fn()=>$this->a->users->revokeSessions($u['id']));$this->a->browser->logout();return Http::redirect('/');}
 public function endSession(Request $r):ResponseInterface {
  if($r->getMethod()==='POST'){$target=$_SESSION['logout_target']??'/';unset($_SESSION['logout_target']);$this->logout($r);return $this->browserRedirect(Http::redirect($target));}
  $target='/';$redirect=Http::query($r,'post_logout_redirect_uri');$hint=Http::query($r,'id_token_hint');
  if($redirect!==''&&$hint!==''){$token=$this->a->oauth()->jwt->verify($hint,'JWT',true);$aud=$token->claims()->get('aud');$client=$this->a->db->one('SELECT * FROM clients WHERE id=? AND enabled=1',[$aud[0]??'']);if(!$client||!in_array($redirect,json_decode($client['logout_uris'],true),true))throw new Problem('退出回调未登记');$current=$this->a->users->current();if($current&&$token->claims()->get('sub')!==$current['id'])throw new Problem('退出凭证与当前账号不匹配',403);$target=$redirect;$state=Http::query($r,'state');if($state!=='')$target.=(str_contains($target,'?')?'&':'?').http_build_query(['state'=>$state]);}
  elseif($redirect!=='')throw new Problem('跳转退出回调需要有效 id_token_hint');$_SESSION['logout_target']=$target;
  return $this->a->view->render('logout',['title'=>'确认退出']);
 }
 public function totp(Request $r):ResponseInterface {
  $u=$this->requireUser();$this->recent($u);
  if($r->getMethod()==='POST'){
   if($u['totp_secret']){if(!$this->a->users->verifyTotp($u['id'],Http::input($r,'code'),$this->ip($r)))throw new Problem('动态验证码或恢复码无效');$this->a->db->transaction(function()use($u){$this->lockAccount($u);$this->a->db->run('UPDATE users SET totp_secret=NULL,recovery_hashes=NULL WHERE id=?',[$u['id']]);$this->a->users->revokeSessions($u['id']);});$this->a->browser->login($this->a->users->byId($u['id']));$this->a->browser->flash('二次验证已关闭');return Http::redirect('/account');}
   $this->a->rate->require('totp-setup:'.$u['id'],8,300);$secret=$_SESSION['totp_setup']??'';if($secret==='')throw new Problem('设置请求已失效');$t=\OTPHP\TOTP::createFromSecret($secret,new OAuth\Clock());if(!$t->verify(Http::input($r,'code')))throw new Problem('动态验证码无效');
   $codes=[];for($i=0;$i<8;$i++)$codes[]=Crypto::random(8);$hashes=array_map(fn($v)=>$this->a->crypto->hash($v),$codes);
   $this->a->db->transaction(function()use($secret,$hashes,$u){$this->lockAccount($u);$this->a->db->run('UPDATE users SET totp_secret=?,totp_last_step=?,recovery_hashes=? WHERE id=?',[$this->a->crypto->encrypt($secret),intdiv(time(),30),json_encode($hashes),$u['id']]);$this->a->users->revokeSessions($u['id']);});$this->a->browser->login($this->a->users->byId($u['id']));unset($_SESSION['totp_setup']);return $this->a->view->render('recovery',['title'=>'保存恢复码','codes'=>$codes]);
  }
  $secret='';if(!$u['totp_secret']){$_SESSION['totp_setup']??=\OTPHP\TOTP::generate(new OAuth\Clock())->getSecret();$secret=$_SESSION['totp_setup'];}
  return $this->a->view->render('totp',['title'=>'动态验证码','secret'=>$secret]);
 }
}
