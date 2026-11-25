# 禅道规格关系库

在禅道 22.7 的默认 `zt_` 表前缀和 MariaDB 上运行。受信本地工程服务通过 `php tools/spec-trace/cli.php <动作>` 调用，成功向标准输出写 JSON，失败返回非零并向标准错误写 JSON。Git 保存原件和规格正文。扩展表通过禅道产品 ID 和 Epic 稳定 ID 隔离冻结记录。

## 安装与权限

`install` 在指定数据库内创建或补建扩展表。为安装进程设置 `TRACE_ROLE=installer`、`TRACE_DSN`、`TRACE_DB_USER` 和 `TRACE_DB_PASSWORD`。DSN 使用 PDO MySQL 格式并指定 `charset=utf8mb4`。日常服务可按角色单独配置数据库账号。读取需要原生产品、用户及扩展表的 SELECT 权限，写入需要在相应扩展表上执行 INSERT，以及获取锁和处理幂等所需的 UPDATE 权限。

日常动作通过部署方保护的本地服务环境绑定 `TRACE_ACTOR`（有效的禅道账号）、`TRACE_PRODUCT`（已授权产品 ID）和 `TRACE_ROLE`。服务调用方负责从已认证主体取得账号、角色和对应产品授权。能自行控制该进程环境或 DB 凭据的操作者属于受信服务边界。该 CLI 不提供面向浏览器的认证入口。

## 冻结与查询

- `import` 由 author 使用。请求包含 `product, epic, expected_revision, graph`。首版预期修订为 0。相同作者以相同基线重试相同图，返回首次冻结回执。其他过期写入报告冲突。
- `graph` 包含固定 Git 提交 `baseline`、`features`、`sources`、`items` 三个数组。feature 具有 `id`。source 具有 `id, revision, path, blob`。item 具有 `id, revision, summary, sources, actual_consumers`。item.sources 保存版本化定位，包含 `id, revision, path, blob` 及上层检查器支持的行范围、引句等精确定位字段。消费者边具有 `feature_id, obligation_id`。
- 同一 Epic 内同一来源或需求项身份及版本绑定固定内容。改变义务或关联需升需求项版本。图保留附加业务字段和重复输入，SQL 的关系计数按唯一边统计。悬空定位和未知消费者随请求入库，后续审计可核对缺口。
- `snapshot` 可由 reader、author、reviewer 使用。输入 `product, epic` 及可选 `revision`，输出 `product,epic,revision,basis,author,graph,statistics,decisions`。省略修订时返回当前头。历史查询中决定行的 `current` 只表明该行在所查询的修订内是否仍有效。活动放行请重新核对当前头和准确审阅目标。
- `basis` 为完整 graph 的 SHA-256。使用 UTF-8、递归键排序、紧凑 JSON，保留 Unicode 和斜线。值支持对象、数组、字符串、整型、布尔、null。

## 独审决定

`decision` 仅由 reviewer 使用。请求必须提供 `product, epic, revision, basis, stage, scope, target_revision, decision, evidence`。决定为 `accepted` 或 `changes_requested`，核对请求的准确冻结修订和审阅主体。相同请求幂等。后续决定携带该阶段及范围的最近 `prior_decision`。追加新决定时使用当前冻结头。第一道范围为 Epic ID，目标为冻结 Git 提交。第二道范围为功能 ID，目标为检查器提供的准确规格目标摘要，且须提供仍有效的第一道 `epic_decision`。

二审前，作者的受控进程以 `submit` 提交 `product,epic,revision,basis,scope,target_revision,epic_decision`。关系库记录真实禅道账号，将提交者与准确候选及第一道决定绑定。同一目标不能改由其他人认领。第二道决定逐项核对登记候选、真实提交者和报告中的作者。

受控服务生成并保护机械报告文件，通过环境 `TRACE_MECHANICAL_REPORT` 传递。接受决定要求报告状态 passed，逐项比对阶段、范围、产品、Epic、修订、依据、目标和上游决定。第一道核对独立期望 evidence 摘要，第二道另查 spec_author 与审阅者身份。报告和审阅证据随决定持久保存。退回决定可直接附真实发现。第一道被新决定替换时，绑定旧第一道的第二道决定保留历史并失效。此入口记录工程独审，必要人类批准仍走项目既定渠道。

## 复跑数据库验证

在具备 Docker 的主机运行 `bash tools/spec-trace/tests/run.sh`。脚本新建无网络的官方 22.7 容器，初始化官方样例数据库，经过真实 CLI 测试并移除该容器。测试内模拟的审阅和机械报告只验证入库门槛。
