# 个人档案更正申请

## 数据与权限

- 本人申请需要旧系统菜单 303 和 333；人力资源部审核需要旧库角色 15。审核人不能审核自己的申请。
- 本机签名预览会话仅可查看，提交、撤回、通过、驳回和重试接口均拒绝写入。
- 可更正字段由 `config/profile_corrections.php` 中的 40 项白名单限定。职工编号、部门、岗位、工龄及计算字段不能提交。一次申请可改多个字段，整单通过或驳回。
- 申请、变更项、私有证明材料索引及追加式操作记录在 `rsxt`。旧库 `h61` 不写入。审核通过时仅对旧库 `h50` 的白名单字段写回；姓名变更再同步匹配的 `user.truename`。
- 档案页和 PDF 从旧库现值读取，待审申请不改变展示。证明材料经本人或角色 15 鉴权下载，保存在 Laravel `local` 私有磁盘。保管期限待学校确认。

## 配置与启用

运行 `php artisan migrate` 创建四张 `rsxt` 表。`.env.example` 提供配置项，不包含凭据。

1. 默认 `PROFILE_CORRECTION_WRITE_TARGET=disabled`，通过按钮不可用；可提交、撤回和驳回。
2. 隔离验收时设置 `PROFILE_CORRECTION_WRITE_TARGET=stage`，连接为 `legacy_stage_writer`，目标应是辅助数据库服务器上的 `uchr_stage`。应用会拒绝与 `legacy` 同主机且同库名的目标。
3. 正式切换只有在相关角色完成流程验收并停用旧系统写入后才能设置 `PROFILE_CORRECTION_WRITE_TARGET=production`、`PROFILE_CORRECTION_PRODUCTION_WRITES_ENABLED=true`，且须提供独立的 `LEGACY_PROFILE_DB_USERNAME` 和 `LEGACY_PROFILE_DB_PASSWORD`。这个账号只应有 `h50`、`user` 所需的最小更新权限；普通 `legacy` 连接继续只读。
4. 修改 `.env` 后执行 `php artisan config:clear`。应用不得对 `uchr` 运行迁移。

## 写回与故障处理

提交时保存原始值。审核通过时锁定对应的 `h50`，若任一待改字段已改变，整单标为“数据冲突”，不覆盖现值。183 副本的 `h50`、`user` 均为 InnoDB，启用 `PROFILE_CORRECTION_TRANSACTIONAL_IDENTITY` 后姓名与匹配账号在同一事务中更新。原 184 和 162 的旧测试副本 `user` 仍为 MyISAM，使用档案提交后单独同步姓名的兼容路径；失败保持可重试状态。连续写入中的申请 5 分钟后可由审核人重试。

183 的一次性数据复制、私有文件和运行连接说明见 [数据库复制说明](DATABASE_COPY_183.md)。旧系统仍在 184 运行，后续变化不自动同步。

## 验收要点

- 本人申请、证明材料格式与数量、同一职工一张待审单、撤回、整单驳回及必填原因。
- 员工与人力资源部各自的详情、附件权限；无权限用户和其他职工不能访问。
- 隔离库中修改前后的 `h50`、`user.truename`、档案页与 PDF 值；冲突时不覆盖，写入失败后可重试。
- 正式写入开关保持关闭，直到既定统一切换门槛满足。

自动测试使用独立 SQLite 连接；另已在真实 `uchr_stage` 上用外层事务执行写入服务并回滚，确认 `h50` 更新后恢复原值。该检查不代替员工及人力资源部的页面验收。
