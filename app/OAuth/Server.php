<?php
declare(strict_types=1);
namespace Okp\OAuth;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\{AuthCodeGrant,RefreshTokenGrant};
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface};
use Slim\Psr7\Response as HttpResponse;
use Okp\{Config,Database,Problem};
final class Server {
 public readonly Jwt $jwt;public readonly Repository $repository;private AuthorizationServer $server;private Context $context;
 public function __construct(private Config $config,private Database $db){
  $this->jwt=new Jwt($config);$this->context=new Context();$this->repository=new Repository($db,$this->jwt,$this->context);
  $this->server=new AuthorizationServer($this->repository,$this->repository,$this->repository,'file://'.$config->path('storage/keys/private.pem'),$config->values['key'],new Response($this->jwt,$db,$this->context));
  $auth=new AuthCodeGrant($this->repository,$this->repository,new \DateInterval('PT1M'));$auth->setRefreshTokenTTL(new \DateInterval('PT8H'));
  $refresh=new RefreshTokenGrant($this->repository);$refresh->setRefreshTokenTTL(new \DateInterval('PT8H'));
  $this->server->enableGrantType($auth,new \DateInterval('PT5M'));$this->server->enableGrantType($refresh,new \DateInterval('PT5M'));
 }
 public function validate(Request $request):array {
  $q=$request->getQueryParams();
  foreach(['client_id','redirect_uri','response_type','scope','state','nonce','code_challenge','code_challenge_method'] as $key)if(!isset($q[$key])||!is_string($q[$key])||strlen($q[$key])>2048)throw new Problem('授权请求缺少有效参数：'.$key);
  if($q['response_type']!=='code'||$q['code_challenge_method']!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43}$/',$q['code_challenge'])||strlen($q['state'])<8||strlen($q['state'])>255||strlen($q['nonce'])<8||strlen($q['nonce'])>255)throw new Problem('必须使用授权码、随机 state/nonce 和 PKCE S256');
  if(!in_array('openid',explode(' ',$q['scope']),true))throw new Problem('授权范围必须包含 openid');
  if(isset($q['request'])||isset($q['request_uri'])||isset($q['claims'])||isset($q['response_mode'])&&$q['response_mode']!=='query')throw new Problem('不支持此授权请求模式');
  if(isset($q['prompt'])&&!in_array($q['prompt'],['login','consent'],true))throw new Problem('请从应用主动发起登录，不支持静默授权');
  $auth=$this->server->validateAuthorizationRequest($request);
  return ['query'=>array_intersect_key($q,array_flip(['client_id','redirect_uri','response_type','scope','state','nonce','code_challenge','code_challenge_method'])),'name'=>$auth->getClient()->getName()];
 }
 public function complete(array $query,string $user,Request $request):ResponseInterface {
  return $this->db->transaction(function()use($query,$user,$request){$auth=$this->server->validateAuthorizationRequest($request->withQueryParams($query));$auth->setUser(new User($user));$auth->setAuthorizationApproved(true);$this->context->claims=['nonce'=>$query['nonce']];return $this->server->completeAuthorizationRequest($auth,new HttpResponse());});
 }
 public function token(Request $request):ResponseInterface {
  if(!str_starts_with(strtolower($request->getHeaderLine('Content-Type')),'application/x-www-form-urlencoded'))throw new Problem('令牌接口需要 application/x-www-form-urlencoded');
  $this->rejectDuplicateAuth($request);$this->context->replay=false;$this->context->claims=[];
  $this->db->pdo->beginTransaction();
  try{$response=$this->server->respondToAccessTokenRequest($request,new HttpResponse());$this->db->pdo->commit();return $response;}
  catch(OAuthServerException $e){if($this->context->replay)$this->db->pdo->commit();else $this->db->pdo->rollBack();return $e->generateHttpResponse(new HttpResponse());}
  catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
 }
 private function rejectDuplicateAuth(Request $r):void{if($r->getHeaderLine('Authorization')!==''&&isset(($r->getParsedBody()??[])['client_secret']))throw new Problem('不能同时使用两种客户端认证方式');}
 public function authenticateClient(Request $r,bool $confidential=true):string {
  $this->rejectDuplicateAuth($r);$d=(array)$r->getParsedBody();$id=$d['client_id']??'';$secret=$d['client_secret']??null;
  if(preg_match('/^Basic (.+)$/i',$r->getHeaderLine('Authorization'),$m)){$basic=base64_decode($m[1],true);if($basic===false||!str_contains($basic,':'))throw new Problem('客户端认证失败',401);[$id,$secret]=array_map('urldecode',explode(':',$basic,2));}
  if(!is_string($id)||(!is_string($secret)&&$secret!==null))throw new Problem('客户端认证失败',401);
  $entity=$this->repository->getClientEntity($id);
  if(!$entity||($confidential&&!$entity->isConfidential())||!$this->repository->validateClient($id,$secret,null))throw new Problem('客户端认证失败',401);return $id;
 }
 public function bearer(Request $r):array {
  if(!preg_match('/^Bearer ([^\s]+)$/i',$r->getHeaderLine('Authorization'),$m))throw new Problem('缺少访问凭证',401);
  $token=$this->jwt->verify($m[1]);$id=$token->claims()->get('jti');if(!is_string($id)||$this->repository->isAccessTokenRevoked($id))throw new Problem('访问凭证已撤销',401);
  $row=$this->db->one('SELECT * FROM access_tokens WHERE id=?',[$id]);return $row;
 }
 public function userinfo(Request $r):array {
  $a=$this->bearer($r);$scopes=json_decode($a['scopes'],true);if(!in_array('openid',$scopes,true))throw new Problem('访问范围不足',403);
  $u=$this->db->one('SELECT * FROM users WHERE id=?',[$a['user_id']]);$claims=['sub'=>$u['id']];
  if(in_array('profile',$scopes,true)){$claims['name']=$u['display_name'];$claims['preferred_username']=$u['username'];}
  if(in_array('email',$scopes,true)&&$u['email']!==null){$claims['email']=$u['email'];$claims['email_verified']=(bool)$u['email_verified'];}return $claims;
 }
 private function identify(string $token):?array {
  try{$parsed=$this->jwt->verify($token,'at+jwt',true);return ['kind'=>'access','row'=>$this->db->one('SELECT * FROM access_tokens WHERE id=?',[$parsed->claims()->get('jti')])];}
  catch(\Throwable){try{$codec=new TokenCodec();$codec->setEncryptionKey($this->config->values['key']);$p=$codec->decode($token);if(!isset($p['refresh_token_id']))return null;$r=$this->db->one('SELECT r.*,a.client_id,a.user_id,a.scopes FROM refresh_tokens r JOIN access_tokens a ON a.id=r.access_id WHERE r.id=?',[$p['refresh_token_id']]);return ['kind'=>'refresh','row'=>$r];}catch(\Throwable){return null;}}
 }
 public function introspect(Request $r):array {
  $client=$this->authenticateClient($r);$raw=((array)$r->getParsedBody())['token']??'';if(!is_string($raw))return ['active'=>false];$found=$this->identify($raw);$row=$found['row']??null;
  if(!$row||$row['client_id']!==$client||$row['revoked']||$row['expires_at']<=time())return ['active'=>false];
  if($found['kind']==='access'){if($this->repository->isAccessTokenRevoked($row['id']))return ['active'=>false];}else{$a=$this->db->one('SELECT a.revoked,a.session_version,a.auth_time,u.enabled,u.session_version AS current_version,c.enabled AS client_enabled FROM access_tokens a JOIN users u ON u.id=a.user_id JOIN clients c ON c.id=a.client_id WHERE a.id=?',[$row['access_id']]);if(!$a||$a['revoked']||!$a['enabled']||!$a['client_enabled']||$a['session_version']!==$a['current_version']||(int)$a['auth_time']+28800<=time())return ['active'=>false];}
  return ['active'=>true,'client_id'=>$client,'sub'=>$row['user_id'],'iss'=>$this->config->issuer(),'exp'=>(int)$row['expires_at'],'scope'=>implode(' ',json_decode($row['scopes'],true)),'token_type'=>$found['kind']==='access'?'Bearer':'refresh_token'];
 }
 public function revoke(Request $r):void {
  $client=$this->authenticateClient($r,false);$raw=((array)$r->getParsedBody())['token']??'';if(!is_string($raw))return;$found=$this->identify($raw);$row=$found['row']??null;if(!$row||$row['client_id']!==$client)return;
  $this->db->transaction(function()use($found,$row){$id=$found['kind']==='access'?$row['id']:$row['access_id'];$this->repository->revokeAccessToken($id);$this->db->run('UPDATE refresh_tokens SET revoked=1 WHERE access_id=?',[$id]);});
 }
 public function discovery():array {
  $i=$this->config->issuer();$p=$i.'/protocol/openid-connect';return ['issuer'=>$i,'authorization_endpoint'=>$p.'/auth','token_endpoint'=>$p.'/token','userinfo_endpoint'=>$p.'/userinfo','jwks_uri'=>$p.'/certs','end_session_endpoint'=>$p.'/logout','revocation_endpoint'=>$p.'/revoke','introspection_endpoint'=>$p.'/token/introspect','response_types_supported'=>['code'],'response_modes_supported'=>['query'],'grant_types_supported'=>['authorization_code','refresh_token'],'subject_types_supported'=>['public'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>['client_secret_basic','client_secret_post','none'],'scopes_supported'=>['openid','profile','email'],'code_challenge_methods_supported'=>['S256'],'claims_supported'=>['iss','sub','aud','exp','iat','auth_time','nonce','name','preferred_username','email','email_verified','at_hash']];
 }
}
