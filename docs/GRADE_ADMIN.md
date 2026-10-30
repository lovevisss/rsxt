# 所聘岗位等级管理

后台管理员使用现有 `personnel_admins` 授权。旧库菜单 387 对应 `uchr.h17`：本部等级、聘任时间、结束时间分别为 `h1704/h1705/h1710`；东方为 `h1706/h1707/h1711`。同一记录可同时填写两地，一组等级必须同时填写聘任时间。姓名和部门从有效 `h50` 档案带入，等级代码从 `d307` 选择；新记录用数据库 ID 生成序号，旧记录的序号保留。

导入只接受本站 `.xlsx` 模板，至多 500 行、10 MB。模板第二页列出等级代码。先预览逐行错误，错误须全部修正后才能整批提交；同工号且两地等级与起止日期完全相同的行跳过。导出采用列表筛选条件。

写入 `h17` 时，同一事务重算 `h50.h5071`（本部）与 `h50.h5072`（东方）。仅未删除、有等级与聘任时间、聘任时间不晚于今天、结束时间为空或晚于今天的记录参与；先按各地聘任时间、再按 ID 取最新。首次修改前的等级保存在 `rsxt.grade_profile_baselines`，无有效记录时恢复原值。已有缺少聘任时间的旧记录只展示，不参与当前等级计算。每日 00:05（北京时间）定时重算已由本功能接管的人员，使未来聘任生效及结束日期到期自动反映到档案；本人查看档案时也会刷新其已接管的等级。

首次部署先运行 `php artisan migrate --database=migration_auxiliary --force`，再重运行 `php artisan personnel:provision-position-writer --execute`，为现有专用写入账号添加 `h17` 和两个 `h50` 等级字段权限。Laravel 定时任务已注册为 `personnel:refresh-grade-levels`；运行环境须每分钟执行 `php artisan schedule:run`，或在北京时间 00:05 每天直接执行 `php artisan personnel:refresh-grade-levels`。

本机 Windows 任务计划程序需要管理员权限。以提升权限的 PowerShell 在当前主机注册每日任务：

```powershell
$action = New-ScheduledTaskAction -Execute 'C:\Users\Administrator\.config\herd\bin\php84\php.exe' -Argument 'F:\herd\rsxt\artisan personnel:refresh-grade-levels' -WorkingDirectory 'F:\herd\rsxt'
$trigger = New-ScheduledTaskTrigger -Daily -At '00:05'
Register-ScheduledTask -TaskName 'RsxtGradeLevels' -Action $action -Trigger $trigger -User 'SYSTEM' -RunLevel Highest -Description '每日刷新当前所聘岗位等级'
```
