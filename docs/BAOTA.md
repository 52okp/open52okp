# 宝塔 PHP 网站部署（全新初始化）

目标：`https://open.52okp.com`，MySQL 5.7。原 Java 安装目录 `/www/server/keycloak` 保留作回退；**PHP 包不要解压到这个 Java 目录**。

## 1. 创建 PHP 网站

宝塔安装 Nginx、PHP 8.3、MySQL 5.7。在 PHP 扩展中启用 `pdo_mysql`、`openssl`、`sodium`、`mbstring`、`curl`。PHP-FPM 与命令行 PHP 都需这些扩展。

进入 **网站 → PHP 项目 → 添加站点**。如果 `open.52okp.com` 已有站点，可在原站点配置切换 PHP 版本和根目录；不要添加重复域名站点。

- 网站目录：`/www/wwwroot/open.52okp.com`
- PHP：8.3
- 创建独立数据库和独立数据库用户，例如 `okp_account`；字符集 utf8mb4。
- 上传完整 PHP 发布包到网站目录解压。解压后 `app`、`public`、`vendor`、`bin` 应直接位于此目录，避免多套一层文件夹。
- 网站设置 → 网站目录 → **运行目录选 `/public`**。
- 防跨站 open_basedir 应允许整个 `/www/wwwroot/open.52okp.com/` 和 `/tmp/`，不能只允许 public，否则 PHP 无法读取上级 vendor、.env 和 storage。

完整包已带 vendor。不要把运行目录设为项目根目录，数据库密码和私钥都在 public 外。

## 2. 配置与初始化

将 `.env.example` 复制为 `.env`，用宝塔文件编辑器填写：

```dotenv
APP_ENV=production
APP_URL=https://open.52okp.com
APP_KEY=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=okp_account
DB_USERNAME=okp_account
DB_PASSWORD="填写你在宝塔创建的数据库密码"
```

密码包含特殊字符时保持双引号包围，并按 dotenv 规则转义引号和反斜线。不要把真实 .env 发给他人。

进入宝塔终端执行（PHP 安装版本不同请调整 `83`）：

```sh
cd /www/wwwroot/open.52okp.com
/www/server/php/83/bin/php bin/console install
/www/server/php/83/bin/php bin/console admin:create
chown www:www .env
chown -R www:www storage
chmod 600 .env storage/keys/private.pem
/www/server/php/83/bin/php bin/console check
```

管理员邮箱和密码在终端交互输入，无默认管理员密码；密码至少 12 位，含大小写字母和数字。命令行首次指定的管理员邮箱视为管理员自行确认，不发送邮件。以后普通用户注册必须验证邮箱。

若 PHP CLI 禁用了 `shell_exec`，无法隐藏交互密码，可使用临时文件方式（仅终端，密码不放命令参数）：

```sh
umask 077
read -r -p '管理员邮箱: ' ADMIN_EMAIL
export ADMIN_EMAIL
read -r -s -p '管理员密码: ' ADMIN_PASS
printf '\n'
printf '%s' "$ADMIN_PASS" > storage/admin-password.tmp
unset ADMIN_PASS
ADMIN_PASSWORD_FILE="$PWD/storage/admin-password.tmp" /www/server/php/83/bin/php bin/console admin:create
rm -- storage/admin-password.tmp
unset ADMIN_EMAIL
```

`install` 可重复运行，不清空已有数据，不替换已有签名密钥。升级时必须保留 `.env`、`storage/keys` 和数据库。丢失 APP_KEY 将无法解密微信、SMTP、TOTP 配置。

## 3. Nginx 与现有 Java 反向代理

宝塔网站设置 → **反向代理**：关闭这个域名指向 Java / Keycloak 8080 的代理配置，否则请求仍会进入旧服务。

网站设置 → **伪静态**，填入：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

保留宝塔生成的 PHP 配置（如 `include enable-php-83.conf;`）。启用 HTTPS，并保证 public 之外的文件无法被网站访问。`deploy/nginx-rewrite.conf` 提供同一规则。

