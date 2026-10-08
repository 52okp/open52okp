<?php
declare(strict_types=1);
namespace Okp\OAuth;
final class Clock implements \Psr\Clock\ClockInterface {public function now():\DateTimeImmutable{return new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}}
