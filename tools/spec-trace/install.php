<?php
declare(strict_types=1);

final class SpecTraceInstall
{
    public static function enabled(object $config): bool
    {
        return !empty($config->specTraceWorkbench)
            && $config->vision === 'rnd'
            && $config->db->driver === 'mysql'
            && $config->db->prefix === 'zt_';
    }

    public static function run(PDO|dbh $db): void
    {
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if($schema === false) throw new RuntimeException('Cannot read the trace schema');
        foreach(explode(';', $schema) as $statement)
        {
            if(trim($statement) === '') continue;
            if($db instanceof PDO)
            {
                if($db->exec($statement) === false) throw new RuntimeException('Cannot install the trace schema');
                continue;
            }
            $result = $db->rawQuery($statement);
            if(!$result) throw new RuntimeException('Cannot install the trace schema');
            $result->closeCursor();
        }

        /* Store the marker with the grants so reruns preserve changes made in Group Privileges. */
        if(!$db->beginTransaction()) throw new RuntimeException('Cannot initialize trace privileges');
        try
        {
            $mark = $db->prepare("INSERT IGNORE INTO zt_config (vision, owner, module, section, `key`, value)
                VALUES ('rnd', 'system', 'product', 'traceWorkbench', 'initialPrivileges', '1')");
            if(!$mark || !$mark->execute()) throw new RuntimeException('Cannot initialize trace privileges');
            if($mark->rowCount() === 1)
            {
                $grant = $db->prepare("INSERT IGNORE INTO zt_grouppriv (`group`, module, method)
                    SELECT id, 'product', 'traceworkbench' FROM zt_group
                    WHERE vision = 'rnd' AND role = 'admin' AND project = 0 AND devopsSpace = 0");
                if(!$grant || !$grant->execute()) throw new RuntimeException('Cannot grant the default trace privilege');
            }
            if(!$db->commit()) throw new RuntimeException('Cannot save trace privileges');
        }
        catch(Throwable $error)
        {
            $db->rollBack();
            throw $error;
        }
    }
}
