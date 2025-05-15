<?php

namespace humhub\modules\backup\components;

use Yii;
use yii\base\Component;
use yii\helpers\FileHelper;
use humhub\modules\backup\factories\BackupFactory;

/**
 * Backup Manager Class
 * Manages all backup operations
 */
class BackupManager extends Component
{
    /**
     * @var string Default backup type
     */
    public $defaultType = 'full';

    /**
     * @var string Path to mysqldump binary
     */
    public $mysqldumpPath = 'mysqldump';

    /**
     * Create a backup
     * 
     * @param string $type Backup type (database, files, config, modules, full)
     * @return boolean success state
     */
    public function createBackup($type = null)
    {
        if ($type === null) {
            $type = $this->defaultType;
        }

        try {
            $backup = BackupFactory::create($type);

            if ($backup instanceof DatabaseBackup) {
                $backup->mysqldumpPath = $this->mysqldumpPath;
            }

            $backup->init();
            return $backup->execute();
        } catch (\Exception $e) {
            Yii::error('Error creating backup: ' . $e->getMessage(), 'backup');
            return false;
        }
    }

    /**
     * Get all backups
     * 
     * @return array List of backups
     */
    public function getBackups()
    {
        $backupDir = Yii::getAlias('@runtime/backup/');
        $backups = [];

        if (is_dir($backupDir)) {
            $directories = glob($backupDir . '*', GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $backups[] = [
                    'path' => $directory,
                    'name' => basename($directory),
                    'date' => filemtime($directory)
                ];
            }

            usort($backups, function($a, $b) {
                return $b['date'] - $a['date'];
            });
        }

        return $backups;
    }

    /**
     * Delete a backup
     * 
     * @param string $backupName Backup name (directory name)
     * @return boolean success state
     */
    public function deleteBackup($backupName)
    {
        $backupDir = Yii::getAlias('@backup/' . $backupName);

        if (is_dir($backupDir)) {
            try {
                FileHelper::removeDirectory($backupDir);
                return true;
            } catch (\Exception $e) {
                Yii::error('Error deleting backup: ' . $e->getMessage(), 'backup');
                return false;
            }
        }

        return false;
    }
}
