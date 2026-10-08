<?php
declare(strict_types=1);
// Linux-only integration test of actual detached launching and supervision; no database or network.
$source=dirname(__DIR__);foreach(['Remote','Package','Engine','Launcher'] as $class)require $source.'/app/Update/'.$class.'.php';
use Okp\Update\{Engine,Launcher};
if(PHP_OS_FAMILY==='Windows')throw new RuntimeException('Run this test on Linux');
$workspace=sys_get_temp_dir().'/okp-launcher-'.bin2hex(random_bytes(6));mkdir($workspace,0700);$count=0;
function check(bool $ok,string $name):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;$count++;}
function fixture(string $mode):array{global $workspace,$source;$root=$workspace.'/'.$mode;mkdir($root.'/storage/updates',0700,true);mkdir($root.'/app/Update',0755,true);mkdir($root.'/bin',0755,true);foreach(['Remote','Package','Engine'] as $c)copy($source.'/app/Update/'.$c.'.php',$root.'/app/Update/'.$c.'.php');$id=bin2hex(random_bytes(12));Engine::atomic($root.'/storage/updates/state.json',json_encode(['id'=>$id,'phase'=>'queued','history'=>[]]));file_put_contents($root.'/mode',$mode);file_put_contents($root.'/bin/update-worker.php', <<<'WORKER'
<?php
$root=dirname(__DIR__);$gate=fopen($root.'/storage/update-gate.lock','c');flock($gate,LOCK_EX);$path=$root.'/storage/updates/state.json';$s=json_decode(file_get_contents($path),true);$mode=file_get_contents($root.'/mode');
if($mode==='bootstrap-failed')exit(1);
if($mode==='child-killed'&&$s['phase']==='queued'){$s['phase']='installing';file_put_contents($path,json_encode($s));exit(77);}
usleep(500000);$s['phase']=$mode==='child-killed'?'rolled_back':'complete';file_put_contents($path,json_encode($s));
WORKER
);return [$root,$id];}
foreach(['success','child-killed','bootstrap-failed'] as $mode){[$root,$id]=fixture($mode);$launcher=new Launcher($root);$php=$launcher->preflight();$gate=fopen($root.'/storage/update-gate.lock','c');flock($gate,LOCK_SH);$launcher->start($php,$id);flock($gate,LOCK_UN);fclose($gate);check(is_file($root.'/storage/updates/started-'.$id),'detached supervisor acknowledged '.$mode);for($i=0;$i<100;$i++){usleep(100000);$s=(new Engine($root))->state();if(in_array($s['phase'],['complete','rolled_back','failed'],true))break;}$expected=['success'=>'complete','child-killed'=>'rolled_back','bootstrap-failed'=>'failed'][$mode];check($s['phase']===$expected,'supervisor final state '.$expected);}
[$root,$id]=fixture('invalid-id');try{(new Launcher($root))->start(PHP_BINARY,'; unsafe');check(false,'invalid identifier rejected');}catch(RuntimeException){check(!is_file($root.'/storage/updates/supervisor.php'),'invalid identifier rejected before launch');}
[$root,$id]=fixture('missing-php');$failed=false;try{(new Launcher($root))->start('/missing/php',$id);}catch(RuntimeException){$failed=true;(new Engine($root))->failQueued($id,'start failed');}check($failed&&(new Engine($root))->state()['phase']==='failed','failed launch leaves retryable task');
echo 'Completed: '.$count.' launcher assertions; fixtures: '.$workspace.PHP_EOL;
