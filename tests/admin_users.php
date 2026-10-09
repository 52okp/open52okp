<?php
declare(strict_types=1);
$a=require dirname(__DIR__).'/bootstrap.php';
if($a->config->production()||!str_ends_with($a->config->values['db']['database'],'_test'))throw new RuntimeException('Dedicated test database required');
$count=0;function checkDirectory(bool $condition,string $message):void{global $count;if(!$condition)throw new RuntimeException('FAIL '.$message);$count++;echo "PASS $message\n";}
$tag='directory-'.Okp\Crypto::random(5);$client=$tag.'-a';$other=$tag.'-b';$directory=new Okp\AdminUsers($a->db);
$a->db->pdo->beginTransaction();
try{
 foreach([$client,$other] as $id)$a->db->run('INSERT INTO clients(id,name,confidential,redirect_uris,logout_uris,created_at) VALUES(?,?,0,?,?,?)',[$id,$id===''.$client?'<应用 A>':'应用 B','["https://example.test/callback"]','[]',time()]);
 $user=$a->users->create($tag.'@example.test','Directory-Test-Password42','目录用户',$client);
 $direct=$a->users->create($tag.'-direct@example.test','Directory-Test-Password42','直接注册');
 $source=$directory->details($user['id'])['member'];
 checkDirectory($source['registration_platform']==='<应用 A>'&&$source['registration_source']==='邮箱注册','email registration retains validated source application');
 checkDirectory($directory->details($direct['id'])['member']['registration_platform']==='52okp 账号中心','direct registration has account center source');
 checkDirectory($directory->listing($tag,$client,'unrecorded',1)['totalMembers']===2,'registration source alone does not imply application login');
 $a->db->run("DELETE FROM audit_events WHERE user_id=? AND event='user.registration-source'",[$direct['id']]);
 checkDirectory($directory->details($direct['id'])['member']['registration_source']==='历史来源未记录','unknown historical registration source is not guessed');
 $wx=$a->users->fromWechat('wx-test-'.$tag,'openid-'.$tag,null,$client);
 checkDirectory($directory->details($wx['id'])['member']['registration_source']==='微信扫码注册','WeChat first account creation records source');
 $a->users->fromWechat('wx-test-'.$tag,'openid-'.$tag,null,$other);
 checkDirectory($directory->details($wx['id'])['member']['registration_platform']==='<应用 A>','returning WeChat login does not overwrite registration source');
 $a->users->fromWechat('wx-linked-'.$tag,'linked-'.$tag,$direct['id'],$other);
 checkDirectory($directory->details($direct['id'])['member']['registration_source']==='历史来源未记录','linking WeChat does not invent an original registration source');
 $request=(new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','https://example.test/');
 $verifier=Okp\Crypto::b64(random_bytes(48));$query=['client_id'=>$client,'redirect_uri'=>'https://example.test/callback','response_type'=>'code','scope'=>'openid','state'=>Okp\Crypto::random(16),'nonce'=>Okp\Crypto::random(16),'code_challenge'=>Okp\Crypto::b64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256'];
 $response=$a->oauth()->complete($query,$user['id'],$request);parse_str(parse_url($response->getHeaderLine('Location'),PHP_URL_QUERY),$callback);
 $record=$directory->details($user['id'])['member']['applications'][$client];
 checkDirectory(!(bool)$record['exchanged'],'completed authorization is distinct from token exchange');
 $codes=$a->db->one('SELECT * FROM auth_codes WHERE user_id=? AND client_id=? ORDER BY auth_time DESC LIMIT 1',[$user['id'],$client]);
 // Token exchange owns its transaction. Roll back fixtures only after testing repository persistence directly.
 $context=new Okp\OAuth\Context();$context->claims=$codes;$repository=new Okp\OAuth\Repository($a->db,$a->oauth()->jwt,$context);
 $token=$repository->getNewToken($repository->getClientEntity($client),[],$user['id']);$token->setIdentifier(Okp\Crypto::random(30));$token->setExpiryDateTime(new DateTimeImmutable('+5 minutes'));
 $repository->persistNewAccessToken($token);$repository->persistNewAccessToken((function()use($repository,$client,$user){$t=$repository->getNewToken($repository->getClientEntity($client),[],$user['id']);$t->setIdentifier(Okp\Crypto::random(30));$t->setExpiryDateTime(new DateTimeImmutable('+5 minutes'));return $t;})());
 checkDirectory((bool)$directory->details($user['id'])['member']['applications'][$client]['exchanged'],'token issuance records successful application credential exchange');
 checkDirectory((int)$a->db->one("SELECT COUNT(*) AS n FROM audit_events WHERE event='application.first-token' AND user_id=? AND detail=?",[$user['id'],$client])['n']===1,'repeated token issuance keeps one durable first record');
 $a->db->run("DELETE FROM audit_events WHERE user_id=? AND event IN ('application.first-authorized','application.first-token')",[$user['id']]);$a->audit->retainApplicationEvidence();$a->audit->retainApplicationEvidence();
 checkDirectory((int)$a->db->one("SELECT COUNT(*) AS n FROM audit_events WHERE event='application.first-token' AND user_id=? AND detail=?",[$user['id'],$client])['n']===1,'cleanup backfills historical credential evidence once');
 checkDirectory($directory->details($direct['id'])['member']['registration_source']==='历史来源未记录','historical application retention never guesses registration source');
 $a->db->run('DELETE FROM access_tokens WHERE user_id=?',[$user['id']]);$a->db->run('DELETE FROM auth_codes WHERE user_id=?',[$user['id']]);
 checkDirectory((bool)$directory->details($user['id'])['member']['applications'][$client]['exchanged'],'application evidence survives expired token cleanup');
 $listed=$directory->listing($tag,$client,'all',1);checkDirectory($listed['totalMembers']===1&&$listed['members'][0]['id']===$user['id'],'selected app includes the right user without duplicates');
 checkDirectory($directory->listing($tag,$other,'all',1)['totalMembers']===0,'other application excludes unrelated users');
 checkDirectory($directory->listing($tag,$client,'unrecorded',1)['totalMembers']===1,'negative application filter excludes recorded user');
 checkDirectory($directory->listing('目录用户',$client,'all',1)['totalMembers']===1,'nickname search composes with application filter');
 $failed=false;try{$directory->listing('','missing-application','all',1);}catch(Okp\Problem){$failed=true;}checkDirectory($failed,'unknown application rejected');
 $failed=false;try{$directory->listing('','','unrecorded',1);}catch(Okp\Problem){$failed=true;}checkDirectory($failed,'missing application cannot apply negative filter');
 for($i=0;$i<51;$i++){$id=Okp\Crypto::uuid();$a->db->run('INSERT INTO users(id,username,display_name,created_at,updated_at) VALUES(?,?,?,?,?)',[$id,$tag.'-page-'.$i,$tag.'-page',time(),time()]);}
 $first=$directory->listing($tag.'-page','','all',1);$second=$directory->listing($tag.'-page','','all',2);
 checkDirectory($first['totalMembers']===51&&count($first['members'])===50&&count($second['members'])===1,'pagination counts and boundaries are accurate');
 checkDirectory(!array_intersect(array_column($first['members'],'id'),array_column($second['members'],'id')),'stable pagination has no duplicate users');
 checkDirectory($directory->listing($tag.'-page','','all',100)['page']===2,'out of range page is clamped');
 echo "Completed: $count directory assertions.\n";
}finally{if($a->db->pdo->inTransaction())$a->db->pdo->rollBack();}
