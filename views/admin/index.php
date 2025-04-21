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

$anyEnabled = (
    $model->backupDatabase ||
    $model->backupModules ||
    $model->backupConfig ||
    $model->backupUploads ||
    $model->backupTheme
);

?>

<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('BackupModule.base', 'Backup Settings'); ?>
    </div>
    <div class="panel-body">
        <?php $form = ActiveForm::begin(['id' => 'backup-settings-form']); ?>
            
            <?= $form->field($model, 'backupDir')->textInput(['maxlength' => 255])->hint($model->getAttributeHint('backupDir')); ?>

            <?= $form->field($model, 'backupDatabase')->checkbox(); ?>

            <?= $form->field($model, 'backupModules')->checkbox(); ?>

            <?= $form->field($model, 'backupConfig')->checkbox(); ?>

            <?= $form->field($model, 'backupUploads')->checkbox(); ?>

            <?= $form->field($model, 'backupTheme')->checkbox(); ?>

            <?= $form->field($model, 'themeName')->textInput(['maxlength' => 255])->hint($model->getAttributeHint('themeName')); ?>

            <?= $form->field($model, 'enableAutoBackup')->checkbox(); ?>

            <?= $form->field($model, 'autoBackupFrequency')->dropDownList([
                'daily' => Yii::t('BackupModule.base', 'Daily'),
                'weekly' => Yii::t('BackupModule.base', 'Weekly'),
                'monthly' => Yii::t('BackupModule.base', 'Monthly'),
            ]); ?>

            <?= $form->field($model, 'keepBackups')->textInput(['type' => 'number', 'min' => 1, 'max' => 100])->hint($model->getAttributeHint('keepBackups')); ?>

            <div class="form-group">
                <?= Html::submitButton(Yii::t('base', 'Save'), ['class' => 'btn btn-primary', 'data-ui-loader' => '']); ?>
            </div>

        <?php ActiveForm::end(); ?>
    </div>
</div>
<?php if ($anyEnabled): ?>
    <div class="panel panel-default">
        <div class="panel-heading">
            <?= Yii::t('BackupModule.base', 'Backup Actions'); ?>
        </div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-6">
                    <?= Button::primary(Yii::t('BackupModule.base', 'Create Backup'))
                        ->link(Url::to(['create-backup']))
                        ->loader(true)
                        ->options(['data-method' => 'POST']); ?>
                </div>
                <div class="col-md-6">
                    <?= Button::warning(Yii::t('BackupModule.base', 'Cleanup Old Backups'))
                        ->link(Url::to(['cleanup-backups']))
                        ->loader(true)
                        ->options(['data-method' => 'POST']); ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="panel panel-default">
    <div class="panel-heading">
        <?= Yii::t('BackupModule.base', 'Available Backups'); ?>
    </div>
    <div class="panel-body">
        <?php if (empty($backups)): ?>
            <p><?= Yii::t('BackupModule.base', 'No backups found.'); ?></p>
        <?php else: ?>
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
                        <tr>
                            <td><?= Html::encode($backup['filename']); ?></td>
                            <td><?= Html::encode($backup['size']); ?></td>
                            <td><?= Yii::$app->formatter->asDate($backup['created_at']); ?></td>
                            <td class="text-nowrap">
                                <?= Button::primary()
                                    ->link(Url::to(['download-backup', 'fileName' => $backup['filename']]))
                                    ->icon('cloud-download')
                                    ->tooltip(Yii::t('BackupModule.base', 'Download'))
                                    ->xs(); ?>

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
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
