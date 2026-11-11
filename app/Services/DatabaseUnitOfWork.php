<?php

declare(strict_types=1);
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
/** Only the outermost caller owns commit and rollback. */
final class DatabaseUnitOfWork
{
    public static function run(BaseConnection $db,callable $callback):mixed
    {
        $owns=$db->transDepth===0;if($owns){$db->resetTransStatus();if($db->DBDriver==='MySQLi'){$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');}}
        if($owns&&!$db->transException(true)->transBegin()){throw new \RuntimeException('Unable to start database transaction.');}
        try{$result=$callback();if(!$db->transStatus()){throw new \RuntimeException('Database transaction failed.');}if($owns&&!$db->transCommit()){throw new \RuntimeException('Unable to commit database transaction.');}return $result;}
        catch(\Throwable $e){if($owns&&$db->transDepth>0){$db->transRollback();}throw $e;}
    }
    public static function lock(BaseConnection $db,string $table,int $id):void
    {
        if(!in_array($table,['clients','leads','projects'],true)||$db->transDepth<1){throw new \LogicException('A supported parent lock requires an active transaction.');}
        $name=$db->protectIdentifiers($db->prefixTable($table));$db->query('SELECT id FROM '.$name.' WHERE id = ?'.($db->DBDriver==='MySQLi'?' FOR UPDATE':''),[$id])->getRowArray();
    }
}
