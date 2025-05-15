<?php

namespace humhub\modules\backup\components;

use Yii;

/**
 * Files Backup Class
 * Handles file system backup operations
 */
class FilesBackup extends BaseBackup
{
    /**
     * @var string Directory containing files to backup
     */
    public $filesDir;

    /**
     * Initialize the files backup component
     */
    public function init()
    {
        parent::init();

        if ($this->filesDir === null) {
            $this->filesDir = Yii::getAlias('@webroot/uploads/');
        }
    }

    /**
     * Execute files backup
     * 
     * @return boolean success state
     */
    public function execute()
    {
        $timestampedDir = $this->createTimestampedBackupDir();

        try {
            $zipFile = $timestampedDir . 'files_backup.zip';
            $zip = new \ZipArchive();

            if ($zip->open($zipFile, \ZipArchive::CREATE) === true) {
                $this->addDirToZip($zip, $this->filesDir, basename($this->filesDir));
                $zip->close();
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Yii::error('Error creating files backup: ' . $e->getMessage(), 'backup');
            return false;
        }
    }

    /**
     * Add a directory to a ZIP archive recursively
     * 
     * @param \ZipArchive $zip The ZIP archive
     * @param string $dir Directory to add
     * @param string $zipDir Directory name in the ZIP
     */
    protected function addDirToZip($zip, $dir, $zipDir)
    {
        if (is_dir($dir)) {
            if ($handle = opendir($dir)) {
                while (($file = readdir($handle)) !== false) {
                    if ($file != '.' && $file != '..') {
                        $filePath = $dir . '/' . $file;
                        $zipFilePath = $zipDir . '/' . $file;

                        if (is_dir($filePath)) {
                            $zip->addEmptyDir($zipFilePath);
                            $this->addDirToZip($zip, $filePath, $zipFilePath);
                        } else {
                            $zip->addFile($filePath, $zipFilePath);
                        }
                    }
                }
                closedir($handle);
            }
        }
    }
}
