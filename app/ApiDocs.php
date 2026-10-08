<?php
declare(strict_types=1);
namespace Okp;
final class ApiDocs {
 public function __construct(private Application $a){}
 public function spec():array {
  $d=$this->a->oauth()->discovery();
  return ['document_format'=>1,'name'=>'52okp 账号中枢接入 API','issuer'=>$d['issuer'],'discovery_url'=>$d['issuer'].'/.well-known/openid-configuration','documentation_url'=>$this->a->config->values['url'].'/api','protocol'=>'OpenID Connect / OAuth 2.0 Authorization Code + PKCE S256','discovery'=>$d,
   'endpoints'=>[
    ['name'=>'发现配置','method'=>'GET','url'=>$d['issuer'].'/.well-known/openid-configuration','auth'=>'无需认证'],
    ['name'=>'发起登录','method'=>'GET','url'=>$d['authorization_endpoint'],'auth'=>'浏览器跳转；state、nonce、PKCE 必填'],
    ['name'=>'兑换 / 刷新令牌','method'=>'POST','url'=>$d['token_endpoint'],'auth'=>'Basic 或表单客户端认证；公共客户端提交 client_id'],
    ['name'=>'用户资料','method'=>'GET / POST','url'=>$d['userinfo_endpoint'],'auth'=>'Authorization: Bearer ACCESS_TOKEN'],
    ['name'=>'签名公钥','method'=>'GET','url'=>$d['jwks_uri'],'auth'=>'无需认证'],
    ['name'=>'令牌自省','method'=>'POST','url'=>$d['introspection_endpoint'],'auth'=>'仅保密客户端查询自身令牌'],
    ['name'=>'撤销令牌','method'=>'POST','url'=>$d['revocation_endpoint'],'auth'=>'客户端认证；只能撤销自身令牌'],
    ['name'=>'退出中枢','method'=>'GET → 页面确认 POST','url'=>$d['end_session_endpoint'],'auth'=>'浏览器流程；退出跳转需要 id_token_hint']],
   'authorization'=>['response_type'=>'code','required'=>['client_id','redirect_uri','scope','state','nonce','code_challenge','code_challenge_method'],'scope_required'=>'openid','state_nonce_length'=>['min'=>8,'max'=>255],'code_challenge_method'=>'S256','pkce_required_for_all_clients'=>true,'redirect_uri'=>'已登记 HTTPS 地址，精确匹配','response_mode'=>'query'],
   'token'=>['content_type'=>'application/x-www-form-urlencoded','authorization_code_required'=>['grant_type','code','redirect_uri','code_verifier'],'refresh_required'=>['grant_type','refresh_token'],'client_authentication'=>'client_secret_basic 或 client_secret_post 二选一；公共客户端提供 client_id，不提供 secret','authorization_code_ttl_seconds'=>60,'access_token_ttl_seconds'=>300,'id_token_ttl_seconds'=>300,'maximum_session_seconds'=>28800,'refresh_rotation'=>true],
   'userinfo'=>['openid'=>['sub'],'profile'=>['name','preferred_username'],'email'=>['email','email_verified'],'email_fields_optional'=>true,'account_mapping'=>['iss','sub']],
   'id_token_validation'=>['RS256 signature using trusted discovery JWKS and kid','iss equals configured issuer','aud contains own client_id','exp, iat and nbf validity','nonce equals stored login request nonce','at_hash matches access_token','userinfo sub equals verified id_token sub'],
   'constraints'=>['每次授权主动验证身份，不支持 prompt=none 静默登录','未开放浏览器跨域 CORS，网站使用服务端换码或 BFF','不支持 password、client_credentials、implicit grant、SAML 或 Keycloak Admin API','没有后台推送单点登出，业务应用自行销毁或校验本地会话','refresh_token 单次轮换，刷新并发需由客户端串行化；重放会撤销该用户在该应用的凭证','业务网站不直接调用内部微信票据接口，扫码通过授权登录页完成']];
 }
 public function page(){return $this->a->view->render('api',['title'=>'API 接入文档','spec'=>$this->spec()]);}
}
