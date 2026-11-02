# 管理员操作记录与回滚

后台 `/personnel/admin/operations` 统一展示岗位变动、岗位等级、部门变动及管理员名单的写入动作。新动作在 `rsxt.admin_operations` 保存操作人、时间、业务、动作、受影响记录的修改前后快照；一次导入是一项动作，重复跳过的行不计入快照。旧的 `position_change_events`、`grade_change_events`、`department_change_events` 在迁移时导入为只读历史，因缺少完整旧值不能回滚。定时刷新日志同样只读。

回滚不设到期时间。服务端锁定操作和目标记录，逐项核对当前记录仍与该动作的修改后快照一致；不一致或恢复后会形成重复记录时返回冲突，整批导入不会部分回滚。恢复历史记录后，按原业务规则重算 `h50` 当前岗位、等级或部门，并在部门变动时同步 `user.departmentId`。管理员名单回滚仍须保留至少一名管理员。成功回滚会标记原动作，并写入关联的回滚审计记录；回滚记录本身不可再次回滚。

首次部署先运行 `php artisan migrate --database=migration_auxiliary --force`，再运行 `php artisan personnel:provision-position-writer --execute`。专用账号只获得新审计表及原值基线表所需授权；生产环境的人事记录、原值基线、当前档案和审计记录由同一 183 数据库事务提交。既有旧业务记录不迁移或改写。
