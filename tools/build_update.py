# coding: utf-8
"""Build OSS v1 format=2 assets. No server secrets or runtime data are included."""
from pathlib import Path
import argparse,hashlib,json,re,zipfile,runpy
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--version',required=True);p.add_argument('--from',dest='start',required=True);p.add_argument('--notes',required=True,help='UTF-8 notes file');p.add_argument('--output',required=True);args=p.parse_args()
valid=lambda v:bool(re.fullmatch(r'(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})',v))
if not valid(args.version) or not valid(args.start) or tuple(map(int,args.version.split('.')))<=tuple(map(int,args.start.split('.'))):raise SystemExit('Invalid version/from')
identity=json.loads((root/'update-version.json').read_text(encoding='utf-8'));product=identity['product'];notes=Path(args.notes).read_text(encoding='utf-8')
if not re.fullmatch('[a-z][a-z0-9-]{1,47}',product) or len(notes.encode())>65536:raise SystemExit('Invalid product/notes')
out=Path(args.output).resolve();out.mkdir(parents=True,exist_ok=True)
zip_path=out/(product+'-update.zip');manifest_path=out/'update-manifest.json'
if zip_path.exists() or manifest_path.exists():raise SystemExit('Output exists; published versions cannot be overwritten')
runpy.run_path(str(root/'tools/fingerprint.py'))
fixed=['resources/assets.json','bootstrap.php','composer.json','composer.lock','config/app.php','database/schema.sql','public/index.php','public/router.php','bin/console','bin/update-worker.php']
files={}
for name in fixed:files[name]=(root/name).read_bytes()
for directory in ['app','vendor','resources/views','public/assets']:
 for path in (root/directory).rglob('*'):
  if path.is_symlink():raise SystemExit('Symlink in code tree')
  if not path.is_file():continue
  relative=path.relative_to(root)
  if any(part.startswith('.') for part in relative.parts):continue
  files[relative.as_posix()]=path.read_bytes()
files['VERSION']=(args.version+'\n').encode();files['update-version.json']=(json.dumps({'product':product,'version':args.version},separators=(',',':'))+'\n').encode()
if len(files)>10000 or sum(map(len,files.values()))>268435456:raise SystemExit('Package size limit exceeded')
with zipfile.ZipFile(zip_path,'x',zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
 for name,body in sorted(files.items()):archive.writestr(name,body)
manifest={'format':2,'product':product,'version':args.version,'from':args.start,'package':zip_path.name,'size':zip_path.stat().st_size,'sha256':hashlib.sha256(zip_path.read_bytes()).hexdigest(),'notes':notes,'installer':1,'database_schema':1,'migrations':[],'php_min':'8.2.0','php_max':'8.5.99','extensions':['curl','mbstring','openssl','pdo_mysql','sodium','zip'],'composer_lock_sha256':hashlib.sha256(files['composer.lock']).hexdigest(),'files':{name:hashlib.sha256(body).hexdigest() for name,body in sorted(files.items())}}
manifest_path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8');print('Built',zip_path.name,'and',manifest_path.name,'for',product,args.start,'->',args.version)
