# 程序在线更新（app.52okp.com）

本项目已按 https://app.52okp.com/api/spec 接入统一更新 API v1。实读线上服务实现版本为 v1.4.5。项目标识 **open52okp**，接入版程序版本 **1.0.1**；这两个版本号属于不同系统。

## 第一次配置

1. 如果服务器仍是之前不带更新器的版本，需要把这次 `52okp-account-php-1.0.1-online-update.zip` 接入包安装一次。解压代码到原 PHP 项目目录，运行目录仍为 public。包不含真实 .env、密钥、会话或数据库；不要删除旧 .env 和 storage，不要重新创建管理员。以后更新在后台完成。
2. 在更新中心的「项目管理」注册 `open52okp`，绑定这套程序的真实 GitHub owner/repo，生成这个项目自己的只读令牌。不要复用导航站或其他项目的令牌。私有源码需要私有 GitHub 仓库。
3. 在本系统登录管理员 → **程序更新**（`/admin/updates`），填写令牌并保存。令牌加密保存在本系统数据库中，页面不会回显；不需要粘贴到聊天。
4. PHP-FPM 与 PHP CLI 都启用 zip、curl、openssl、sodium、pdo_mysql、mbstring。仅 CLI 需允许 proc_open，用于独立进程健康检查；不要求 Web PHP 开放执行命令。
5. 更新执行器和 PHP-FPM 使用同一网站用户 www。项目代码与 storage 必须可由该用户读写，不使用 777。若当前代码属于 root，在确认路径后可执行 `chown -R www:www /www/wwwroot/open.52okp.com`；不对其他网站目录操作。
6. **为本站关闭 OPcache**，避免替换代码后混用缓存的旧 PHP 文件。在本站 PHP 配置（可用 public/.user.ini，保留原 open_basedir 设置）加入 `opcache.enable=0`，等待生效或重载对应 PHP-FPM。程序会在安装前检测仍生效的 OPcache 并拒绝安装。
7. 宝塔 → 计划任务 → Shell 脚本 → 每分钟执行一次：

```sh
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/open.52okp.com/bin/update-worker.php
```

若宝塔已允许选择执行用户 www，可直接填 `/www/server/php/83/bin/php /www/wwwroot/open.52okp.com/bin/update-worker.php`。替换实际 PHP 版本路径和网站目录；不要用 root 长期执行，以免与 PHP-FPM 创建的任务文件权限不一致。

第一次等执行器运行后，后台显示「最近已运行」。没有待安装任务时，执行器仅更新心跳，不查询或安装远端版本。该计划任务同时负责被中断后的恢复，必须保留。

## 后续管理员操作

1. 打开「程序更新」→「检查更新」，显示最新正式版、说明、实际包大小和起始版本要求。
2. 阅读维护提示，确认安装。需要最近 5 分钟内登录验证；过期则重新登录。
3. 页面显示排队、下载字节、校验、备份、安装、健康检查和最终状态。关闭浏览器不会中断任务，通常最多等待一个计划任务周期开始。
4. 文件切换时登录服务短暂返回 503，当前请求先结束，再替换代码。健康检查通过才结束维护。失败恢复旧代码；进程被杀或主机重启后由下一次计划任务恢复。

更新中心暂无正式版返回 404，错误/撤销令牌返回 401，均明确显示错误，不伪装成「已是最新版」。本次只读实测无令牌请求返回 401；没有本项目正式令牌，尚未完成生产鉴权下载联调。

## 开发者发布新版

开发和测试完成后，使用锁定依赖构建，生成更新包和外部清单：

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
python tools/build_update.py --version 1.0.2 --from 1.0.1 --notes release-notes.txt --output ../dist/open52okp/1.0.2
```

构建器自动生成带内容指纹的 CSS/JS 文件，先写包内版本，再生成 ZIP，最后计算外部清单大小和 SHA-256。输出是 `open52okp-update.zip` 和 `update-manifest.json`；既有输出拒绝覆盖。

在绑定的 GitHub 仓库创建 **v1.0.2 正式 Release**，上传这两份资产。再到更新中心拉取或上传相同文件，完成 GitHub 摘要校验，管理员点击发布。未发布草稿不能供客户端安装。发布后才会在本系统检查到新版。

初始交付的 1.0.1 更新资产用于建立发布基线（from=1.0.0）；已经装好 1.0.1 接入版的网站不会再次安装同版。内部 1.0.2 测试包是隔离测试数据，不是正式新版，不要发布它。

源站只连接 app.52okp.com，不自行访问 GitHub。清单与 ZIP 绑定同一发布 ID，地址必须 HTTPS 同源并精确匹配预期项目路径，不跟随重定向。EdgeOne 必须透传 Authorization 并禁止缓存私有 API；更新中心对外 HTTPS 不等于其公网 HTTP 回源也被加密。

## 数据保护与支持范围

- 不覆盖 `.env`、storage、用户上传、账号数据库、私钥、SMTP/微信配置和更新令牌；配置仍保存在原数据库。
- 只更新允许的代码目录和固定入口，逐文件核对 SHA-256，拒绝穿越、链接、重复路径、异常压缩比、超大项、错误项目和不兼容版本。
- 严格匹配 from，不自动跳过中间版本，不降级。
- 当前 installer=1 只接受 `database_schema=1`、`migrations=[]`，且 schema.sql 摘要必须与已安装版本相同。**不执行任何数据库迁移，也不声称已备份/回滚数据库**。未来改表版本需要先升级安装策略，当前安装器会拒绝该包。
- 备份、staging、下载包和恢复日志保存在 public 之外的 `storage/updates/<任务ID>/`。旧备份不自动删除，定期按磁盘情况归档；至少保留上一个成功版本。
- 维护锁和文件替换使用本机文件系统，面向宝塔单节点部署，不支持多台应用节点共享目录/NFS 的集群滚动升级。
- SHA-256 是完整性校验，不是数字签名。发布账户、项目令牌和更新服务必须受保护。

## 故障处理

网页一直排队：检查计划任务、CLI PHP 路径/扩展、www 权限。网页维护中：先等待下一次计划任务；不要再上传解压或手工删除维护标记。

查看状态或手动执行一次恢复（均以 www 身份）：

```sh
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/open.52okp.com/bin/update-worker.php --status
runuser -u www -- /www/server/php/83/bin/php /www/wwwroot/open.52okp.com/bin/update-worker.php
```

`recovery_required` 表示文件恢复或旧版健康检查尚未成功，系统会保留维护状态，修复磁盘/权限/数据库连接后再运行。运行时使用更新前留存的独立恢复引擎，即使应用 bootstrap 损坏也能恢复代码。不要删除 recovery、journal.json 或 backup。

## 已测与未测

隔离副本验证：协议字段和固定来源、真实独立进程健康检查成功安装、配置/密钥/数据保留、健康失败回滚、下载失败不改代码、模拟进程终止后恢复、危险 ZIP 和摘要/版本/环境错误拒绝。HTTP 验证新增后台权限、CSRF、无效令牌及缺失检查结果不能安装，并回归原登录功能。

本次没有注册生产更新中心项目、生成生产令牌、创建 GitHub Release 或发布线上版本；未改动服务器程序。配置项目和令牌、发布正式版本后，才具备生产在线更新条件。
