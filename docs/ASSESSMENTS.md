# 个人年度考核

员工入口沿用菜单 379，只读取本人 `h20` 记录。部门审核入口沿用菜单 380，同时要求 `gz_dpleaders` 中存在该审核人与考核所在部门的有效配置。新系统不创建年度记录，仍由管理端统一建档。部门通过后，`h2026`、`h2031` 等后续状态由旧系统继续处理。

## 写入配置

`ASSESSMENT_WRITE_TARGET` 默认是 `disabled`。设置为 `stage` 时年度考核页面读写 `LEGACY_STAGE_DATABASE`，应用会拒绝与生产旧库同主机同库名的目标。隔离库须先重新同步并核对记录；流程验收应使用针对隔离库的测试账号和数据。旧菜单授权仍由原只读连接核验。

正式切换时，只有在员工与部门负责人验收、停用旧入口写入并核对后续审核兼容后，才设置 `ASSESSMENT_WRITE_TARGET=production` 和 `ASSESSMENT_PRODUCTION_WRITES_ENABLED=true`。须提供独立的 `LEGACY_ASSESSMENT_DB_USERNAME` 与 `LEGACY_ASSESSMENT_DB_PASSWORD`；该账号只需 `h20` 的更新权限。普通 `legacy` 连接仍只读。修改环境配置后执行 `php artisan config:clear`。在默认 `rsxt` 数据库运行 `php artisan migrate` 建立操作记录表，不得对旧库运行迁移。

## 状态与导出

保存总结只更新 `Memo`；员工提交置 `h2025=y`，部门处理前撤销恢复 `n`。部门通过写入 `h2013`（旧字典 `d519` 的等次）和 `h2030=y`；退回清除部门结果，恢复可编辑状态。操作人与时间、退回原因先以待处理状态保存在 `assessment_events`，旧库写入成功后标记完成；异常中断留下待核对记录。所有写入检查记录版本与旧状态，避免覆盖并发修改。

Word 导出为真正的 `.docx`，按提供的旧版考核表样本组织 A4 页面及个人信息、总结、部门意见、审批意见、个人确认栏目。历史 `Memo` 的 HTML 经白名单清理后显示和导出。
