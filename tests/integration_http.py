# coding: utf-8
"""HTTP and independent-process concurrency regression. Local development only."""
import concurrent.futures, http.cookiejar, html, json, os, re, subprocess, urllib.request, urllib.parse, urllib.error, hashlib, base64, secrets
from pathlib import Path
BASE='http://127.0.0.1:8808'
ROOT=Path(__file__).resolve().parent
PHP=os.environ.get('PHP_BINARY','php')
count=0
def check(condition,name):
 global count
 if not condition: raise AssertionError(name)
 count+=1;print('PASS',name)
def worker(data):
 result=subprocess.run([PHP,'-d','extension=sodium',str(ROOT/'worker.php')],input=json.dumps(data).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if result.returncode: raise RuntimeError(result.stderr.decode('utf-8','replace'))
 return json.loads(result.stdout)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None
jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect())
def request(path,data=None,raw=None):
 body=urllib.parse.urlencode(data).encode() if data is not None else raw
 req=urllib.request.Request(BASE+path,body)
 if body is not None:req.add_header('Content-Type','application/x-www-form-urlencoded')
 try:r=opener.open(req)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read().decode('utf-8'),r.headers
def field(body,key):return html.unescape(re.search(r'name="'+key+r'" value="([^"]*)"',body).group(1))
s,b,h=request('/api');check(s==200 and '接入 52okp 统一登录' in b and 'php-authorize' in b,'API documentation is public and includes integration examples')
page=b
s,b,h=request('/api/spec');spec=json.loads(b);check(s==200 and spec['authorization']['pkce_required_for_all_clients'] and 'Set-Cookie' not in h,'public JSON spec needs no login or session cookie')
s,b,h=request('/realms/52okp/.well-known/openid-configuration');check(spec['discovery']==json.loads(b),'documented endpoints match live OIDC discovery')
for asset in re.findall(r'(?:href|src)="(/assets/api-docs[^"]+)"',page):
 s,b,h=request(asset);check(s==200,'API documentation asset available')
