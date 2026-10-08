<?php
declare(strict_types=1);
namespace Okp\OAuth;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint as V;
use Lcobucci\JWT\UnencryptedToken;
use Okp\{Config,Crypto,Problem};
final class Jwt {
 private Configuration $jwt;public readonly string $kid;
 public function __construct(private Config $config){
  $private=file_get_contents($config->path('storage/keys/private.pem'));$public=file_get_contents($config->path('storage/keys/public.pem'));
  if(!$private||!$public)throw new \RuntimeException('签名密钥缺失，请先初始化');
  $this->kid=substr(hash('sha256',$public),0,24);
  $this->jwt=Configuration::forAsymmetricSigner(new Sha256(),InMemory::plainText($private),InMemory::plainText($public));
 }
 private function builder(string $type,string $sub,string $aud,int $expires):\Lcobucci\JWT\Builder {
  $now=new \DateTimeImmutable();return $this->jwt->builder()->withHeader('kid',$this->kid)->withHeader('typ',$type)->issuedBy($this->config->issuer())->relatedTo($sub)->permittedFor($aud)->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->setTimestamp($expires));
 }
 public function access(Access $t):string {
  $scopes=array_map(fn($s)=>$s->getIdentifier(),$t->getScopes());
  return $this->builder('at+jwt',$t->getUserIdentifier(),$t->getClient()->getIdentifier(),$t->getExpiryDateTime()->getTimestamp())->identifiedBy($t->getIdentifier())->withClaim('scopes',$scopes)->withClaim('scope',implode(' ',$scopes))->getToken($this->jwt->signer(),$this->jwt->signingKey())->toString();
 }
 public function idToken(string $user,string $client,array $claims):string {
  $builder=$this->builder('JWT',$user,$client,time()+300)->identifiedBy(Crypto::random(16));
  foreach($claims as $k=>$v)$builder=$builder->withClaim($k,$v);
  return $builder->getToken($this->jwt->signer(),$this->jwt->signingKey())->toString();
 }
 public function verify(string $raw,string $type='at+jwt',bool $allowExpired=false):UnencryptedToken {
  try {
   $token=$this->jwt->parser()->parse($raw);
   if(!$token instanceof UnencryptedToken || $token->headers()->get('alg')!=='RS256'||$token->headers()->get('typ')!==$type||$token->headers()->get('kid')!==$this->kid)throw new \RuntimeException();
   $constraints=[new V\SignedWith($this->jwt->signer(),$this->jwt->verificationKey()),new V\IssuedBy($this->config->issuer())];
   if(!$allowExpired)$constraints[]=new V\StrictValidAt(new Clock(),new \DateInterval('PT5S'));
   $this->jwt->validator()->assert($token,...$constraints);return $token;
  }catch(\Throwable){throw new Problem('访问凭证无效或已过期',401);}
 }
 public function jwks():array {
  $key=openssl_pkey_get_public(file_get_contents($this->config->path('storage/keys/public.pem')));$d=openssl_pkey_get_details($key);
  return ['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>$this->kid,'n'=>Crypto::b64($d['rsa']['n']),'e'=>Crypto::b64($d['rsa']['e'])]]];
 }
}
