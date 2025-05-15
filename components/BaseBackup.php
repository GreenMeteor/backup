<?php

namespace humhub\modules\backup\components;

use Yii;
use yii\base\Component;
use yii\helpers\FileHelper;
use humhub\modules\backup\interfaces\BackupInterface;

/**
 * Abstract Base Backup Class
 * Contains common functionality for all backup types
 */
abstract class BaseBackup extends Component implements BackupInterface
{
    /**
     * @var string The backup directory path
     */
    protected $backupDir;

    /**
     * Initialize the backup component
     */
    public function init()
    {
        parent::init();

        $this->backupDir = Yii::getAlias('@runtime/backup/');
        if (!is_dir($this->backupDir)) {
            FileHelper::createDirectory($this->backupDir, 0755, true);
        }
    }

    /**
     * Get the backup directory
     * 
     * @return string path to backup directory
     */
    public function getBackupDir()
    {
        return $this->backupDir;
    }

    /**
     * Create a timestamped backup directory
     * 
     * @return string path to timestamped backup directory
     */
    protected function createTimestampedBackupDir()
    {
        $timestampedDir = $this->backupDir . date('Y-m-d_H-i-s') . '/';
        if (!is_dir($timestampedDir)) {
            FileHelper::createDirectory($timestampedDir, 0755, true);
        }
        return $timestampedDir;
    }
}
