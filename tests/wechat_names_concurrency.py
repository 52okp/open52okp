"""Real independent-process nickname allocation on the guarded local *_test database."""
import concurrent.futures, json, os, secrets, subprocess
from pathlib import Path
worker_path=Path(__file__).resolve().parent/'worker.php'
php=os.environ.get('PHP_BINARY','php')
def create(openid):
    result=subprocess.run([php,'-d','extension=sodium',str(worker_path)],input=json.dumps({'action':'wechat-user-create','app':tag,'openid':openid}).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if result.returncode: raise RuntimeError(result.stderr.decode('utf-8','replace'))
    return json.loads(result.stdout)
tag='wx-names-'+secrets.token_hex(8)
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    users=list(pool.map(create,[str(i) for i in range(6)]))
names=[u['display_name'] for u in users]
assert len(set(names))==6 and all(n.startswith('wx_') and n[3:].isdigit() and int(n[3:])>=10052 for n in names)
numbers=sorted(int(n[3:]) for n in names)
assert numbers==list(range(numbers[0],numbers[0]+6))
print('PASS six independent registrations receive distinct consecutive numbers')
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    repeated=list(pool.map(create,['same-identity','same-identity']))
assert repeated[0]==repeated[1]
print('PASS concurrent logins of one identity keep a single account and nickname')
assert create('same-identity')==repeated[0]
print('PASS subsequent process preserves the stored nickname')
