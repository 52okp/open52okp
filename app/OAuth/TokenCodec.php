<?php
declare(strict_types=1);
namespace Okp\OAuth;
final class TokenCodec {use \League\OAuth2\Server\CryptTrait;public function decode(string $value):array{return json_decode($this->decrypt($value),true,32,JSON_THROW_ON_ERROR);}}
