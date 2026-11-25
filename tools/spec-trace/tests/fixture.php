<?php
declare(strict_types=1);
// Run only in the throwaway image created by run.sh; no external DB settings.
$db = new PDO('mysql:unix_socket=/data/mysql/tmp/mysql.sock;dbname=zentao;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$count = $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
if ((int)$count !== 0) throw new RuntimeException('fixture database must be empty');
foreach (['zentao.sql','demo.sql'] as $file) {
    $db->exec(str_replace('__DATABASE__','zentao',file_get_contents('/apps/zentao/db/' . $file)));
}
echo "installed official demo dataset\n";
