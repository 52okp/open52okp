<?php
declare(strict_types=1);
namespace Okp;
final class Problem extends \RuntimeException {public function __construct(string $message,public readonly int $status=400){parent::__construct($message);}}
