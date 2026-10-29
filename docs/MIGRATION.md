# 人事系统迁移状态

当前 Laravel 项目已接入独立的辅助库和只读旧业务库连接。旧库是 MySQL 5.5，主要使用 GBK 表；应用连接以 `utf8` 会话读取，MySQL 负责转码。`php artisan migrate` 只作用于默认的 `rsxt` 连接，不能将默认连接设为 `legacy`。

## 已实现

- 学校 CAS 协议包已接入；票据校验成功后按旧库 `user.username` 精确匹配启用且有模块权限的账号，并建立会话。支持标准及 JSONP 单点注销。公网地址须在部署环境配置。
- 旧库 `userroles`、`treeroles` 菜单授权；个人档案、年度考核、请假记录只读页面。
- 个人档案按基本资料、工作与岗位、学历学位、资格与聘任、联系与其他五类分页；按旧库代码表显示部门、籍贯、民族、岗位等名称。
- `php artisan personnel:inventory` 生成不含个人信息的菜单与角色覆盖清单。
- [功能核对清单](FEATURE_MATRIX.md) 标记已交付与待验收的功能域。

## 本机预览

门户 CAS 地址尚未提供时，`/login` 显示配置说明，不再返回原始 503。仅在 `APP_ENV=local`、请求来自本机回环地址，并显式设置 `PERSONNEL_LOCAL_PREVIEW=true` 与 `PERSONNEL_PREVIEW_USER_ID` 时，页面提供五分钟有效的签名只读预览入口。当前工作区的本地 `.env` 已启用员工账号预览；部署环境须保持 `PERSONNEL_LOCAL_PREVIEW=false`。预览不会给原业务库增加写入权限。

## 切换前必须完成

- 在部署环境设置 `CAS_SERVICE_URL=https://rsxt.zufedfc.edu.cn/sso/callback`、`CAS_POST_LOGOUT_URL=https://rsxt.zufedfc.edu.cn/`；在 CAS 平台登记应用首页 `https://rsxt.zufedfc.edu.cn/`、精确匹配及登录回调 `https://rsxt.zufedfc.edu.cn/sso/callback`、单点注销 `https://rsxt.zufedfc.edu.cn/auth/cas/slo`，验证登录、退出和单点注销。CAS 服务端默认使用 `https://cas.paas.zufedfc.edu.cn/cas`。
- 从当前运行系统核实其他角色菜单、审批和写入规则。2017 年 `ROOT.zip` 与现网页面不同，不能作为现网写入规则的唯一依据。
- 已在 10.1.12.162 创建隔离的 `uchr_stage`，复制 322 张基础表和 7 个视图。`php artisan personnel:verify-stage` 已逐表、逐视图核对准确记录数，结果均一致。副本是开发测试数据，生产库仍在变化，写入流程测试前需重新核对并按需重建副本。旧库视图的原始 definer 不允许当前账号查询，核对命令以视图定义在源库直接计算，并在测试库使用兼容的 SQL 模式执行。随后完成增删改、审批、撤销和报表对照。当前代码没有向原业务库写入的路由。
- 导出旧服务器上的历史附件，在新应用存储中校验数量、大小、摘要和记录对应关系。
- 全部在用角色完成流程验收后，停用旧入口写入，再启用新系统的生产写入。

本地 `.env` 不应提交到 Git。部署时以 `.env.example` 为清单，在运行环境设置辅助库、旧库和 CAS 参数。
