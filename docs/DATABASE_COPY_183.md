# 183 一次性数据库与文件复制

此次复制的来源是 `10.1.12.184/uchr` 和 `10.1.12.162/rsxt`，目标为 `10.1.12.183/uchr`、`10.1.12.183/rsxt`。162 的 `uchr_stage` 保留为独立测试连接。旧 JSP 仍使用 184；本项目切换后只向 183 写业务数据。没有自动同步或双向回写。

## 命令与记录

迁移账号通过 `.env` 中的 `COPY_SOURCE_*`、`COPY_TARGET_*` 配置，不使用应用运行账号进行建表。凭据不提交 Git。以下命令默认仅检查，带 `--execute` 才复制或创建账号：

```powershell
php -d memory_limit=512M artisan personnel:copy-instance
php -d memory_limit=512M artisan personnel:copy-instance --execute
# 中断恢复只处理本次清单拥有的目标表；已完成表须先通过哈希校验
php -d memory_limit=512M artisan personnel:copy-instance --execute --resume
php -d memory_limit=512M artisan personnel:copy-instance --verify

# 只向 183/rsxt 应用新增的 Laravel 迁移
php artisan migrate --database=migration_auxiliary --force
php artisan personnel:activate-copy --execute

php -d memory_limit=512M artisan personnel:import-files
php -d memory_limit=512M artisan personnel:import-files --execute
php -d memory_limit=512M artisan personnel:import-files --execute --retry-missing
php artisan personnel:import-files --verify

# 只允许在应用尚未切换到 183 时运行；测试后恢复业务数据，回滚测试留痕
php -d memory_limit=512M artisan personnel:verify-copy-writes --execute
php artisan personnel:activate-copy --execute --activate
```

`storage/app/private/migration/instance-copy.json` 保存来源版本、快照时间、逐表行数、排序后的行哈希及每 1,000 行哈希块、视图行数、MyISAM 复制后变化。`file-report.json`、`file-verification.json` 和 `write-verification.json` 保存文件与真实写入验证结果。上述目录与私有文件均被 Git 忽略；其中 `pre-183.env` 和 `runtime-credentials.json` 含凭据，只能作为本机私有运维文件保管。

新库启用业务写入后，不应将 `--resume` 或快照校验当作自动同步工具。校验发现目标值已变化时会拒绝覆盖。已知新增文件映射迁移记录不计入原始 `migrations` 行哈希，但其他原始表的数据变化仍会导致校验失败。

## 数据处理

- 完整复制 322 张旧业务表及 7 个视图，并复制 `rsxt` 的全部表结构与持久数据。新 `rsxt` 的会话、缓存、临时队列、失败队列和密码重置令牌清空，用户重新登录。
- 保留原字段、主键和编码；对旧 InnoDB 行格式作目标兼容处理，视图使用 `SQL SECURITY INVOKER`。只把 183 的活跃 `user` 表改为 InnoDB，开启 `PROFILE_CORRECTION_TRANSACTIONAL_IDENTITY`，使档案姓名与账号姓名在同一事务中更新。162 的旧测试副本继续使用原 MyISAM 兼容路径。
- 旧来源不能停写。InnoDB 使用一致性事务读取，MyISAM 另行复查。复制记录是一个时间区间，不能声称所有表严格属于同一瞬间；后续差异由人工迁移。
- 文件扫描全部文本列中的旧上传引用，并兼容旧域名、内网 IP、WebVPN 别名和日期子目录。下载走 184 内网地址，保留原引用作为映射键；文件按 SHA-256 去重存入 `storage/app/private/legacy-files`。
- 无效旧字段、旧 SQL 日志中无法解析的地址、来源已失效或拒绝下载的文件会单独记录。照片、证书和请假附件接口先鉴权，再读取私有副本并校验内容哈希；183 启用后关闭旧服务器 HTTP 回退。新请假附件也存私有磁盘。

## 运行连接与写入权限

`mysql` 连接 183 的 `rsxt`，`legacy` 只读连接 183 的 `uchr`。运行账号 `rsxt_app183` 只有 Laravel 库的增删改查权限；`rsxt_read183` 只有业务库查询权限。更正、考核和请假各用独立账号，只授权所需表及列的写入，不向运行账号授予建库、建表或全局管理权限。未来数据库迁移仍通过 `migration_auxiliary` 管理连接执行。

激活命令在写入验证和私有文件校验通过后修改当前 `.env`，将三个已实现业务的目标设为 `production` 并开启对应开关。这里的目标是 183 的新副本。本机签名预览继续只读；正常认证会话按现有菜单、角色和审批规则写入。

## 部署与回退

Git 只发布代码。部署时另行传输 `storage/app/private/legacy-files`、申请证明材料和新请假附件，保留新 `rsxt` 中的映射，并在部署主机再次执行文件校验。部署环境使用 183 的运行凭据；不要发布本机迁移管理员配置、备份环境或凭据 JSON。

如果新应用启用后需要临时退出，先关闭三个业务写开关并保存 183 的新增记录，再调整连接。184 和 162 保留的原数据可供核对，但不含 183 启用后的新业务；直接回切到原库会丢失这部分可见结果。后续手工补迁应先比较主键、版本和新旧值，禁止整库覆盖已有新业务的 183。
