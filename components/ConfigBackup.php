<?php

namespace humhub\modules\backup\components;

use Yii;
use yii\helpers\FileHelper;

/**
 * Module Configuration Backup Class
 * Handles module configuration backup operations
 */
class ConfigBackup extends BaseBackup
{
    /**
     * Execute configuration backup
     * 
     * @return boolean success state
     */
    public function execute()
    {
        $timestampedDir = $this->createTimestampedBackupDir();

        try {
            $rootDir = Yii::getAlias('@webroot')
            $configDir = $rootDir . '/protected/config';
            $configBackupDir = $timestampedDir . 'config/';

            FileHelper::createDirectory($configBackupDir, 0755, true);

            FileHelper::copyDirectory($configDir, $configBackupDir);

            return true;
        } catch (\Exception $e) {
            Yii::error('Error creating config backup: ' . $e->getMessage(), 'backup');
            return false;
        }
    }
}
