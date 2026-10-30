# 人员部门变动管理

后台管理员沿用 `personnel_admins` 名单。旧库菜单 295 的 `uchr.h54` 保存姓名、工号、原部门代码、目标部门代码、变动日期、备注二和原因。后台可按原部门、姓名或工号筛选，分页查看、新增、编辑、软删除和导出。现有旧记录可查看和修改；部署本功能不会批量改写这 171 条旧记录或档案。

新增、编辑和导入要求有效 `h50` 档案及启用的 `user` 账号。原部门默认取档案 `h5003`，允许管理员为历史记录校正；目标部门须为启用且未删除的 `department.objname`。Excel 仅接受本站 `.xlsx` 模板，每次最多 500 行、10 MB。模板第二页列部门代码。先预览逐行错误，错误行阻止整批提交；同工号、目标部门和变动日期的重复行跳过。姓名只从档案读取，不由导入覆盖。

首次修改某人前，将 `h50.h5003` 与 `user.departmentId` 分别保存到 `rsxt.department_profile_baselines`。业务写入在 183 同一个数据库事务中维护 `uchr.h54`、`uchr.h50`、`uchr.user`、基线和 `rsxt.department_change_events` 日志。未删除且日期不晚于北京时间今天的记录按日期、ID 取最新，目标部门代码映射到 `department.Id` 后同步档案和门户账号。无有效记录时分别恢复基线。未来日期的记录暂不影响当前部门。每次管理操作及定时任务只重算已被本功能接管的人员；已有旧记录在编辑或同一人员发生新操作前不参与批量刷新。

首次部署运行：

```powershell
php artisan migrate --database=migration_auxiliary --force
php artisan personnel:provision-position-writer --execute
```

以上迁移在 183 的 `rsxt` 建基线与日志表，并授予现有专用账号 `h54`、`h50.h5003`、`user.departmentId` 和这两张新表所需的最小权限。Laravel 每日 00:10（北京时间）注册 `personnel:refresh-departments`，本人查看档案时也会刷新其已接管的部门。主机需每分钟执行 `php artisan schedule:run`，或每天 00:10 直接执行该命令。当前 Windows 主机拒绝创建系统计划任务，需由主机管理员以提升权限配置；未配置前，后台变动操作及本人查看档案仍会触发相应刷新。
