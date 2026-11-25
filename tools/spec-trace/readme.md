# 禅道规格关系库

在禅道 22.7 的默认 `zt_` 表前缀和 MariaDB 上运行。受信本地工程服务通过 `php tools/spec-trace/cli.php <动作>` 调用，成功向标准输出写 JSON，失败返回非零并向标准错误写 JSON。Git 保存原件和规格正文。扩展表通过禅道产品 ID 和 Epic 稳定 ID 隔离冻结记录。

## 安装与权限

`install` 在指定数据库内创建或补建扩展表。为安装进程设置 `TRACE_ROLE=installer`、`TRACE_DSN`、`TRACE_DB_USER` 和 `TRACE_DB_PASSWORD`。DSN 使用 PDO MySQL 格式并指定 `charset=utf8mb4`。日常服务可按角色单独配置数据库账号。读取需要原生产品、用户及扩展表的 SELECT 权限，写入需要在相应扩展表上执行 INSERT，以及获取锁和处理幂等所需的 UPDATE 权限。

全新禅道安装会初始化追溯表。已有 22.7 实例第一次从分支启动前用上述 `install` 补建表，后续启动可安全重跑。首次初始化只为禅道研发视图的默认管理员角色组登记 `产品 → 浏览产品 → 需求追溯` 权限。公司管理员沿用禅道原生权限，其他组由管理员按需通过 `后台 → 人员 → 权限管理` 配置。业务数据仍按当前账号的产品访问范围读取。重复安装保留管理员对权限的修改。

追溯默认在研发视图（`rnd`）、MySQL 驱动和 `zt_` 表前缀下启用。用站点 `config/my.php` 中的 `$config->specTraceWorkbench = false;` 可以禁用产品入口和页面。启用后从有权访问的产品进入 **需求追溯**。产品暂无冻结 Epic 时显示空状态，发布后可选择版本浏览。

日常动作通过部署方保护的本地服务环境绑定 `TRACE_ACTOR`（有效的禅道账号）、`TRACE_PRODUCT`（已授权产品 ID）和 `TRACE_ROLE`。服务调用方负责从已认证主体取得账号、角色和对应产品授权。能自行控制该进程环境或 DB 凭据的操作者属于受信服务边界。该 CLI 不提供面向浏览器的认证入口。

## 冻结与查询

- `import` 由 author 使用。请求包含 `product, epic, expected_revision, graph`。首版预期修订为 0。相同作者以相同基线重试相同图，返回首次冻结回执。其他过期写入报告冲突。
- `graph` 包含固定 Git 提交 `baseline`、`features`、`sources`、`items` 三个数组。feature 具有 `id`。source 具有 `id, revision, path, blob`。item 具有 `id, revision, summary, sources, actual_consumers`。item.sources 保存版本化定位，包含 `id, revision, path, blob` 及上层检查器支持的行范围、引句等精确定位字段。消费者边具有 `feature_id, obligation_id`。
- 同一 Epic 内同一来源或需求项身份及版本绑定固定内容。改变义务或关联需升需求项版本。图保留附加业务字段和重复输入，SQL 的关系计数按唯一边统计。悬空定位和未知消费者随请求入库，后续审计可核对缺口。
- `snapshot` 可由 reader、author、reviewer 使用。输入 `product, epic` 及可选 `revision`，输出 `product,epic,revision,basis,author,graph,statistics,decisions`。省略修订时返回当前头。快照包含活动修订 `active_revision`。历史修订中的所有决定都返回 `current=false`，原决定与证据保留。活动放行需核对当前头和准确审阅目标。
- `basis` 为完整 graph 的 SHA-256。使用 UTF-8、递归键排序、紧凑 JSON，保留 Unicode 和斜线。值支持对象、数组、字符串、整型、布尔、null。

## 独审决定

