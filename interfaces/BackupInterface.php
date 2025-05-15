<?php

namespace humhub\modules\backup\interfaces;

/**
 * Base Backup Interface
 * Defines the contract for all backup strategies
 */
interface BackupInterface
{
    /**
     * Execute the backup operation
     * 
     * @return boolean success state
     */
    public function execute();
    
    /**
     * Get the backup directory
     * 
     * @return string path to backup directory
     */
    public function getBackupDir();
}
