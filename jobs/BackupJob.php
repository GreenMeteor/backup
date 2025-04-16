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
     * @var string The user ID who initiated the backup
     */
    public $userId;

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

            $this->sendNotification(
                Yii::t('BackupModule.base', 'Backup Completed'),
                Yii::t('BackupModule.base', 'Your backup has been successfully created: {filename}', ['filename' => Html::encode($filename)])
            );

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

    /**
     * Send a notification to the user who initiated the backup
     * 
     * @param string $title The notification title
     * @param string $message The notification message
     */
    private function sendNotification($title, $message)
    {
        if (empty($this->userId)) {
            return;
        }

        $user = \humhub\modules\user\models\User::findOne(['id' => $this->userId]);
        if ($user === null) {
            return;
        }

        try {
            $notification = new \humhub\modules\notification\models\Notification();
            $notification->class = 'humhub\modules\backup\notifications\BackupNotification';
            $notification->user_id = $user->id;
            $notification->module = 'backup';
            $notification->source_class = self::class;

            $notification->data = [
                'title' => $title,
                'message' => $message
            ];

            $notification->save();
        } catch (\Exception $e) {
            Yii::error('Failed to send backup notification: ' . $e->getMessage(), 'backup');
        }
    }
}
