<?php

namespace humhub\modules\backup\components;

use Yii;

/**
 * Full Backup Class
 * Combines all backup types into a single operation
 */
class FullBackup extends BaseBackup
{
    /**
     * @var array List of backup components to use
     */
    protected $backupComponents = [];

    /**
     * Initialize the full backup component
     */
    public function init()
    {
        parent::init();

        $this->backupComponents = [
            new DatabaseBackup(),
            new FilesBackup(),
            new ConfigBackup(),
            new ModulesBackup()
        ];

        foreach ($this->backupComponents as $component) {
            $component->init();
        }
    }

    /**
     * Execute all backup operations
     * 
     * @return boolean success state
     */
    public function execute()
    {
        $success = true;

        foreach ($this->backupComponents as $component) {
            $result = $component->execute();
            $success = $success && $result;
        }

        return $success;
    }
}
