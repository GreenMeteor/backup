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
     * AJAX endpoint to check backup status
     */
    public function actionCheckBackupStatus()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if (!Yii::$app->session->get('backup_in_progress', false)) {
            return [
                'status' => 'idle',
                'message' => null
            ];
        }

        $backupStartedAt = Yii::$app->session->get('backup_started_at', 0);
        $currentTime = time();

        $timeoutMinutes = 10;
        if (($currentTime - $backupStartedAt) > ($timeoutMinutes * 60)) {
            Yii::$app->session->remove('backup_in_progress');
            Yii::$app->session->remove('backup_started_at');

            return [
                'status' => 'timeout',
                'message' => Yii::t('BackupModule.base', 'Backup operation has timed out. Please check if the backup was completed successfully.')
            ];
        }

        $backupManager = new BackupManager();
        $backups = $backupManager->getBackupsList();

        if (!empty($backups)) {
            usort($backups, function($a, $b) {
                $aTime = isset($a['created_at']) ? $a['created_at'] : 0;
                $bTime = isset($b['created_at']) ? $b['created_at'] : 0;

                if ($aTime === 0) {
                    $backupManager = new BackupManager();
                    $aPath = $backupManager->getBackupDirectory() . '/' . $a['filename'];
                    if (file_exists($aPath)) {
                        $aTime = filemtime($aPath);
                    }
                }

                if ($bTime === 0) {
                    $backupManager = new BackupManager();
                    $bPath = $backupManager->getBackupDirectory() . '/' . $b['filename'];
                    if (file_exists($bPath)) {
                        $bTime = filemtime($bPath);
                    }
                }

                return $bTime <=> $aTime;
            });

            $latestBackup = reset($backups);
            $latestBackupTime = isset($latestBackup['created_at']) ? $latestBackup['created_at'] : 0;

            if ($latestBackupTime === 0) {
                $latestBackupPath = $backupManager->getBackupDirectory() . '/' . $latestBackup['filename'];
                if (file_exists($latestBackupPath)) {
                    $latestBackupTime = filemtime($latestBackupPath);
                }
            }

            if ($latestBackupTime > ($backupStartedAt - 30)) {
                Yii::$app->session->remove('backup_in_progress');
                Yii::$app->session->remove('backup_started_at');

                return [
                    'status' => 'completed',
                    'message' => Yii::t('BackupModule.base', 'Backup completed successfully.')
                ];
            }
        }

        return [
            'status' => 'in_progress',
            'message' => null
        ];
    }

    /**
     * Creates a new backup using the background job
     */
    public function actionCreateBackup()
    {
        $this->forcePostRequest();

        if (Yii::$app->session->get('backup_in_progress', false)) {
            $this->view->warning(Yii::t('BackupModule.base', 'A backup is already in progress. Please wait for it to complete.'));
            return $this->redirect(['index']);
        }

        try {
            $job = new BackupJob([]);

            if (Yii::$app->queue->push($job)) {
                Yii::$app->session->set('backup_in_progress', true);
                Yii::$app->session->set('backup_started_at', time());
                
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

        $downloadInProgress = Yii::$app->session->get('download_in_progress', []);
        $downloadInProgress[] = $fileName;
        Yii::$app->session->set('download_in_progress', $downloadInProgress);

        Yii::$app->response->on(Response::EVENT_AFTER_SEND, function() use ($fileName) {
            $downloadInProgress = Yii::$app->session->get('download_in_progress', []);
            $downloadInProgress = array_diff($downloadInProgress, [$fileName]);
            Yii::$app->session->set('download_in_progress', $downloadInProgress);
        });

        Yii::$app->response->cookies->add(new \yii\web\Cookie([
            'name' => 'download_complete_' . $fileName,
            'value' => '1',
            'expire' => time() + 10,
        ]));

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

    /**
     * Manual action to clear backup progress state (for troubleshooting)
     */
    public function actionClearBackupState()
    {
        $this->forcePostRequest();

        Yii::$app->session->remove('backup_in_progress');
        Yii::$app->session->remove('backup_started_at');
        Yii::$app->session->remove('download_in_progress');

        $this->view->info(Yii::t('BackupModule.base', 'Backup state cleared successfully.'));

        return $this->redirect(['index']);
    }
}