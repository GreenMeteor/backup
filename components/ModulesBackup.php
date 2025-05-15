<?php

namespace humhub\modules\backup\components;

use Yii;
use yii\helpers\FileHelper;

/**
 * Module Backup Class
 * Handles module files backup operations
 */
class ModulesBackup extends BaseBackup
{
    /**
     * Execute modules backup
     * 
     * @return boolean success state
     */
    public function execute()
    {
        $timestampedDir = $this->createTimestampedBackupDir();

        try {
            $rootDir = Yii::getAlias('@webroot')
            $modulesDir = $rootDir . '/protected/modules';
            $modulesBackupDir = $timestampedDir . 'modules/';

            FileHelper::createDirectory($modulesBackupDir, 0755, true);

            FileHelper::copyDirectory($modulesDir, $modulesBackupDir);

            return true;
        } catch (\Exception $e) {
            Yii::error('Error creating modules backup: ' . $e->getMessage(), 'backup');
            return false;
        }
    }
}
