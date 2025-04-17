<?php

namespace humhub\modules\backup\controllers;

use Yii;
use yii\web\HttpException;
use humhub\modules\admin\components\Controller;
use humhub\modules\admin\permissions\ManageModules;
use humhub\modules\backup\models\ConfigureForm;
use humhub\modules\backup\components\BackupManager;
use humhub\modules\backup\jobs\BackupJob;
use yii\web\Response;

/**
 * Admin controller for the backup module
 */
class AdminController extends Controller
{
    /**
     * @inheritdoc
     */
    public function getAccessRules()
    {
        return [
            ['permissions' => ManageModules::class]
        ];
    }

    /**
     * Renders the settings form
     */
    public function actionIndex()
    {
        $model = new ConfigureForm();
        $model->loadSettings();

        $backupManager = new BackupManager();
        $backups = $backupManager->getBackupsList();

        foreach ($backups as &$backup) {
            if (!isset($backup['created_at'])) {
                $backupFilePath = $backupManager->getBackupDirectory() . '/' . $backup['filename'];
                if (file_exists($backupFilePath)) {
                    $backup['created_at'] = filemtime($backupFilePath);
                } else {
                    $backup['created_at'] = null;
                }
            }
        }
        unset($backup);

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->save();
            $this->view->saved();
        }

        return $this->render('index', [
            'model' => $model,
            'backups' => $backups
        ]);
    }

    /**
     * Creates a new backup using the background job
     */
    public function actionCreateBackup()
    {
        $this->forcePostRequest();

        try {
            $job = new BackupJob([]);

            if (Yii::$app->queue->push($job)) {
                $this->view->info(Yii::t('BackupModule.base', 'Backup job has been queued and will run in the background.'));
            } else {
                $this->view->error(Yii::t('BackupModule.base', 'Failed to queue backup job.'));
            }
        } catch (\Exception $e) {
            $this->view->error(Yii::t('BackupModule.base', 'Error creating backup job: {message}', ['message' => $e->getMessage()]));
            Yii::error('Error creating backup job: ' . $e->getMessage(), 'backup');
        }

        return $this->redirect(['index']);
    }

    /**
     * Download a backup
     * 
     * @param string $fileName The filename to download
     * @return \yii\web\Response
     * @throws HttpException If file doesn't exist
     */  
    public function actionDownloadBackup($fileName)
    {
        $backupManager = new BackupManager();
        $filePath = $backupManager->prepareDownload($fileName);

        if ($filePath === false) {
            throw new HttpException(404, Yii::t('BackupModule.base', 'Backup file not found or invalid.'));
        }

        return Yii::$app->response->sendFile($filePath, $fileName);
    }

    /**
     * Delete a backup
     * 
     * @param string $fileName The filename to delete
     * @return \yii\web\Response
     * @throws HttpException If file doesn't exist
     */
    public function actionDeleteBackup($fileName)
    {
        $this->forcePostRequest();

        $backupManager = new BackupManager();

        if ($backupManager->deleteBackup($fileName)) {
            $this->view->success(Yii::t('BackupModule.base', 'Backup deleted successfully.'));
        } else {
            $this->view->error(Yii::t('BackupModule.base', 'Failed to delete backup.'));
        }

        return $this->redirect(['index']);
    }

    /**
     * Run auto-cleanup of old backups
     */
    public function actionCleanupBackups()
    {
        $this->forcePostRequest();

        $backupManager = new BackupManager();

        try {
            $deleted = $backupManager->cleanupOldBackups();
            $this->view->success(Yii::t('BackupModule.base', 'Deleted {count} old backup(s).', ['count' => $deleted]));
        } catch (\Exception $e) {
            $this->view->error(Yii::t('BackupModule.base', 'Error cleaning up backups: {message}', ['message' => $e->getMessage()]));
            Yii::error('Error cleaning up backups: ' . $e->getMessage(), 'backup');
        }

        return $this->redirect(['index']);
    }

    /**
     * Restore a backup
     * 
     * @param string $fileName The filename to restore
     * @return \yii\web\Response
     * @throws HttpException If file doesn't exist or restoration fails
     */
    public function actionRestoreBackup($fileName)
    {
        $this->forcePostRequest();

        $backupManager = new BackupManager();

        $result = $backupManager->restoreBackup($fileName);

        if ($result === true) {
            $this->view->success(Yii::t('BackupModule.base', 'Backup restored successfully.'));
        } else {
            $errorMessage = is_array($result) ? implode(', ', $result) : Yii::t('BackupModule.base', 'Failed to restore backup.');
            $this->view->error($errorMessage);
            Yii::error('Error restoring backup: ' . $errorMessage, 'backup');
        }

        return $this->redirect(['index']);
    }
}
