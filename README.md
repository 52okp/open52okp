# 52okp PHP 统一登录中枢

独立 PHP 项目，代码、依赖、配置和运行文件都位于网站项目目录。宝塔选择 **网站 → PHP 项目**，网站目录为本项目，运行目录为 **public**。数据库使用 MySQL 5.7，不依赖 Java、Keycloak、PostgreSQL、Docker、Redis或常驻队列进程。

先阅读 [宝塔部署步骤](docs/BAOTA.md)，业务应用接入见 [OIDC 接入规范](docs/INTEGRATION.md)，实现与测试范围见 [审查与验证](docs/REVIEW.md)。

## 程序在线更新

管理后台新增 `/admin/updates`，按 [在线更新配置](docs/UPDATES.md) 配置项目令牌及宝塔每分钟执行器。后续在后台检查并确认安装，不再手动覆盖服务器代码。

## 已实现

- 邮箱注册、账号密码登录、邮箱验证码找回密码。
- 微信小程序扫码登录与登录后绑定；兼容已发布的 `pages/account-confirm/index` 和 `/realms/52okp/wechat` 接口。
- 用户中心：昵称、首次绑定邮箱、设置/修改密码、TOTP 动态验证码和单次恢复码。
- 中文后台：用户搜索/禁用、应用配置、客户端密钥轮换、微信和 SMTP 加密配置、最近审计记录。
- 授权码 + PKCE S256，RS256 ID Token、JWKS、UserInfo、刷新轮换、自省、撤销及退出确认。
- 每个业务网站主动发起登录后重新验证身份，不自动用中枢 Cookie 跳过登录。

这是一套新的账号中心，不是 Keycloak 的 PHP 包装层。按已确认方案全新初始化，不迁移 Java 测试账号，不提供 Keycloak Admin REST API、SAML、多 Realm、组织/角色授权体系或后台推送登出。业务网站的权限和本地会话由业务网站管理。

## 目录

| 目录 | 用途 |
| --- | --- |
| public/ | 唯一 Web 根目录，入口和静态资源 |
| app/ | 业务服务、控制器与 OAuth 仓储 |
| resources/views/ | PHP 页面模板 |
| database/schema.sql | MySQL 5.7 表结构 |
| bin/console | 命令行初始化、管理员创建、检查、清理 |
| config/、.env | 配置加载与站点本地环境配置 |
| storage/ | 密钥、会话、错误日志（不可公开访问） |
| vendor/、composer.lock | 随发布包提供的生产依赖与锁定版本 |
| tests/ | 隔离测试数据库上的回归测试 |

## 环境

PHP 8.2 或更新的兼容版本，部署建议 PHP 8.3；扩展 `pdo_mysql`、`openssl`、`sodium`、`mbstring`、`curl`、`zip`，并启用 PHP 标准 Session、JSON、ctype、filter 等基础扩展。已实际验证 PHP 8.2.12 + MySQL 5.7.44；更换 PHP 版本后先执行 `bin/console check`。生产使用 HTTPS。

源码安装可执行 `composer install --no-dev --prefer-dist --optimize-autoloader`。完整发布包已带 vendor，无需在宝塔上安装 Composer 或编译项目。

## 本地测试

仅在 `APP_ENV=development` 且数据库名以 `_test` 结尾的隔离环境运行：

```sh
php tests/run.php
php -S 127.0.0.1:8808 -t public public/router.php
# 另一个终端，Python 3：
python tests/integration_http.py
```

测试保留生成的测试记录，不会清空已有表。并发测试使用两个独立 PHP 进程连接同一 MySQL，不能以生产数据库运行。Windows XAMPP 若未启用 sodium，可临时用 `php -d extension=sodium` 执行；生产应在 php.ini 启用扩展。
