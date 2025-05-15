<?php

namespace humhub\modules\backup\components;

use Yii;

/**
 * Database Backup Class
 * Handles database backup operations
 */
class DatabaseBackup extends BaseBackup
{
    /**
     * @var string Path to mysqldump binary
     */
    public $mysqldumpPath = 'mysqldump';

    /**
     * Execute database backup
     * 
     * @return boolean success state
     */
    public function execute()
    {
        $timestampedDir = $this->createTimestampedBackupDir();

        $dbConfig = Yii::$app->db->dsn;
        $dbHost = '';
        $dbName = '';

        preg_match('/host=([^;]*)/', $dbConfig, $hostMatches);
        if (isset($hostMatches[1])) {
            $dbHost = $hostMatches[1];
        }

        preg_match('/dbname=([^;]*)/', $dbConfig, $dbNameMatches);
        if (isset($dbNameMatches[1])) {
            $dbName = $dbNameMatches[1];
        }

        $backupFile = $timestampedDir . 'database_backup.sql';

        $command = sprintf(
            '%s --host=%s --user=%s --password=%s %s > %s',
            $this->mysqldumpPath,
            escapeshellarg($dbHost),
            escapeshellarg(Yii::$app->db->username),
            escapeshellarg(Yii::$app->db->password),
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0;
    }
}
