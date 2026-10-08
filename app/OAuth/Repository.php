<?php
declare(strict_types=1);
namespace Okp\OAuth;
use League\OAuth2\Server\Repositories as R;
use League\OAuth2\Server\Entities as E;
use League\OAuth2\Server\Exception\OAuthServerException;
use Okp\Database;
final class Repository implements R\ClientRepositoryInterface,R\ScopeRepositoryInterface,R\AuthCodeRepositoryInterface,R\AccessTokenRepositoryInterface,R\RefreshTokenRepositoryInterface {
 public function __construct(private Database $db,private Jwt $jwt,public readonly Context $context){}
 public function getClientEntity(string $id):?E\ClientEntityInterface{$r=$this->db->one('SELECT * FROM clients WHERE id=? AND enabled=1'.($this->db->pdo->inTransaction()?' FOR UPDATE':''),[$id]);return $r?new Client($r):null;}
 public function validateClient(string $id,?string $secret,?string $grant):bool{$r=$this->db->one('SELECT * FROM clients WHERE id=? AND enabled=1'.($this->db->pdo->inTransaction()?' FOR UPDATE':''),[$id]);return $r && in_array($grant,['authorization_code','refresh_token',null],true) && (!$r['confidential'] || ($secret!==null&&password_verify($secret,$r['secret_hash']??'')));}
 public function getScopeEntityByIdentifier(string $id):?E\ScopeEntityInterface{return in_array($id,['openid','profile','email'],true)?new Scope($id):null;}
 public function finalizeScopes(array $scopes,string $grant,E\ClientEntityInterface $client,?string $user=null,?string $code=null):array{return $scopes;}
 public function getNewAuthCode():E\AuthCodeEntityInterface{return new Code();}
 public function persistNewAuthCode(E\AuthCodeEntityInterface $code):void {
  $u=$this->db->one('SELECT * FROM users WHERE id=? AND enabled=1 FOR UPDATE',[$code->getUserIdentifier()]);if(!$u)throw OAuthServerException::accessDenied('Account disabled');
  $this->db->run('INSERT INTO auth_codes (id,user_id,client_id,nonce,auth_time,session_version,expires_at) VALUES (?,?,?,?,?,?,?)',[$code->getIdentifier(),$u['id'],$code->getClient()->getIdentifier(),$this->context->claims['nonce']??'',time(),$u['session_version'],$code->getExpiryDateTime()->getTimestamp()]);
 }
 public function revokeAuthCode(string $id):void{$this->db->run('UPDATE auth_codes SET revoked=1 WHERE id=?',[$id]);}
 public function isAuthCodeRevoked(string $id):bool {
  $r=$this->db->one('SELECT * FROM auth_codes WHERE id=? FOR UPDATE',[$id]);
  if(!$r||$r['revoked']||$r['expires_at']<=time()||!$this->userValid($r))return true;
  $this->context->claims=$r;return false;
 }
 private function userValid(array $r):bool{$u=$this->db->one('SELECT enabled,session_version FROM users WHERE id=? FOR UPDATE',[$r['user_id']]);return $u&&(int)$u['enabled']===1&&(int)$u['session_version']===(int)$r['session_version'];}
 public function getNewToken(E\ClientEntityInterface $client,array $scopes,?string $user=null):E\AccessTokenEntityInterface {
  $t=new Access($this->jwt);$t->setClient($client);if($user!==null)$t->setUserIdentifier($user);foreach($scopes as $s)$t->addScope($s);return $t;
 }
 public function persistNewAccessToken(E\AccessTokenEntityInterface $token):void {
  $r=$this->context->claims;
  if(!$r||$r['user_id']!==$token->getUserIdentifier()||!$this->userValid($r))throw OAuthServerException::accessDenied('Account unavailable');
  $this->db->run('INSERT INTO access_tokens (id,user_id,client_id,scopes,nonce,auth_time,session_version,expires_at) VALUES (?,?,?,?,?,?,?,?)',[$token->getIdentifier(),$r['user_id'],$token->getClient()->getIdentifier(),json_encode(array_map(fn($s)=>$s->getIdentifier(),$token->getScopes()),JSON_THROW_ON_ERROR),$r['nonce'],$r['auth_time'],$r['session_version'],$token->getExpiryDateTime()->getTimestamp()]);
 }
 public function revokeAccessToken(string $id):void{$this->db->run('UPDATE access_tokens SET revoked=1 WHERE id=?',[$id]);}
 public function isAccessTokenRevoked(string $id):bool {
  $r=$this->db->one('SELECT a.revoked,a.expires_at,a.session_version,u.session_version AS current_version,u.enabled,c.enabled AS client_enabled FROM access_tokens a JOIN users u ON u.id=a.user_id JOIN clients c ON c.id=a.client_id WHERE a.id=?',[$id]);
  return !$r||$r['revoked']||$r['expires_at']<=time()||!$r['enabled']||!$r['client_enabled']||$r['session_version']!==$r['current_version'];
 }
 public function getNewRefreshToken():?E\RefreshTokenEntityInterface{return new Refresh();}
 public function persistNewRefreshToken(E\RefreshTokenEntityInterface $t):void{$limit=(int)$this->context->claims['auth_time']+28800;$t->setExpiryDateTime((new \DateTimeImmutable())->setTimestamp(min($limit,$t->getExpiryDateTime()->getTimestamp())));$this->db->run('INSERT INTO refresh_tokens (id,access_id,expires_at) VALUES (?,?,?)',[$t->getIdentifier(),$t->getAccessToken()->getIdentifier(),$t->getExpiryDateTime()->getTimestamp()]);}
 public function revokeRefreshToken(string $id):void{$this->db->run('UPDATE refresh_tokens SET revoked=1 WHERE id=?',[$id]);}
 public function isRefreshTokenRevoked(string $id):bool {
  $r=$this->db->one('SELECT * FROM refresh_tokens WHERE id=? FOR UPDATE',[$id]);if(!$r||$r['expires_at']<=time())return true;
  $a=$this->db->one('SELECT * FROM access_tokens WHERE id=? FOR UPDATE',[$r['access_id']]);if(!$a)return true;
  if($r['revoked']) {
   // Reuse of an already rotated refresh token revokes this user's grants for that client.
   $this->db->run('UPDATE access_tokens SET revoked=1 WHERE user_id=? AND client_id=?',[$a['user_id'],$a['client_id']]);
   $this->db->run('UPDATE refresh_tokens r JOIN access_tokens a ON a.id=r.access_id SET r.revoked=1 WHERE a.user_id=? AND a.client_id=?',[$a['user_id'],$a['client_id']]);
   $this->context->replay=true;return true;
  }
  if($a['revoked']||(int)$a['auth_time']+28800<=time()||!$this->userValid($a))return true;
  $this->context->claims=$a;return false;
 }
}
