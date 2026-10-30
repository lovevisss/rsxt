# 聘任岗位变动与后台管理员

后台管理员名单保存在 `rsxt.personnel_admins`，以门户工号识别。首次迁移写入 `20150301`、`20201003`。管理员可继续添加有效账号、移除账号，但至少保留一人；这项权限不授予旧系统菜单和其他审核权限。

岗位变动来自 `uchr.h06`，`h0615=DF` 为东方、`BB` 为本部。新建和编辑时使用 `h50` 的有效职工编号自动带出姓名与部门，岗位代码来自 `d306`。删除只修改 `yndelete`。以同一工号、类型下最新的现聘任时间及记录 ID 计算当前岗位；东方同步 `h5019/h5021`，本部同步 `h5094/h5073`。首次修改前的档案值保存在 `rsxt.position_profile_baselines`，删至无有效记录时恢复。

导入仅接受本站下载的 `.xlsx` 模板，每次至多 500 行、10 MB。先预览逐行错误；有错误时不提交。同工号、类型、现聘任时间的重复记录跳过，其余行一次提交。模板第二页列出岗位代码。导出使用当前列表筛选条件。

生产写入使用 `legacy_position_writer` 专用账号。应用已在 183 时，先用迁移管理员执行 `php artisan migrate --database=migration_auxiliary --force`，再执行 `php artisan personnel:provision-position-writer --execute` 创建账号并写入本机 `.env`。该账号只可读写 `uchr.h06`，读取 `uchr.h50` 并更新其四个岗位字段及 `updateTime`；运行账号继续不具备业务库写权限。隔离测试可设置 `POSITION_WRITE_TARGET=stage`，目标连接须与正式库不同。