example=html.unescape(re.search(r'<code id="php-authorize">(.*?)</code>',page,re.S).group(1))
import tempfile
with tempfile.TemporaryDirectory() as temp:
 path=Path(temp)/'authorize.php';path.write_bytes(example.encode('utf-8'))
 result=subprocess.run([PHP,'-l',str(path)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 check(result.returncode==0,'rendered PHP authorization example has valid syntax')
seed=worker({'action':'seed'})
s,b,h=request('/');check(s==200 and '账号中心' in b,'home renders');check(h['Cache-Control']=='no-store','HTML not cached')
s,b,h=request('/admin');check(s==401,'admin requires login')
s,b,h=request('/admin/updates');check(s==401,'program update page requires administrator login')
s,b,h=request('/admin/updates/status');check(s==401,'update task status requires administrator login')
s,b,h=request('/login?target=/admin');check(s==303,'login request created');path=h['Location'];s,b,h=request(path);csrf=field(b,'csrf');ticket=field(b,'request');check('账号密码' in b,'password login form renders')
s,b,h=request('/login/password',{'request':ticket,'username':seed['email'],'password':seed['password']});check(s==403,'CSRF missing rejected')
s,b,h=request('/login/password',{'csrf':csrf,'request':ticket,'username':seed['email'],'password':seed['password']});check(s==303 and h['Location']=='/admin','password login reaches admin')
s,b,h=request('/admin');check(s==200 and 'id="members"' in b and '微信小程序' not in b,'admin defaults to users only');csrf=field(b,'csrf')
s,b,h=request('/admin?section=clients');check(s==200 and 'action="/admin/client"' in b and 'id="members"' not in b,'clients section renders independently')
s,b,h=request('/admin?section=settings');check(s==200 and '微信小程序' in b and '邮箱验证码' in b and 'id="members"' not in b,'service settings render independently')
s,b,h=request('/admin?section=users&q=absent-account');check(s==200 and 'section=users&amp;page=' in b,'user search pagination retains section')
s,b,h=request('/admin?section=unknown');check(s==404,'unknown admin section rejected')
s,b,h=request('/admin/updates');check(s==200 and '程序更新' in b and 'open52okp' in b,'program update UI renders correct project');csrf=field(b,'csrf')
s,b,h=request('/admin/updates/configure',{'token':'invalid'});check(s==403,'update configuration requires CSRF')
s,b,h=request('/admin/updates/configure',{'csrf':csrf,'token':'invalid'});check(s==400,'invalid update token rejected')
s,b,h=request('/admin/updates/install',{'csrf':csrf,'confirmed':'1','release_id':'invalid'});check(s==400,'install refuses missing checked release')
s,b,h=request('/account');check(s==200 and '基本资料' in b,'account center renders');csrf=field(b,'csrf')
s,b,h=request('/account/profile',{'csrf':csrf,'name':'<script>alert(1)</script>'});check(s==303,'profile update succeeds');s,b,h=request('/account');check('&lt;script&gt;alert(1)&lt;/script&gt;' in b and '<script>alert(1)</script>' not in b,'profile HTML escaped')
s,b,h=request('/admin/client',{'csrf':csrf,'id':'http-client-'+seed['id'][:8],'name':'HTTP client','redirect_uris':'https://example.test/callback','logout_uris':'https://example.test/logout','confidential':'1','enabled':'1'});check(s==200 and '保存应用密钥' in b,'client creation shows one-time secret')
s,b,h=request('/admin/client',{'csrf':csrf,'id':'invalid-client','name':'bad','redirect_uris':'https://*.example.test/callback','confidential':'1','enabled':'1'});check(s==400,'wildcard redirect rejected')
s,b,h=request('/admin/user',{'csrf':csrf,'id':seed['id'],'enabled':'0'});check(s==400,'admin cannot disable self')
s,b,h=request('/login');path=h['Location'];s,b,h=request(path);ticket=field(b,'request');worker({'action':'expire','ticket':ticket});s,b,h=request('/login/refresh',{'csrf':csrf,'request':ticket});check(s==303 and h['Location']!=path,'expired request can refresh through HTTP')
s,b,h=request('/realms/52okp/.well-known/openid-configuration');discovery=json.loads(b);check(s==200 and discovery['issuer']==BASE+'/realms/52okp','OIDC discovery exact issuer')
s,b,h=request('/realms/52okp/protocol/openid-connect/certs');check(s==200 and json.loads(b)['keys'][0]['alg']=='RS256','public JWKS available')
s,b,h=request('/realms/52okp/protocol/openid-connect/token',raw=b'client_id=a&client_id=b');check(s==400,'duplicate OAuth parameters rejected')
s,b,h=request('/realms/52okp/wechat/confirm',{'ticket':'bad'});check(s==400,'mini-program confirmation requires JSON')
for path in ['/register','/forgot-password','/privacy','/account/totp']:
 s,b,h=request(path);check(s==200,'page renders '+path)
# Independent PHP processes hit the same MySQL rows concurrently; PHP web server serialization cannot hide races.
data=worker({'action':'prepare','id':seed['id']})
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(worker,[{'action':'exchange','data':data}]*2))
check(sorted(x['status'] for x in results)[0]==200 and sum(x['status']==200 for x in results)==1,'concurrent code exchange succeeds exactly once')
tokens=next(x['body'] for x in results if x['status']==200)
refresh={'grant_type':'refresh_token','client_id':data['client_id'],'client_secret':data['client_secret'],'refresh_token':tokens['refresh_token']}
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(worker,[{'action':'exchange','data':refresh}]*2))
check(sum(x['status']==200 for x in results)==1,'concurrent refresh succeeds exactly once')
flow=worker({'action':'prepare','id':seed['id']});state=secrets.token_hex(16);nonce=secrets.token_hex(16);challenge=base64.urlsafe_b64encode(hashlib.sha256(flow['code_verifier'].encode()).digest()).decode().rstrip('=')
query={'client_id':flow['client_id'],'redirect_uri':flow['redirect_uri'],'response_type':'code','scope':'openid','state':state,'nonce':nonce,'code_challenge':challenge,'code_challenge_method':'S256'}
s,b,h=request('/realms/52okp/protocol/openid-connect/auth?'+urllib.parse.urlencode(query));check(s==303 and h['Location'].startswith('/login?'),'OIDC authorization requires fresh login despite session')
s,b,h=request(h['Location']);csrf=field(b,'csrf');ticket=field(b,'request');s,b,h=request('/login/password',{'csrf':csrf,'request':ticket,'username':seed['email'],'password':seed['password']});callback=urllib.parse.parse_qs(urllib.parse.urlparse(html.unescape(re.search(r'data-login-redirect href="([^"]+)"',b).group(1))).query);check(s==200 and callback.get('state')==[state] and bool(callback.get('code')),'HTTP login returns a navigation page with validated OIDC code and state');check("form-action 'self'" in h['Content-Security-Policy'],'callback fix preserves same-origin form restriction')
flow['code']=callback['code'][0];s,b,h=request('/realms/52okp/protocol/openid-connect/token',flow);check(s==200 and 'id_token' in json.loads(b),'HTTP token endpoint exchanges browser authorization')
s,b,h=request('/account');csrf=field(b,'csrf')
s,b,h=request('/logout',{'csrf':csrf});check(s==303,'logout succeeds');s,b,h=request('/admin');check(s==401,'logout clears browser authentication')

