<?php

namespace humhub\modules\backup\jobs;

use Yii;
use humhub\modules\queue\LongRunningActiveJob;
use humhub\modules\backup\components\BackupManager;
use humhub\modules\backup\models\ConfigureForm;
use yii\helpers\Html;
use humhub\modules\admin\permissions\ManageModules;

/**
 * BackupJob handles the creation of backups as a background process
 * 
 * This job is designed to handle potentially lengthy backup operations
 * without blocking the web interface.
 */
class BackupJob extends LongRunningActiveJob
{
    /**
     * @var array Backup configuration settings
     */
    public $settings;

    /**
     * @inheritDoc
     */
    public function run()
    {
        if (!empty($this->settings)) {
            $configureForm = new ConfigureForm();
            $configureForm->load(['ConfigureForm' => $this->settings]);
            $configureForm->saveSettings();
        }

        try {
            $backupManager = new BackupManager();
            $filename = $backupManager->createBackup();

            if ($filename === false) {
                $this->logError('Failed to create backup');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('Backup error: ' . $e->getMessage());

            $this->sendNotification(
                Yii::t('BackupModule.base', 'Backup Failed'),
                Yii::t('BackupModule.base', 'An error occurred during backup creation: {error}', ['error' => Html::encode($e->getMessage())])
            );

            return false;
        }
    }

    /**
     * Log an error message
     * 
     * @param string $message
     */
    private function logError($message)
    {
        Yii::error($message, 'backup');
    }
}
