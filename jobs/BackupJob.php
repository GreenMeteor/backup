<?php

namespace humhub\modules\backup\jobs;

use Yii;
use yii\helpers\FileHelper;
use humhub\modules\queue\LongRunningActiveJob;
use humhub\modules\backup\models\ConfigureForm;
use humhub\modules\backup\components\BackupManager;

/**
 * BackupJob handles the creation of backups as a background process.
 */
class BackupJob extends LongRunningActiveJob
{
    /**
     * @var string The type of backup to create (database, files, config, modules, full)
     */
    public $type;

    /**
     * @var array Backup configuration settings
     */
    public $settings;

    /**
     * @inheritDoc
     */
    public function run()
    {
        $form = new ConfigureForm();
        $form->loadSettings();

        if (!empty($this->settings)) {
            $form->load(['ConfigureForm' => $this->settings]);

            if ($form->backupTheme && empty($form->themeName)) {
                $form->themeName = Yii::$app->view->theme->name ?? 'HumHub';
            }

            if (!$form->validate()) {
                $this->logError('BackupJob: Configuration validation failed.', $form->getErrors());
                return false;
            }

            $form->save();
        }

        try {
            $backupPath = Yii::getAlias($form->backupDir ?: '@runtime/backup');
            if (!is_dir($backupPath)) {
                FileHelper::createDirectory($backupPath, 0775, true);
            }

            $backupManager = new BackupManager();

            if (!empty($form->mysqldumpPath)) {
                $backupManager->mysqldumpPath = $form->mysqldumpPath;
            }

            if ($this->type === null) {
                if ($form->backupDatabase && $form->backupModules && 
                    $form->backupConfig && $form->backupUploads) {
                    $this->type = 'full';
                } elseif ($form->backupDatabase) {
                    $this->type = 'database';
                } elseif ($form->backupModules) {
                    $this->type = 'modules';
                } elseif ($form->backupConfig) {
                    $this->type = 'config';
                } elseif ($form->backupUploads) {
                    $this->type = 'files';
                } else {
                    $this->type = 'full';
                }
            }

            $success = $backupManager->createBackup($this->type);

            if (!$success) {
                $this->logError('BackupJob: Failed to create ' . $this->type . ' backup.');
                return false;
            }

            if ($form->keepBackups > 0) {
                $this->cleanupOldBackups($backupManager, $form->keepBackups);
            }

            return true;

        } catch (\Throwable $e) {
            $this->logError('BackupJob exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Clean up old backups
     * 
     * @param BackupManager $backupManager
     * @param int $keepBackups Number of backups to keep
     */
    private function cleanupOldBackups(BackupManager $backupManager, int $keepBackups)
    {
        try {
            $backups = $backupManager->getBackups();

            usort($backups, function($a, $b) {
                return $b['date'] - $a['date'];
            });

            if (count($backups) > $keepBackups) {
                for ($i = $keepBackups; $i < count($backups); $i++) {
                    $backupManager->deleteBackup($backups[$i]['name']);
                }
            }
        } catch (\Exception $e) {
            Yii::error('Failed to clean up old backups: ' . $e->getMessage(), 'backup');
        }
    }

    /**
     * Log an error message
     * 
     * @param string $message
     * @param array|null $context Optional error context (e.g. validation errors)
     */
    private function logError(string $message, array $context = null)
    {
        Yii::error($message . ($context ? (' | Context: ' . print_r($context, true)) : ''), 'backup');
    }
}
