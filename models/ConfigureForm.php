<?php

namespace humhub\modules\backup\models;

use Yii;
use yii\base\Model;

/**
 * ConfigureForm defines the configuration form for the backup module
 */
class ConfigureForm extends Model
{
    /**
     * @var string backup directory
     */
    public $backupDir;

    /**
     * @var bool backup database
     */
    public $backupDatabase = true;

    /**
     * @var bool backup modules
     */
    public $backupModules = true;

    /**
     * @var bool backup config
     */
    public $backupConfig = true;

    /**
     * @var bool backup uploads
     */
    public $backupUploads = true;

    /**
     * @var bool backup theme
     */
    public $backupTheme = true;

    /**
     * @var string theme name
     */
    public $themeName = '';

    /**
     * @var bool enable automatic backups
     */
    public $enableAutoBackup = false;

    /**
     * @var string frequency of automatic backups
     */
    public $autoBackupFrequency = 'weekly';

    /**
     * @var int number of backups to keep
     */
    public $keepBackups = 5;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['backupDir'], 'string'],
            [['themeName'], 'string'],
            [['backupDatabase','backupModules', 'backupConfig', 'backupUploads', 'backupTheme', 'enableAutoBackup'], 'boolean'],
            [['autoBackupFrequency'], 'in', 'range' => ['daily', 'weekly', 'monthly']],
            [['keepBackups'], 'integer', 'min' => 1, 'max' => 100],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'backupDir' => Yii::t('BackupModule.base', 'Backup Directory (absolute path or relative to HumHub root)'),
            'backupDatabase' => Yii::t('BackupModule.base', 'Backup Database'),
            'backupModules' => Yii::t('BackupModule.base', 'Backup Modules'),
            'backupConfig' => Yii::t('BackupModule.base', 'Backup Config'),
            'backupUploads' => Yii::t('BackupModule.base', 'Backup Uploads'),
            'backupTheme' => Yii::t('BackupModule.base', 'Backup Theme'),
            'themeName' => Yii::t('BackupModule.base', 'Theme Name (leave empty for default theme)'),
            'enableAutoBackup' => Yii::t('BackupModule.base', 'Enable Automatic Backups'),
            'autoBackupFrequency' => Yii::t('BackupModule.base', 'Automatic Backup Frequency'),
            'keepBackups' => Yii::t('BackupModule.base', 'Number of Backups to Keep'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints()
    {
        return [
            'backupDir' => Yii::t('BackupModule.base', 'Default is @runtime/backups'),
            'backupDatabase' => Yii::t('BackupModule.base', 'Currently disabled due to database dump issues.'),
            'backupModules' => Yii::t('BackupModule.base', 'Warning: If you have a lot of modules in <code>/protected/modules</code> then extended wait time may occur.'),
            'themeName' => Yii::t('BackupModule.base', 'Only required if you want to backup a custom theme'),
            'enableAutoBackup' => Yii::t('BackupModule.base', 'Warning: Enable at your own risk'),
            'keepBackups' => Yii::t('BackupModule.base', 'Older backups will be automatically deleted'),
        ];
    }

    /**
     * Loads the current module settings
     */
    public function loadSettings()
    {
        $this->backupDir = Yii::$app->getModule('backup')->settings->get('backupDir', '@runtime/backups');
        $this->backupDatabase = (boolean) Yii::$app->getModule('backup')->settings->get('backupDatabase', true);
        $this->backupModules = (boolean) Yii::$app->getModule('backup')->settings->get('backupModules', true);
        $this->backupConfig = (boolean) Yii::$app->getModule('backup')->settings->get('backupConfig', true);
        $this->backupUploads = (boolean) Yii::$app->getModule('backup')->settings->get('backupUploads', true);
        $this->backupTheme = (boolean) Yii::$app->getModule('backup')->settings->get('backupTheme', true);
        $this->themeName = Yii::$app->getModule('backup')->settings->get('themeName', '');
        $this->enableAutoBackup = (boolean) Yii::$app->getModule('backup')->settings->get('enableAutoBackup', false);
        $this->autoBackupFrequency = Yii::$app->getModule('backup')->settings->get('autoBackupFrequency', 'weekly');
        $this->keepBackups = (int) Yii::$app->getModule('backup')->settings->get('keepBackups', 5);

        return true;
    }

    /**
     * Saves module settings
     */
    public function save()
    {
        Yii::$app->getModule('backup')->settings->set('backupDir', $this->backupDir);
        Yii::$app->getModule('backup')->settings->set('backupDatabase', $this->backupDatabase);
        Yii::$app->getModule('backup')->settings->set('backupModules', $this->backupModules);
        Yii::$app->getModule('backup')->settings->set('backupConfig', $this->backupConfig);
        Yii::$app->getModule('backup')->settings->set('backupUploads', $this->backupUploads);
        Yii::$app->getModule('backup')->settings->set('backupTheme', $this->backupTheme);
        Yii::$app->getModule('backup')->settings->set('themeName', $this->themeName);
        Yii::$app->getModule('backup')->settings->set('enableAutoBackup', $this->enableAutoBackup);
        Yii::$app->getModule('backup')->settings->set('autoBackupFrequency', $this->autoBackupFrequency);
        Yii::$app->getModule('backup')->settings->set('keepBackups', $this->keepBackups);

        return true;
    }
}
