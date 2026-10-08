# coding: utf-8
from pathlib import Path
import hashlib,json
root=Path(__file__).resolve().parents[1];assets=root/'public/assets';manifest={}
for name in ['app.css','app.js']:
 data=(assets/name).read_bytes();base,ext=name.rsplit('.',1);target=base+'.'+hashlib.sha256(data).hexdigest()[:16]+'.'+ext;(assets/target).write_bytes(data);manifest[name]=target
(root/'resources/assets.json').write_text(json.dumps(manifest)+'\n',encoding='utf-8')
