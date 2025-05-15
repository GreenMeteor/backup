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
    public $backupDatabase;

    /**
     * @var bool backup modules
     */
    public $backupModules;

    /**
     * @var bool backup config
     */
    public $backupConfig;

    /**
     * @var bool backup uploads
     */
    public $backupUploads;

    /**
     * @var bool backup theme
     */
    public $backupTheme;

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
     * @var string path to mysqldump binary
     */
    public $mysqldumpPath = 'mysqldump';

    /**
     * @var string default backup type
     */
    public $defaultBackupType = 'full';

    /**
     * @var bool enable backup compression
     */
    public $enableCompression = true;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['backupDir', 'themeName', 'mysqldumpPath'], 'string'],
            [['backupDatabase', 'backupModules', 'backupConfig', 'backupUploads', 'backupTheme', 'enableAutoBackup', 'enableCompression'], 'boolean'],
            [['autoBackupFrequency'], 'in', 'range' => ['daily', 'weekly', 'monthly']],
            [['defaultBackupType'], 'in', 'range' => ['full', 'database', 'files', 'config', 'modules']],
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
            'mysqldumpPath' => Yii::t('BackupModule.base', 'Path to mysqldump binary'),
            'defaultBackupType' => Yii::t('BackupModule.base', 'Default Backup Type'),
            'enableCompression' => Yii::t('BackupModule.base', 'Enable Backup Compression'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints()
    {
        return [
            'backupDir' => Yii::t('BackupModule.base', 'Default is @runtime/backups'),
            'backupDatabase' => Yii::t('BackupModule.base', 'Database backup requires mysqldump binary.'),
            'backupModules' => Yii::t('BackupModule.base', 'Warning: If you have a lot of modules in <code>/protected/modules</code> then extended wait time may occur.'),
            'themeName' => Yii::t('BackupModule.base', 'Only required if you want to backup a custom theme'),
            'enableAutoBackup' => Yii::t('BackupModule.base', 'Warning: Enable at your own risk'),
            'keepBackups' => Yii::t('BackupModule.base', 'Older backups will be automatically deleted'),
            'mysqldumpPath' => Yii::t('BackupModule.base', 'Usually just "mysqldump", but may need full path on some systems'),
            'defaultBackupType' => Yii::t('BackupModule.base', 'The default backup type to use when creating backups'),
            'enableCompression' => Yii::t('BackupModule.base', 'Creates compressed ZIP archives (recommended)'),
        ];
    }

    /**
     * Loads the current module settings
     */
    public function loadSettings()
    {
        $module = Yii::$app->getModule('backup');

        $this->backupDir = $module->settings->get('backupDir', '@runtime/backups');
        $this->backupDatabase = (boolean) $module->settings->get('backupDatabase');
        $this->backupModules = (boolean) $module->settings->get('backupModules');
        $this->backupConfig = (boolean) $module->settings->get('backupConfig');
        $this->backupUploads = (boolean) $module->settings->get('backupUploads');
        $this->backupTheme = (boolean) $module->settings->get('backupTheme');
        $this->themeName = $module->settings->get('themeName', '');
        $this->enableAutoBackup = (boolean) $module->settings->get('enableAutoBackup', false);
        $this->autoBackupFrequency = $module->settings->get('autoBackupFrequency', 'weekly');
        $this->keepBackups = (int) $module->settings->get('keepBackups', 5);
        $this->mysqldumpPath = $module->settings->get('mysqldumpPath', 'mysqldump');
        $this->defaultBackupType = $module->settings->get('defaultBackupType', 'full');
        $this->enableCompression = (boolean) $module->settings->get('enableCompression', true);

        return true;
    }

    /**
     * Saves module settings
     */
    public function save()
    {
        $module = Yii::$app->getModule('backup');

        $module->settings->set('backupDir', $this->backupDir);
        $module->settings->set('backupDatabase', $this->backupDatabase);
        $module->settings->set('backupModules', $this->backupModules);
        $module->settings->set('backupConfig', $this->backupConfig);
        $module->settings->set('backupUploads', $this->backupUploads);
        $module->settings->set('backupTheme', $this->backupTheme);
        $module->settings->set('themeName', $this->themeName);
        $module->settings->set('enableAutoBackup', $this->enableAutoBackup);
        $module->settings->set('autoBackupFrequency', $this->autoBackupFrequency);
        $module->settings->set('keepBackups', $this->keepBackups);
        $module->settings->set('mysqldumpPath', $this->mysqldumpPath);
        $module->settings->set('defaultBackupType', $this->defaultBackupType);
        $module->settings->set('enableCompression', $this->enableCompression);

        return true;
    }

    /**
     * Get available backup types for dropdown
     * 
     * @return array
     */
    public function getBackupTypeOptions()
    {
        return [
            'full' => Yii::t('BackupModule.base', 'Full Backup'),
            'database' => Yii::t('BackupModule.base', 'Database Backup'),
            'files' => Yii::t('BackupModule.base', 'Files Backup'),
            'config' => Yii::t('BackupModule.base', 'Configuration Backup'),
            'modules' => Yii::t('BackupModule.base', 'Modules Backup'),
        ];
    }

    /**
     * Get available backup frequency options for dropdown
     * 
     * @return array
     */
    public function getFrequencyOptions()
    {
        return [
            'daily' => Yii::t('BackupModule.base', 'Daily'),
            'weekly' => Yii::t('BackupModule.base', 'Weekly'),
            'monthly' => Yii::t('BackupModule.base', 'Monthly'),
        ];
    }
}
