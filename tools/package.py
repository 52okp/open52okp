# coding: utf-8
"""Build a complete BaoTa upload archive without local secrets or runtime state."""
from pathlib import Path
import hashlib, json, zipfile
root=Path(__file__).resolve().parents[1]
out=root.parent/'dist';out.mkdir(exist_ok=True)
archive=out/'52okp-account-php-1.0.5.zip'
files=[]
for path in root.rglob('*'):
 if not path.is_file():continue
 relative=path.relative_to(root);parts=relative.parts
 if path.name=='.env' or '__pycache__' in parts or path.suffix in ['.pyc','.log'] or path.name.startswith('.fixture'):continue
 if parts[0]=='storage' and relative.as_posix()!='storage/.gitkeep':continue
 if '.git' in parts:continue
 if path.name.startswith('.env') and path.name!='.env.example':continue
 files.append(path)
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for path in sorted(files):z.write(path,path.relative_to(root).as_posix())
with zipfile.ZipFile(archive) as z:
 names=z.namelist()
 assert all(not n.endswith('.pem') and n!='.env' and not n.startswith('storage/sessions/') for n in names)
 assert all(n in names for n in ['public/index.php','vendor/autoload.php','composer.lock','database/schema.sql','bin/console','.env.example','docs/BAOTA.md'])
 assert z.testzip() is None
sha=hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(sha+'  '+archive.name+'\n',encoding='utf-8')
print(json.dumps({'archive':str(archive),'files':len(files),'bytes':archive.stat().st_size,'sha256':sha},ensure_ascii=False))
