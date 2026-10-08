<?php
declare(strict_types=1);
namespace Okp\Update;
use RuntimeException;
final class Package {
 public static function allowed(string $path):bool {
  if($path===''||strlen($path)>240||str_contains($path,'\\')||!preg_match('#^[A-Za-z0-9_./@+-]+$#D',$path))return false;
  foreach(explode('/',$path) as $segment)if($segment===''||$segment==='.'||$segment==='..'||str_ends_with($segment,'.')||str_starts_with($segment,'.')||preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i',$segment))return false;
  return in_array($path,['bootstrap.php','composer.json','composer.lock','VERSION','update-version.json','resources/assets.json','config/app.php','database/schema.sql','public/index.php','public/router.php','bin/console','bin/update-worker.php'],true)||preg_match('#^(app|vendor|resources/views|public/assets)/#',$path)===1;
 }
 public static function inventory(string $root):array {
  $out=[];foreach(['app','vendor','resources/views','public/assets'] as $dir){if(!is_dir($root.'/'.$dir))continue;$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir,\FilesystemIterator::SKIP_DOTS));foreach($iterator as $file){$name=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if($file->isLink())throw new RuntimeException('代码路径含链接，拒绝更新');if($file->isFile()&&self::allowed($name))$out[$name]=hash_file('sha256',$file->getPathname());}}
  foreach(['bootstrap.php','composer.json','composer.lock','VERSION','update-version.json','resources/assets.json','config/app.php','database/schema.sql','public/index.php','public/router.php','bin/console','bin/update-worker.php'] as $name)if(is_file($root.'/'.$name)){if(is_link($root.'/'.$name))throw new RuntimeException('代码文件不能是链接');$out[$name]=hash_file('sha256',$root.'/'.$name);}ksort($out);return $out;
 }
 public static function environment(array $m):void {
  if(($m['installer']??null)!==1||($m['database_schema']??null)!==1||($m['migrations']??null)!==[])throw new RuntimeException('此更新器仅支持数据库结构不变的代码更新，迁移包需要专门升级方案');
  if(!is_string($m['php_min']??null)||!is_string($m['php_max']??null)||version_compare(PHP_VERSION,$m['php_min'],'<')||version_compare(PHP_VERSION,$m['php_max'],'>'))throw new RuntimeException('当前 PHP 版本不满足更新要求');
  if(($m['extensions']??null)!==['curl','mbstring','openssl','pdo_mysql','sodium','zip'])throw new RuntimeException('运行扩展要求不兼容');foreach($m['extensions'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('缺少 PHP 扩展：'.$ext);
 }
 public static function unpack(string $zipPath,string $stage,array $m,string $root,string $project,string $current):array {
  self::environment($m);if(($m['product']??'')!==$project||($m['from']??'')!==$current||!Remote::version($m['version']??'')||!version_compare($m['version'],$current,'>'))throw new RuntimeException('项目或起始版本不匹配，需要先安装中间版本');
  $files=$m['files']??null;if(!is_array($files)||count($files)<10||count($files)>10000)throw new RuntimeException('更新文件清单缺失或过大');$seen=[];foreach($files as $name=>$hash){if(!is_string($name)||!self::allowed($name)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||isset($seen[strtolower($name)]))throw new RuntimeException('文件清单包含危险或重复路径');$seen[strtolower($name)]=true;}
  foreach(['resources/assets.json','public/index.php','bootstrap.php','app/Application.php','vendor/autoload.php','composer.lock','update-version.json','VERSION','database/schema.sql','app/Update/Engine.php','app/Update/Package.php','app/Update/Remote.php','bin/update-worker.php'] as $required)if(!isset($files[$required]))throw new RuntimeException('更新包不是完整运行版本');
  if(!hash_equals(hash_file('sha256',$root.'/database/schema.sql'),$files['database/schema.sql']))throw new RuntimeException('不允许在代码更新中改变数据库结构');
  if(($m['composer_lock_sha256']??'')!==$files['composer.lock'])throw new RuntimeException('Composer 依赖锁不匹配');
  $zip=new \ZipArchive();if($zip->open($zipPath)!==true)throw new RuntimeException('无法读取 ZIP');$total=0;$entries=[];
  try{if($zip->numFiles!==count($files))throw new RuntimeException('ZIP 与文件清单数量不一致');for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$name=$s['name'];$zip->getExternalAttributesIndex($i,$os,$attr);$type=($attr>>16)&0170000;if(!self::allowed($name)||isset($entries[strtolower($name)])||!isset($files[$name])||($s['encryption_method']??0)!==0||($type!==0&&$type!==0100000)||$s['size']>52428800||$s['size']<0)throw new RuntimeException('ZIP 包含不安全路径、链接、加密项或超大文件');$entries[strtolower($name)]=true;$total+=$s['size'];if($total>268435456)throw new RuntimeException('解压后大小超出 256 MiB 限制');if($s['size']>1048576&&$s['size']>max(1,$s['comp_size'])*200)throw new RuntimeException('ZIP 压缩比异常');}
   if(disk_free_space(dirname($stage))<$total*4+67108864)throw new RuntimeException('磁盘空间不足以解压、备份和回滚');
   if(!mkdir($stage,0700,true))throw new RuntimeException('无法创建隔离解压目录');
   for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$name=$s['name'];$dest=$stage.'/'.$name;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);$in=$zip->getStream($name);$out=fopen($dest,'xb');if(!$in||!$out)throw new RuntimeException('无法读取更新文件');$n=stream_copy_to_stream($in,$out,$s['size']+1);fclose($in);fclose($out);if($n!==$s['size']||!hash_equals($files[$name],hash_file('sha256',$dest)))throw new RuntimeException('包内文件摘要不匹配');}
  }finally{$zip->close();}
  $version=json_decode(file_get_contents($stage.'/update-version.json'),true);if(($version['product']??'')!==$project||($version['version']??'')!==$m['version']||trim(file_get_contents($stage.'/VERSION'))!==$m['version'])throw new RuntimeException('包内版本不匹配');
  if(!str_contains(file_get_contents($stage.'/public/index.php'),'OKP_UPDATE_GATE_V1'))throw new RuntimeException('新版本缺少维护窗口保护');
  $assets=json_decode(file_get_contents($stage.'/resources/assets.json'),true);foreach(['app.css','app.js'] as $asset){$name=$assets[$asset]??'';if(!preg_match('/^app\.[a-f0-9]{16}\.(css|js)$/D',$name)||!isset($files['public/assets/'.$name]))throw new RuntimeException('缺少带指纹的静态资源');}
  return $files;
 }
}
