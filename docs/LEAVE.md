# 请假与销假迁入

## 数据与权限

- `a34` 是请假申请，`a35` 是多条请假明细，`a38` 是关联 `a35.Id` 的销假申请。
- “我的申请与进度”按 `a34.CreatorId` 查询；“我的请假明细”按 `a35.a3511`（请假人的旧系统用户 ID）查询。已提交的历史记录即使 `a34.yndelete=1` 仍展示。
- 员工权限沿用菜单 408、409、411、426；审批待办和已办沿用 412、413。操作审批要求菜单 412、`gz_auditors.auditorId` 与当前审批人一致。
- 新申请从 `modules`、`workflowmodule_users`、`gz_dpleaders` 选择流程。特殊人员流程优先于通用流程；无法唯一确定部门领导时禁止提交。

## 部署

1. 先运行应用自身的迁移，创建 `leave_events` 与 `leave_attachments`。旧库不得运行 Laravel 迁移。
2. 隔离验收时设 `LEAVE_WRITE_TARGET=stage`；该目标必须与 `legacy` 的主机或库名不同。运行员工、代办人、各级审批人和销假场景，核对旧页面与新页面的状态、附件和审批结果。
3. 上传附件前配置 `LEAVE_PUBLIC_ATTACHMENT_URL`，例如新站点可供旧系统访问的 `https://example.edu.cn/storage`，并建立 Laravel 的 `public/storage` 链接。此地址用于旧系统显示新附件，需由部署方确认访问策略。未配置时仍可申请，但不能上传新附件。
4. 正式切换前，为 `LEGACY_LEAVE_DB_USERNAME` 建立独立数据库账号，仅授予 `a34`、`a35`、`a38`、`gz_auditors` 的 `SELECT, INSERT, UPDATE, DELETE`，以及流程配置、用户、部门、字典等必要表的 `SELECT`。不可复用 `LEGACY_DB_USERNAME`。
5. 完成旧系统对照验收后，设置 `LEAVE_WRITE_TARGET=production` 和 `LEAVE_PRODUCTION_WRITES_ENABLED=true`。两者缺一则不可写；默认均关闭。原 `legacy` 连接继续由只读守卫保护。

## 验收重点

- 逐条核对本人、代办、被代办人的查询隔离。历史归档记录 `a34.Id=2674/3963` 及其 `a35` 明细应可见。
- 对照通用和两级请假流程的审批名单及顺序；示例 `a34.Id=4890` 为通用流程，`4863` 为两级流程。
- 在隔离库检查提交、撤销、退回重提、逐级通过、销假关联和附件地址；同时比较旧系统产生的统计与归档副作用。未达到一致前保持正式写入关闭。
