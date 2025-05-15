<?php

namespace humhub\modules\backup\factories;

use humhub\modules\backup\components\FullBackup;
use humhub\modules\backup\components\FilesBackup;
use humhub\modules\backup\components\ConfigBackup;
use humhub\modules\backup\components\ModulesBackup;
use humhub\modules\backup\components\DatabaseBackup;

/**
 * Backup Factory Class
 * Creates backup instances based on type
 */
class BackupFactory
{
    /**
     * Create a backup instance
     * 
     * @param string $type Backup type (database, files, config, modules, full)
     * @return BackupInterface Backup instance
     */
    public static function create($type)
    {
        switch ($type) {
            case 'database':
                return new DatabaseBackup();
            case 'files':
                return new FilesBackup();
            case 'config':
                return new ConfigBackup();
            case 'modules':
                return new ModulesBackup();
            case 'full':
                return new FullBackup();
            default:
                throw new \InvalidArgumentException('Invalid backup type: ' . $type);
        }
    }
}
