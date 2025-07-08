<?php

use yii\helpers\Url;
use humhub\libs\Html;
use humhub\widgets\Button;
use yii\widgets\ActiveForm;
use humhub\modules\ui\view\components\View;

/** @var $this View */
/** @var $model \humhub\modules\backup\models\ConfigureForm */
/** @var $backups array */

$this->title = Yii::t('BackupModule.base', 'Backup Management');

\humhub\modules\backup\assets\Assets::register($this);

$anyEnabled = (
    $model->backupDatabase ||
    $model->backupModules ||
    $model->backupConfig ||
    $model->backupUploads ||
    $model->backupTheme
);

// Check if backup is currently being created
$backupInProgress = Yii::$app->session->get('backup_in_progress', false);
$downloadInProgress = Yii::$app->session->get('download_in_progress', []);

?>

<div class="container-fluid backup-admin-container">
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <?= Yii::t('BackupModule.base', 'Backup Settings'); ?>
                </div>
                <div class="panel-body">
                    <?php $form = ActiveForm::begin(['id' => 'backup-settings-form']); ?>
                        <div class="row">
                            <div class="col-md-8">
                                <?= $form->field($model, 'backupDir')
                                    ->textInput(['maxlength' => 255])
                                    ->hint($model->getAttributeHint('backupDir')); ?>
                            </div>
                            <div class="col-md-4">
                                <?= $form->field($model, 'themeName')
                                    ->textInput(['maxlength' => 255])
                                    ->hint($model->getAttributeHint('themeName')); ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-8">
                                <div class="panel panel-default">
                                    <div class="panel-heading">
                                        <?= Yii::t('BackupModule.base', 'Backup Components'); ?>
                                    </div>
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="backup-component-section">
                                                    <?= $form->field($model, 'backupDatabase')->checkbox(); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="backup-component-section">
                                                    <?= $form->field($model, 'backupModules')->checkbox(); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="backup-component-section">
                                                    <?= $form->field($model, 'backupConfig')->checkbox(); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="backup-component-section">
                                                    <?= $form->field($model, 'backupUploads')->checkbox(); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="backup-component-section">
                                                    <?= $form->field($model, 'backupTheme')->checkbox(); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="panel panel-default">
                                    <div class="panel-heading">
                                        <?= Yii::t('BackupModule.base', 'Automated Backups'); ?>
                                    </div>
                                    <div class="panel-body">
                                        <?= $form->field($model, 'enableAutoBackup')
                                            ->checkbox()
                                            ->hint(Yii::t('BackupModule.base', 'Automatic backups may consume significant server resources and disk space. Ensure your server has adequate performance monitoring and storage capacity. Failed automated backups may not generate notifications.')); ?>
                                        
                                        <?= $form->field($model, 'autoBackupFrequency')->dropDownList([
                                            'daily' => Yii::t('BackupModule.base', 'Daily'),
                                            'weekly' => Yii::t('BackupModule.base', 'Weekly'),
                                            'monthly' => Yii::t('BackupModule.base', 'Monthly'),
                                        ]); ?>
                                        
                                        <?= $form->field($model, 'keepBackups')
                                            ->textInput(['type' => 'number', 'min' => 1, 'max' => 100])
                                            ->hint($model->getAttributeHint('keepBackups')); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <?= Html::submitButton(Yii::t('base', 'Save'), [
                                'class' => 'btn btn-primary',
                                'data-ui-loader' => false
                            ]); ?>
                        </div>
                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($anyEnabled): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <?= Yii::t('BackupModule.base', 'Backup Actions'); ?>
                    </div>
                    <div class="panel-body">
                        <div class="row backup-actions">
                            <div class="col-md-8">
                                <div id="backup-button-container">
                                    <?php if ($backupInProgress): ?>
                                        <?= Button::info(Yii::t('BackupModule.base', 'Backup in Progress...'))
                                            ->icon('clock-o')
                                            ->options(['disabled' => true, 'class' => 'btn btn-info', 'id' => 'backup-btn']); ?>
                                        <p class="help-block" id="backup-status-message" style="margin-top: 10px;">
                                            <i class="fa fa-info-circle"></i>
                                            <?= Yii::t('BackupModule.base', 'A backup is currently being created. Please wait for it to complete before starting another one.'); ?>
                                        </p>
                                    <?php else: ?>
                                        <?= Button::primary(Yii::t('BackupModule.base', 'Create Backup'))
                                            ->link(Url::to(['create-backup']))
                                            ->options(['data-method' => 'POST', 'id' => 'backup-btn']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <?= Button::warning(Yii::t('BackupModule.base', 'Cleanup Old Backups'))
                                    ->link(Url::to(['cleanup-backups']))
                                    ->options(['data-method' => 'POST']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <?= Yii::t('BackupModule.base', 'Available Backups'); ?>
                </div>
                <div class="panel-body">
                    <?php if (empty($backups)): ?>
                        <div class="empty-state">
                            <?= Yii::t('BackupModule.base', 'No backups found.'); ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><?= Yii::t('BackupModule.base', 'Filename'); ?></th>
                                        <th><?= Yii::t('BackupModule.base', 'Size'); ?></th>
                                        <th><?= Yii::t('BackupModule.base', 'Created At'); ?></th>
                                        <th class="text-center"><?= Yii::t('BackupModule.base', 'Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                        <?php 
                                        $isDownloading = in_array($backup['filename'], $downloadInProgress);
                                        ?>
                                        <tr class="backup-item">
                                            <td>
                                                <span class="backup-filename"><?= Html::encode($backup['filename']); ?></span>
                                            </td>
                                            <td>
                                                <span class="backup-size"><?= Html::encode($backup['size']); ?></span>
                                            </td>
                                            <td>
                                                <?= Yii::$app->formatter->asDate($backup['created_at']); ?>
                                            </td>
                                            <td class="text-nowrap text-center">
                                                <div class="btn-group" role="group">
                                                    <?php if ($isDownloading): ?>
                                                        <?= Button::info()
                                                            ->icon('clock-o')
                                                            ->tooltip(Yii::t('BackupModule.base', 'Download in Progress...'))
                                                            ->xs()
                                                            ->options(['disabled' => true]); ?>
                                                    <?php else: ?>
                                                        <?= Button::primary()
                                                            ->link(Url::to(['download-backup', 'fileName' => $backup['filename']]))
                                                            ->icon('cloud-download')
                                                            ->tooltip(Yii::t('BackupModule.base', 'Download'))
                                                            ->xs()
                                                            ->options([
                                                                'class' => 'download-btn',
                                                                'data-filename' => $backup['filename'],
                                                                'data-method' => 'GET',
                                                                'data-ui-loader' => false,
                                                            ]); ?>
                                                    <?php endif; ?>

                                                    <?= Button::warning()
                                                        ->link(Url::to(['restore-backup', 'fileName' => $backup['filename']]))
                                                        ->icon('refresh')
                                                        ->tooltip(Yii::t('BackupModule.base', 'Restore'))
                                                        ->xs()
                                                        ->options([
                                                            'data-method' => 'POST',
                                                            'data-confirm' => Yii::t('BackupModule.base', 'Are you sure you want to restore this backup? This will overwrite existing data.'),
                                                        ]); ?>

                                                    <?= Button::danger()
                                                        ->link(Url::to(['delete-backup', 'fileName' => $backup['filename']]))
                                                        ->icon('trash')
                                                        ->tooltip(Yii::t('BackupModule.base', 'Delete'))
                                                        ->xs()
                                                        ->options([
                                                            'data-method' => 'POST',
                                                            'data-confirm' => Yii::t('BackupModule.base', 'Are you sure you want to delete this backup?'),
                                                        ]); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.backup-actions {
    align-items: center;
}

.backup-actions .col-md-8 {
    display: flex;
    align-items: flex-start;
    flex-direction: column;
}

.backup-actions .col-md-4 {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

/* Ensure consistent button heights */
.backup-actions .btn {
    margin: 0;
}

/* Improve spacing */
.backup-component-section {
    margin-bottom: 10px;
}

/* Style for backup in progress state */
.btn[disabled] {
    cursor: not-allowed;
    opacity: 0.6;
}

.help-block i.fa {
    color: #337ab7;
}
</style>

<script <?= Html::nonce() ?>>
document.addEventListener('DOMContentLoaded', function() {
    const downloadButtons = document.querySelectorAll('.download-btn');

    downloadButtons.forEach(button => {
        button.addEventListener('click', function () {
            const filename = this.dataset.filename;

            this.classList.add('disabled');
            this.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

            const cookieName = `download_complete_${filename}`;
            const self = this;

            const checkCookie = setInterval(() => {
                if (document.cookie.includes(`${cookieName}=1`)) {
                    self.classList.remove('disabled');
                    self.innerHTML = '<i class="fa fa-cloud-download"></i>';
                    clearInterval(checkCookie);

                    document.cookie = `${cookieName}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
                }
            }, 1000);
        });
    });

    <?php if ($backupInProgress): ?>
    let backupStatusInterval;

    function checkBackupStatus() {
        fetch('<?= Url::to(['check-backup-status']) ?>', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            console.log('Backup status:', data);

            if (data.status === 'completed') {
                if (data.message) {
                    console.log(data.message);
                }
                clearInterval(backupStatusInterval);
                window.location.reload();
            } else if (data.status === 'timeout') {
                if (data.message) {
                    alert(data.message);
                }
                clearInterval(backupStatusInterval);
                window.location.reload();
            } else if (data.status === 'idle') {
                clearInterval(backupStatusInterval);
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error checking backup status:', error);
        });
    }

    backupStatusInterval = setInterval(checkBackupStatus, 2000);

    window.addEventListener('beforeunload', function() {
        if (backupStatusInterval) {
            clearInterval(backupStatusInterval);
        }
    });
    <?php endif; ?>
});
</script>