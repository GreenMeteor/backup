<?php

namespace humhub\modules\backup\controllers;

use Yii;
use yii\web\HttpException;
use humhub\modules\admin\components\Controller;
use humhub\modules\admin\permissions\ManageModules;
use humhub\modules\backup\models\ConfigureForm;
use humhub\modules\backup\components\BackupManager;
use humhub\modules\backup\factories\BackupFactory;
use humhub\modules\backup\jobs\BackupJob;

/**
 * Admin controller for the backup module
 * Uses BackupManager and BackupFactory to handle backup operations
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
     * Renders the settings form with backups
     */
    public function actionIndex()
    {
        $model = new ConfigureForm();
        $model->loadSettings();

        $backupManager = new BackupManager();
        $backups = $backupManager->getBackups();

        $backupTypes = [
            'full' => Yii::t('BackupModule.base', 'Full Backup'),
            'database' => Yii::t('BackupModule.base', 'Database Backup'),
            'files' => Yii::t('BackupModule.base', 'Files Backup'),
            'config' => Yii::t('BackupModule.base', 'Configuration Backup'),
            'modules' => Yii::t('BackupModule.base', 'Modules Backup'),
        ];

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->save();
            $this->view->saved();
        }

        return $this->render('index', [
            'model' => $model,
            'backups' => $backups,
            'backupTypes' => $backupTypes
        ]);
    }

    /**
     * Queue a backup job
     * 
     * @param string $type Backup type (database, files, config, modules, full)
     * @return \yii\web\Response
     */
    public function actionCreateBackup($type = null)
    {
        $this->forcePostRequest();

        try {
            if ($type !== null) {
                try {
                    BackupFactory::create($type);
                } catch (\InvalidArgumentException $e) {
                    $this->view->error(Yii::t('BackupModule.base', 'Invalid backup type: {type}', ['type' => $type]));
                    return $this->redirect(['index']);
                }
            }

            $job = new BackupJob(['type' => $type]);

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
     * @param string $backupName Backup name (directory name)
     * @return \yii\web\Response
     */
    public function actionDownloadBackup($backupName)
    {
        $backupDir = Yii::getAlias('@runtime/backup/' . $backupName);

        if (!is_dir($backupDir)) {
            throw new HttpException(404, Yii::t('BackupModule.base', 'Backup not found.'));
        }

        $zipPath = $backupDir . '/backup.zip';

        if (file_exists($zipPath)) {
            return Yii::$app->response->sendFile($zipPath, $backupName . '.zip');
        }

        throw new HttpException(404, Yii::t('BackupModule.base', 'Backup file not found.'));
    }

    /**
     * Delete a backup
     * 
     * @param string $backupName Backup name (directory name)
     * @return \yii\web\Response
     */
    public function actionDeleteBackup($backupName)
    {
        $this->forcePostRequest();

        $backupManager = new BackupManager();

        if ($backupManager->deleteBackup($backupName)) {
            $this->view->success(Yii::t('BackupModule.base', 'Backup deleted successfully.'));
        } else {
            $this->view->error(Yii::t('BackupModule.base', 'Failed to delete backup.'));
        }

        return $this->redirect(['index']);
    }

    /**
     * Create a backup manually
     * 
     * @param string $type Backup type (database, files, config, modules, full)
     * @return \yii\web\Response
     */
    public function actionCreateManualBackup($type = null)
    {
        $this->forcePostRequest();

        try {
            $backupManager = new BackupManager();
            
            if ($backupManager->createBackup($type)) {
                $this->view->success(Yii::t('BackupModule.base', 'Backup created successfully.'));
            } else {
                $this->view->error(Yii::t('BackupModule.base', 'Failed to create backup.'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->view->error(Yii::t('BackupModule.base', 'Invalid backup type: {message}', ['message' => $e->getMessage()]));
            Yii::error('Invalid backup type: ' . $e->getMessage(), 'backup');
        } catch (\Exception $e) {
            $this->view->error(Yii::t('BackupModule.base', 'Error creating backup: {message}', ['message' => $e->getMessage()]));
            Yii::error('Error creating backup: ' . $e->getMessage(), 'backup');
        }

        return $this->redirect(['index']);
    }

    /**
     * Restore a backup
     * 
     * @param string $backupName Backup name (directory name)
     * @return \yii\web\Response
     */
    public function actionRestoreBackup($backupName)
    {
        $this->forcePostRequest();

        $backupDir = Yii::getAlias('@runtime/backup/' . $backupName);

        if (!is_dir($backupDir)) {
            throw new HttpException(404, Yii::t('BackupModule.base', 'Backup not found.'));
        }

        $metaFile = $backupDir . '/meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            $type = $meta['type'] ?? 'full';

            $this->view->info(Yii::t('BackupModule.base', 'Restore operation would be performed for {type} backup.', [
                'type' => $type
            ]));

            return $this->redirect(['index']);
        }

        throw new HttpException(400, Yii::t('BackupModule.base', 'Invalid backup structure.'));
    }
}
