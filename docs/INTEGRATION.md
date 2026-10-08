# 业务网站接入 PHP 登录中枢

在线文档：`https://open.52okp.com/api`；机器可读接口说明：`https://open.52okp.com/api/spec`（1.0.4 起提供）。

Issuer：`https://open.52okp.com/realms/52okp`

Discovery：`https://open.52okp.com/realms/52okp/.well-known/openid-configuration`

通过成熟的 OIDC 客户端库接入。每个业务应用单独登记 client_id、HTTPS 登录回调和退出回调，服务端应用保管 client_secret。客户端 ID、回调不得通配，不支持 implicit、密码 grant、SAML、Keycloak Admin API。

## 授权流程

用户点击业务网站“登录” → 网站生成独立随机 state、nonce、PKCE verifier 并存入自己的服务端会话 → 跳转授权端点。code_challenge 是 verifier 的 SHA256 摘要经无填充 base64url 编码，code_challenge_method 必须 S256，公开或保密客户端都要求 PKCE。

授权端点 `/protocol/openid-connect/auth`（相对 issuer）参数：

| 参数 | 值 |
| --- | --- |
| response_type | code |
| client_id | 后台登记的 ID |
| redirect_uri | 精确匹配的回调地址 |
| scope | openid，可按需加 profile、email |
| state / nonce | 各自不可预测，8～255 字符，建议 32 随机字节 |
| code_challenge | SHA256(verifier) 的 base64url，43 字符 |
| code_challenge_method | S256 |

每次授权都会重新验证账号密码或微信，并在启用 TOTP 时继续验证。已有中枢 Cookie 不会静默跳过验证；prompt=none 被拒绝。用户取消时，已校验回调收到 `error=access_denied` 与原 state；未通过回调验证的错误只在中枢显示。

回调先用常量时间比较 state 并消耗该本地请求，再由网站服务端 POST `/protocol/openid-connect/token`，Content-Type 为 application/x-www-form-urlencoded，提交 grant_type=authorization_code、code、原 redirect_uri、code_verifier。客户端认证使用 HTTP Basic 或 client_secret_post 二选一；公共客户端提交 client_id。授权码有效期 60 秒且单次兑换。

响应包含 access_token、refresh_token、id_token、token_type、expires_in、scope。验证 ID Token：从 certs/JWKS 按 kid 获取公钥，只允许 RS256，校验签名、issuer、audience=自己的 client_id、过期时间、nonce 和 at_hash。**不能只 base64 解码就相信身份**。

用 `(issuer, sub)` 作为业务网站的唯一账号映射，不用昵称、email 或 OpenID 代替 sub。本 PHP 版全新账号会产生新的 UUID sub，不沿用旧 Java 测试账号的 subject。

## 凭证与资料

- access_token 约 5 分钟；在 Authorization: Bearer 中调用 `/protocol/openid-connect/userinfo`。
- `openid` 提供 sub，`profile` 提供 name/preferred_username，`email` 提供已设置邮箱及 email_verified。
- refresh_token 单次轮换，必须原子保存新的 refresh_token，不能并发刷新。原始身份验证后最长 8 小时，之后重新主动登录。
- 重用已经轮换的 refresh_token 会撤销该用户在该应用的访问/刷新凭证，业务网站应重新发起登录。
- `/protocol/openid-connect/token/introspect` 仅允许保密客户端自省自身凭证，参数 token；`/protocol/openid-connect/revoke` 撤销自身凭证。
- 改密码、禁用用户、改变 TOTP、退出中枢会使旧中枢凭证失效。**业务站仅离线验签时，已签 JWT 在其 exp 前仍可能被本地接受**；需要立即感知撤销的服务应调用自省/UserInfo，并限制本地会话有效期。
- 未开放通用跨域 CORS。普通网站使用服务端换码/BFF，不能把密钥放进浏览器。公共客户端类型不等于已经提供浏览器跨域换码支持。

## 退出

业务网站先销毁自己的本地会话，再导航到 `/protocol/openid-connect/logout`。可提供已验证的 id_token_hint、登记过的 post_logout_redirect_uri 和自己的 state。页面由用户确认后 POST 退出，中枢验证 CSRF、撤销账号凭证并回到登记地址。

不实现后台推送单点登出。其他业务站已有的本地 Session 不会自动消失，必须在业务端制定定期自省或会话过期策略。

## 已发布小程序接口（保持原路径）

前缀 `https://open.52okp.com/realms/52okp/wechat`：

- GET `/request/{ticket}`：platform、expiresAt（Unix 毫秒）、state、intent（LOGIN/LINK）。
- POST `/confirm` JSON：`ticket`、`code`（本次 wx.login 的临时 code）、`accepted`（确认时必须 true）、`decision`（confirm/cancel）。取消也校验微信凭证。
- GET `/qrcode/{ticket}?key=...`：PNG/JPEG。key 仅留在网页，不能作为小程序确认凭证。
- GET `/status/{ticket}?key=...`：网页每 3 秒查询状态；不重复向微信生成二维码。

微信 access_token 与二维码内容加锁缓存；微信拒绝、配置错误、应用自己的限流会显示错误，不伪造二维码成功状态。同一个 ticket 只能确认、消费一次，且网页消费必须绑定原浏览器。微信绑定只允许已登录的目标账号，不按相同邮箱或昵称自动合并账号。
