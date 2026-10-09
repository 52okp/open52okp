<?php
declare(strict_types=1);
namespace Okp;
use PDO;
final class Database {
 public readonly PDO $pdo;
 public function __construct(array $config) {
  $this->pdo=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $this->pdo->exec("SET time_zone='+00:00'");
  $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
 }
 public function run(string $sql,array $args=[]):\PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
 public function one(string $sql,array $args=[]):?array{return $this->run($sql,$args)->fetch()?:null;}
 public function all(string $sql,array $args=[]):array{return $this->run($sql,$args)->fetchAll();}
 public function transaction(callable $work,int $attempts=1):mixed {
  if($this->pdo->inTransaction())return $work();
  for($attempt=1;;$attempt++){
   $this->pdo->beginTransaction();
   try{$result=$work();$this->pdo->commit();return $result;}catch(\Throwable $e){
    if($this->pdo->inTransaction())$this->pdo->rollBack();
    if(!($e instanceof \PDOException)||(string)$e->getCode()!=='40001'||(int)($e->errorInfo[1]??0)!==1213||$attempt>=max(1,min(5,$attempts)))throw $e;
    usleep(10000*$attempt);
   }
  }
 }
}