`decision` 仅由 reviewer 使用。请求必须提供 `product, epic, revision, basis, stage, scope, target_revision, decision, evidence`。决定为 `accepted` 或 `changes_requested`，核对请求的准确冻结修订和审阅主体。相同请求幂等。后续决定携带该阶段及范围的最近 `prior_decision`。追加新决定时使用当前冻结头。第一道范围为 Epic ID，目标为冻结 Git 提交。第二道范围为功能 ID，目标为检查器提供的准确规格目标摘要，且须提供仍有效的第一道 `epic_decision`。

二审前，作者的受控进程以 `submit` 提交 `product,epic,revision,basis,scope,target_revision,epic_decision`。关系库记录真实禅道账号，将提交者与准确候选及第一道决定绑定。同一目标不能改由其他人认领。第二道决定逐项核对登记候选、真实提交者和报告中的作者。

受控服务生成并保护机械报告文件，通过环境 `TRACE_MECHANICAL_REPORT` 传递。接受决定要求报告状态 passed，逐项比对阶段、范围、产品、Epic、修订、依据、目标和上游决定。第一道核对独立期望 evidence 摘要，第二道另查 spec_author 与审阅者身份。报告和审阅证据随决定持久保存。退回决定可直接附真实发现。第一道被新决定替换时，绑定旧第一道的第二道决定保留历史并失效。此入口记录工程独审，必要人类批准仍走项目既定渠道。

## 复跑数据库验证

在具备 Docker 的主机运行 `bash tools/spec-trace/tests/run.sh`。脚本新建无网络的官方 22.7 容器，初始化官方样例数据库，经过真实 CLI 测试并移除该容器。测试内模拟的审阅和机械报告只验证入库门槛。

## 工作台只读投影

由受控出版进程从冻结 Git 基线和准确规格候选生成投影，使用单独的 `TRACE_ROLE=publisher`。出版进程须验证来源树、规格提交中的路径与 blob、资料范围及定位。该角色需要读取相关原生表和冻结表、锁定所属 Epic 的 stream 行，并对投影表拥有 SELECT、INSERT。读取服务只需 SELECT。投影和 Git 中的正式正文分别用于查询与版本恢复。CLI 再次核对缓存文本的 Git blob 摘要、引用、字符分区和冻结身份。独审与必要人类审批继续走其已有入口。

- `publish` 输入为 `product,epic,revision,basis,expected_projection,projection`。初次出版传 0。相同基线、内容及出版账号的重试返回原回执；竞争更新先对账新版本再合并。修改历史冻结上的投影会被拒绝。一次出版为完整视图，单功能更新需保留同版其它功能数据。
- `projection` 读最新出版，或用 `revision,projection_revision` 读准确历史。未出版的冻结返回 `projection_revision:0,projection:null`。请求不存在的明确出版序号会失败。`projections` 列该冻结的出版回执，按新到旧排序。查询角色支持 reader、author、reviewer、publisher。
- 投影版本 1 包含 `baseline,directories,documents,fragments,features,obligations,fragment_obligations,specs,target_obligations`。`directories` 以规范路径为 ID 并指向父目录。可读文档和规格保存 UTF-8 原始文本（保留 BOM 和换行），Git blob 身份针对完整字节计算。文档分区按 **1 基 Unicode 码点、闭区间** 覆盖全部正文。未切、未关联、已处置和有关联区间分别用 `uncut,unlinked,disposed,linked` 表示。无法提取或读取的文件保留版本和状态，正文为空值。每个片段可关联多个功能义务。义务由 `item_id,item_revision,obligation_id,feature_id` 共同识别。条款和场景以 `feature_id,blob,kind,id` 识别。各关联数组支持按任一方向检索，完整投影可在独审前发布以展示缺口。
- `zt_spectrace_projection.freeze_id` 外键关联现有冻结图。读取时以禅道已授权的 `zt_spectrace_freeze.product`、Epic、冻结修订和出版修订定位；Web 读取受会话产品权限约束。多版本按 `(freeze_id,projection_revision)` 唯一。相同内容不重复追加到当前头。回执中的 `projection_digest` 是经过递归键排序的 UTF-8 JSON SHA-256。决定及其有效性从冻结关系库实时取得。
