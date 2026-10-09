<?php
declare(strict_types=1);
$a=require dirname(__DIR__).'/bootstrap.php';
if($a->config->production()||!str_ends_with($a->config->values['db']['database'],'_test'))throw new RuntimeException('Dedicated test database required');
$count=0;function checkWechatName(bool $condition,string $name):void{global $count;if(!$condition)throw new RuntimeException('FAIL '.$name);$count++;echo "PASS $name\n";}
$tag='wx-name-'.Okp\Crypto::random(8);$key='wechat-display-name-sequence';
$a->db->pdo->beginTransaction();
try{
 $a->db->run('DELETE FROM settings WHERE name=?',[$key]);
 $first=$a->users->fromWechat($tag,'first');$second=$a->users->fromWechat($tag,'second');
 checkWechatName($first['display_name']==='wx_10052','first new WeChat account starts at wx_10052');
 checkWechatName($second['display_name']==='wx_10053','next new account receives the next number');
 checkWechatName($a->users->fromWechat($tag,'first')['display_name']==='wx_10052','returning identity keeps its existing default nickname');
 $a->db->run('UPDATE users SET display_name=?,updated_at=? WHERE id=?',['我自己的昵称',time(),$first['id']]);
 checkWechatName($a->users->fromWechat($tag,'first')['display_name']==='我自己的昵称','custom nickname survives a later WeChat login');
 $third=$a->users->fromWechat($tag,'third');checkWechatName($third['display_name']==='wx_10054','returning logins and nickname changes do not consume or reuse numbers');
 $email=$a->users->create($tag.'@example.test','Wechat-Name-Password42','邮箱用户');$linked=$a->users->fromWechat($tag,'linked',$email['id']);
 checkWechatName($linked['display_name']==='邮箱用户','binding WeChat preserves an existing account nickname');
 checkWechatName($a->users->fromWechat($tag,'fourth')['display_name']==='wx_10055','binding an account does not consume a nickname number');
 $legacy=Okp\Crypto::uuid();$a->db->run('INSERT INTO users(id,username,display_name,created_at,updated_at) VALUES(?,?,?,?,?)',[$legacy,'wx_'.Okp\Crypto::random(12),'微信用户',time(),time()]);$legacyId=hash('sha256',$tag."\0legacy");$a->db->run('INSERT INTO identities(id,app_id,openid,user_id) VALUES(?,?,?,?)',[$legacyId,$tag,'legacy',$legacy]);
 checkWechatName($a->users->fromWechat($tag,'legacy')['display_name']==='微信用户','existing account nickname is not rewritten by login');
 $a->db->pdo->exec('SAVEPOINT failed_creation');$failed=false;try{$a->users->fromWechat($tag,'failed',null,'missing-source-application');}catch(Okp\Problem){$failed=true;}$a->db->pdo->exec('ROLLBACK TO SAVEPOINT failed_creation');
 checkWechatName($failed&&$a->users->fromWechat($tag,'after-failure')['display_name']==='wx_10056','failed account creation rolls back nickname reservation');
 $a->db->run('UPDATE settings SET value=? WHERE name=?',[$a->crypto->encrypt('{"next":0}'),$key]);$failed=false;try{$a->users->fromWechat($tag,'invalid-counter');}catch(RuntimeException){$failed=true;}
 checkWechatName($failed,'invalid counter is rejected without restarting the sequence');
 echo "Completed: $count WeChat nickname assertions.\n";
}finally{if($a->db->pdo->inTransaction())$a->db->pdo->rollBack();}
$before=$a->db->one('SELECT value FROM settings WHERE name=?',[$key]);$usersBefore=(int)$a->db->one('SELECT COUNT(*) AS n FROM users')['n'];$failed=false;
try{$a->users->fromWechat($tag,'top-level-failed',null,'missing-source-application');}catch(Okp\Problem){$failed=true;}
checkWechatName($failed&&$a->db->one('SELECT value FROM settings WHERE name=?',[$key])===$before&&(int)$a->db->one('SELECT COUNT(*) AS n FROM users')['n']===$usersBefore,'real failed creation leaves no account and preserves the persistent counter');
$retryKey='wx-name-retry-'.Okp\Crypto::random(8);$calls=0;$rolledBack=false;
try{
 $a->db->transaction(function()use($a,$retryKey,&$calls,&$rolledBack){$calls++;if($calls===2)$rolledBack=$a->db->one('SELECT value FROM settings WHERE name=?',[$retryKey])===null;$a->settings->put($retryKey,['attempt'=>$calls]);if($calls===1){$e=new PDOException('Injected deadlock',40001);$e->errorInfo=['40001',1213,'Injected deadlock'];throw $e;}},5);
 checkWechatName($calls===2&&$rolledBack&&$a->settings->get($retryKey)['attempt']===2,'opted-in deadlock retry rolls back before rerunning and commits once');
 $calls=0;$failed=false;try{$a->db->transaction(function()use(&$calls){$calls++;$e=new PDOException('Injected deadlock',40001);$e->errorInfo=['40001',1213,'Injected deadlock'];throw $e;},3);}catch(PDOException){$failed=true;}
 checkWechatName($failed&&$calls===3,'deadlock retries stop at the configured bound');
 $calls=0;$failed=false;try{$a->db->transaction(function()use(&$calls){$calls++;throw new Okp\Problem('Invalid registration');},5);}catch(Okp\Problem){$failed=true;}
 checkWechatName($failed&&$calls===1,'ordinary failures are never retried');
 $calls=0;$failed=false;try{$a->db->transaction(function()use(&$calls){$calls++;$e=new PDOException('Injected deadlock',40001);$e->errorInfo=['40001',1213,'Injected deadlock'];throw $e;});}catch(PDOException){$failed=true;}
 checkWechatName($failed&&$calls===1,'existing transactions keep single-attempt behavior by default');
}finally{$a->db->run('DELETE FROM settings WHERE name=?',[$retryKey]);}
echo "Final: $count WeChat nickname and transaction assertions.\n";