# Registration/reset use a seeded single-use email challenge, never a production mail bypass.
s,b,h=request('/register');csrf=field(b,'csrf');email='registered-'+seed['id'][:8]+'@example.test';session=next(c.value for c in jar if c.name=='okp_dev')
code=worker({'action':'email','session':session,'purpose':'register','email':email})['code']
s,b,h=request('/register',{'csrf':csrf,'email':email,'password':seed['password'],'name':'新注册用户','code':code});check(s==303,'registration consumes verified email challenge')
s,b,h=request('/forgot-password');csrf=field(b,'csrf');session=next(c.value for c in jar if c.name=='okp_dev');code=worker({'action':'email','session':session,'purpose':'reset','email':email})['code'];new_password=seed['password']+'Reset7'
s,b,h=request('/forgot-password',{'csrf':csrf,'email':email,'password':new_password,'code':code});check(s==303,'password recovery succeeds')
s,b,h=request('/login');s,b,h=request(h['Location']);csrf=field(b,'csrf');ticket=field(b,'request');s,b,h=request('/login/password',{'csrf':csrf,'request':ticket,'username':email,'password':seed['password']});check(s==200 and '账号或密码错误' in b,'old password fails after reset')
s,b,h=request('/login/password',{'csrf':csrf,'request':ticket,'username':email,'password':new_password});check(s==303 and h['Location']=='/account','new password works after reset')
s,b,h=request('/account/totp');csrf=field(b,'csrf');secret=re.search(r'<code class="secret">([^<]+)</code>',b).group(1);otp=worker({'action':'totp-code','secret':secret})['code']
s,b,h=request('/account/totp',{'csrf':csrf,'code':otp});check(s==200 and '恢复码仅显示一次' in b,'MFA enabled and recovery codes shown');recovery=re.search(r'<div class="secret"><div>([a-f0-9]+)</div>',b).group(1)
s,b,h=request('/account');csrf=field(b,'csrf');s,b,h=request('/logout',{'csrf':csrf});s,b,h=request('/login');s,b,h=request(h['Location']);csrf=field(b,'csrf');ticket=field(b,'request');s,b,h=request('/login/password',{'csrf':csrf,'request':ticket,'username':email,'password':new_password});check(s==303 and h['Location']=='/login/otp','password alone cannot bypass MFA')
s,b,h=request('/login/otp');csrf=field(b,'csrf');s,b,h=request('/login/otp',{'csrf':csrf,'code':recovery});check(s==303 and h['Location']=='/account','recovery code completes MFA login')
s,b,h=request('/account/totp');csrf=field(b,'csrf');s,b,h=request('/account/totp',{'csrf':csrf,'code':recovery});check(s==400,'spent recovery code cannot disable MFA')
# Confirm the real ticket state machine with a mocked WeChat exchange, then finish through HTTP.
worker({'action':'wechat-enable'})
try:
 query['state']=secrets.token_hex(16);query['nonce']=secrets.token_hex(16)
 s,b,h=request('/realms/52okp/protocol/openid-connect/auth?'+urllib.parse.urlencode(query));s,b,h=request(h['Location']);csrf=field(b,'csrf');ticket=field(b,'request');key=html.unescape(re.search(r'data-key="([^"]+)"',b).group(1))
 worker({'action':'wechat-confirm','ticket':ticket})
 s,b,h=request('/realms/52okp/wechat/status/'+ticket+'?key='+key);check(s==200 and json.loads(b)['state']=='CONFIRMED','mini-program proof reaches confirmed state')
 s,b,h=request('/login/finish',{'csrf':csrf,'request':ticket});destination=html.unescape(re.search(r'data-login-redirect href="([^"]+)"',b).group(1));callback=urllib.parse.parse_qs(urllib.parse.urlparse(destination).query)
 check(s==200 and destination.startswith(flow['redirect_uri']+'?') and callback['state']==[query['state']],'QR completion preserves registered callback and original state')
 flow['code']=callback['code'][0];s,b,h=request('/realms/52okp/protocol/openid-connect/token',flow);check(s==200 and 'id_token' in json.loads(b),'QR callback code exchanges successfully with original PKCE')
 s,b,h=request('/account');csrf=field(b,'csrf');s,b,h=request('/login/finish',{'csrf':csrf,'request':ticket});check(s==409,'confirmed QR ticket cannot be consumed twice')
 query['state']=secrets.token_hex(16)
 s,b,h=request('/realms/52okp/protocol/openid-connect/auth?'+urllib.parse.urlencode(query));s,b,h=request(h['Location']);csrf=field(b,'csrf');ticket=field(b,'request')
 s,b,h=request('/login/cancel',{'csrf':csrf,'request':ticket});destination=html.unescape(re.search(r'data-login-redirect href="([^"]+)"',b).group(1));cancel=urllib.parse.parse_qs(urllib.parse.urlparse(destination).query)
 check(s==200 and cancel['error']==['access_denied'] and cancel['state']==[query['state']],'cancel returns to registered app through safe navigation')
finally: worker({'action':'wechat-restore'})
print('Completed:',count,'HTTP/concurrency assertions.')
