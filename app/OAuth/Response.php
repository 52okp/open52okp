<?php
declare(strict_types=1);
namespace Okp\OAuth;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use Okp\Database;
final class Response extends BearerTokenResponse {
 public function __construct(private Jwt $jwt,private Database $db,private Context $context){}
 protected function getExtraParams(AccessTokenEntityInterface $token):array {
  $scopes=array_map(fn($s)=>$s->getIdentifier(),$token->getScopes());$extra=['scope'=>implode(' ',$scopes)];
  if(!in_array('openid',$scopes,true))return $extra;
  $u=$this->db->one('SELECT * FROM users WHERE id=?',[$token->getUserIdentifier()]);
  $claims=['auth_time'=>(int)$this->context->claims['auth_time'],'at_hash'=>\Okp\Crypto::b64(substr(hash('sha256',$token->toString(),true),0,16))];
  if(($this->context->claims['nonce']??'')!=='')$claims['nonce']=$this->context->claims['nonce'];
  if(in_array('profile',$scopes,true)){$claims['name']=$u['display_name'];$claims['preferred_username']=$u['username'];}
  if(in_array('email',$scopes,true)&&$u['email']!==null){$claims['email']=$u['email'];$claims['email_verified']=(bool)$u['email_verified'];}
  $extra['id_token']=$this->jwt->idToken($u['id'],$token->getClient()->getIdentifier(),$claims);return $extra;
 }
}
