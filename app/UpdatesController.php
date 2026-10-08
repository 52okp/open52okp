<?php
declare(strict_types=1);
namespace Okp;
use Okp\Update\{Engine,Remote};
use Psr\Http\Message\ServerRequestInterface as Request;
final class UpdatesController {
 private Engine $engine;
 public function __construct(private Application $a){$this->engine=new Engine($a->config->root);}
 private function guard(bool $recent=false):array{$u=(new WebController($this->a))->requireUser(true);if($recent&&($_SESSION['user']['at']??0)<time()-300)throw new Problem('安装或配置更新需要最近 5 分钟内登录验证，请重新登录',403);return $u;}
 private function remote():Remote{$v=$this->engine->identity();return new Remote($v['product'],$this->a->settings->get('updates')['token']??'');}
 private function run(callable $fn){try{return $fn();}catch(Problem $e){throw $e;}catch(\RuntimeException $e){throw new Problem($e->getMessage());}}
 public function index(Request $r){$this->guard();return $this->run(function(){return $this->a->view->render('updates',['title'=>'程序更新','adminSection'=>'updates','identity'=>$this->engine->identity(),'configured'=>!empty($this->a->settings->get('updates')['token']),'checked'=>$this->a->settings->get('updates-checked'),'status'=>$this->engine->status()]);});}
 public function configure(Request $r){$this->guard(true);return $this->run(function()use($r){if(!$this->engine->idle())throw new Problem('更新任务处理中，暂不能更换令牌');$token=trim(Http::input($r,'token'));if($token!==''){new Remote($this->engine->identity()['product'],$token);$this->a->settings->put('updates',['token'=>$token]);$this->a->settings->put('updates-checked',[]);}$this->a->browser->flash('更新配置已加密保存');return Http::redirect('/admin/updates');});}
 public function check(Request $r){$this->guard();return $this->run(function()use($r){$this->a->rate->require('update-check',6,60);$v=$this->engine->identity();$checked=$this->remote()->check($v['version']);$this->a->settings->put('updates-checked',$checked);$this->a->browser->flash($checked['available']?'已获取正式版本信息，请查看兼容性与说明':'当前已经是最新版本');return Http::redirect('/admin/updates');});}
 public function install(Request $r){$u=$this->guard(true);return $this->run(function()use($r,$u){if(Http::input($r,'confirmed')!=='1')throw new Problem('请先确认维护窗口和数据保护说明');if(function_exists('opcache_get_status')&&opcache_get_status(false)!==false)throw new Problem('本站 PHP OPcache 仍开启，请按在线更新文档为本站关闭后安装，避免旧代码缓存混用');$checked=$this->a->settings->get('updates-checked');if(($checked['checked_at']??0)<time()-900||!($checked['available']??false)||!($checked['compatible']??false)||($checked['release']['id']??'')!==Http::input($r,'release_id'))throw new Problem('检查结果已过期或版本不兼容，请重新检查');$seen=$this->engine->status()['worker_seen'];if($seen<time()-180)throw new Problem('更新执行器尚未运行，请先配置宝塔每分钟计划任务');$this->a->db->one('SELECT version FROM schema_versions WHERE version=1');$id=$this->engine->enqueue($this->remote()->release($checked['release']));$this->a->audit->log('update.queued',$u['id'],Http::ip($r,$this->a->config),$id);return Http::redirect('/admin/updates');});}
 public function status(Request $r){$this->guard();return Http::json($this->engine->status());}
}