PHP 不需要启动 Java 守护进程，也不需要添加“Java 项目”。切换反向代理和 PHP 网站后，旧 Java 进程可在宝塔原 Java 项目列表点停止；如果此前用 systemd 或 supervisord 管理，则从原管理方式停止。**不要用 killall java**，以免影响其他项目。PHP 切换不依赖提前删除旧服务。

## 4. EdgeOne：外部 HTTPS、回源 HTTP

APP_URL 固定使用外部 `https://open.52okp.com`。应用用该地址签发 issuer 和回调，并始终使用 Secure Cookie；不会根据用户伪造的 X-Forwarded-Proto 改地址，因此支持现有 HTTPS → HTTP 回源。

- EdgeOne 对此账号域名的所有动态页面/API **绕过缓存**，不得缓存带 Cookie 的登录页、二维码、授权重定向或令牌响应。应用已经发送 no-store，但仍需取消平台的强制缓存规则。
- Nginx 必须按当前 EdgeOne 官方回源 IP 段配置 `set_real_ip_from` 和 `real_ip_header`，只信任真实边缘节点。应用限流使用 REMOTE_ADDR，不直接相信任意 X-Forwarded-For；不配置真实 IP 会把同一边缘节点的用户合并限流。
- 按你实际使用的 EdgeOne 头选择恢复客户端 IP；使用官方当前回源 IP 列表，不复制网上旧 IP 段，不设置 `set_real_ip_from 0.0.0.0/0`。
- CDN/WAF 独立返回的 429 不会被 PHP 修复；若再次出现 429，先看响应来自 EdgeOne、Nginx 还是应用，并核对真实 IP 与对应规则。
- 回源服务器应限制只允许可信边缘来源访问，避免绕过边缘防护。

## 5. 首次配置与验收

1. 访问 `https://open.52okp.com/login?target=/admin`，使用新管理员登录。
2. 后台填微信 AppID、AppSecret，页面 `pages/account-confirm/index`，版本正式版。留空密钥字段不会覆盖旧密钥。
3. 微信公众平台配置服务器出口 IP 白名单（如 API 要求），小程序 request 合法域名包含 `https://open.52okp.com`。确认页已发布且小程序实际 AppID 与后台一致。
4. 后台配置 SMTP 主机、端口、加密方式和授权码；使用自己的邮箱实际完成一次注册和找回密码。
5. 打开登录页扫码，手机看到业务应用名，在小程序确认后网页进入账号中心。手机取消、网页过期、刷新均需测试。
6. 后台新建业务应用，登记准确回调地址，将一次性显示的 client_secret 保存到业务网站服务端。按 INTEGRATION.md 接入。
7. 用一套测试业务应用验收 PKCE 换码、UserInfo、退出，并验证普通用户不能打开后台。

入口：`/login` 登录，`/account` 用户中心，`/admin` 后台。未配置微信时登录页仅显示账号密码。扫码和邮件需真实凭据，离线测试不等于线上微信/SMTP 联调通过。

## 6. 运维与回退

宝塔计划任务每天运行：

```sh
cd /www/wwwroot/open.52okp.com && /www/server/php/83/bin/php bin/console cleanup
```

每次最多删除各表 1 万条过期记录，繁忙站点增加频率。审计记录保留 30 天，已过期票据/令牌延后一天清理。PHP Session 有 30 分钟闲置、8 小时绝对有效期；过期会话文件由 PHP GC 处理。

备份独立 MySQL 数据库、`.env` 和 `storage/keys`，三者一起恢复；使用宝塔数据库备份并单独保存加密密钥文件。普通运行仅 storage 需要可写；启用在线更新后，代码目录也需由更新执行用户写入，具体权限、计划任务和 OPcache 设置见 [在线更新说明](UPDATES.md)。日志 `storage/error.log` 只记录错误编号、异常类别和代码位置，不写密码和令牌。

回退：恢复旧 Nginx 代理配置，再通过旧方式启动 Keycloak。两套数据库独立；PHP 新建正式账号不会自动同步回 Java，正式启用后回退需另行考虑数据。

参考：[宝塔网站目录说明](https://docs.bt.cn/user-guide/site/php/site-config/website-directory/)、[宝塔创建 PHP 网站](https://docs.bt.cn/user-guide/site/php/create-web/)。
