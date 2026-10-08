<?php
declare(strict_types=1);
namespace Okp\OAuth;
use League\OAuth2\Server\Entities as E;
use League\OAuth2\Server\Entities\Traits as T;
final class Client implements E\ClientEntityInterface {
 use T\EntityTrait;use T\ClientTrait;
 public function __construct(array $r){$this->identifier=$r['id'];$this->name=$r['name'];$this->redirectUri=json_decode($r['redirect_uris'],true,32,JSON_THROW_ON_ERROR);$this->isConfidential=(bool)$r['confidential'];}
 public function supportsGrantType(string $type):bool{return in_array($type,['authorization_code','refresh_token'],true);}
}
final class User implements E\UserEntityInterface {use T\EntityTrait;public function __construct(string $id){$this->identifier=$id;}}
final class Scope implements E\ScopeEntityInterface {use T\EntityTrait;use T\ScopeTrait;public function __construct(string $id){$this->identifier=$id;}}
final class Code implements E\AuthCodeEntityInterface {use T\EntityTrait;use T\TokenEntityTrait;use T\AuthCodeTrait;}
final class Refresh implements E\RefreshTokenEntityInterface {use T\EntityTrait;use T\RefreshTokenTrait;}
final class Access implements E\AccessTokenEntityInterface {
 use T\EntityTrait;use T\TokenEntityTrait;
 private ?string $encoded=null;
 public function __construct(private Jwt $jwt){}
 public function setPrivateKey(\League\OAuth2\Server\CryptKeyInterface $key):void{}
 public function toString():string{return $this->encoded??=$this->jwt->access($this);}
}
