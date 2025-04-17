<?php

namespace humhub\modules\backup\components;

use Yii;
use ZipArchive;
use yii\helpers\FileHelper;
use humhub\modules\backup\models\ConfigureForm;

/**
 * BackupManager handles the creation and management of backups
 * Optimized version with removed MySQL functionality and improved file handling
 */
class BackupManager
{
    /**
     * @var ConfigureForm
     */
    private $settings;
    
    /**
     * @var int Maximum files to process in a batch to avoid memory issues
     */
    private $batchSize = 500;

    /**
     * BackupManager constructor.
     */
    public function __construct()
    {
        $this->settings = new ConfigureForm();
        $this->settings->loadSettings();
    }

    /**
     * Creates a backup based on the current settings
     * 
     * @return bool|string Return the backup filename on success, false on failure
     * @throws \Exception if backup directory cannot be created
     */
    public function createBackup()
    {
        $backupDir = $this->getBackupDirectory();
        if (!is_dir($backupDir)) {
            if (!FileHelper::createDirectory($backupDir, 0775, true)) {
                throw new \Exception("Could not create backup directory: $backupDir");
            }
            @chmod($backupDir, 0775);
        }

        if (!is_writable($backupDir)) {
            @chmod($backupDir, 0775);
            if (!is_writable($backupDir)) {
                throw new \Exception("Backup directory is not writable: $backupDir");
            }
        }

        $timestamp = date('Y-m-d_His');

        $hostname = $this->getSafeHostname();
        
        $filename = "humhub_backup_{$hostname}_{$timestamp}.zip";
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        if (is_file($backupPath)) {
            @unlink($backupPath);
        }

        $zip = new ZipArchive();
        $result = $zip->open($backupPath, ZipArchive::CREATE);
        if ($result !== true) {
            Yii::error("Failed to create ZIP archive: error code $result", 'backup');
            return false;
        }

        $rootDir = Yii::getAlias('@webroot');
        $processed = true;

        if ($this->settings->backupModules) {
            $modulesDir = $rootDir . '/protected/modules';
            if (is_dir($modulesDir)) {
                $processed = $processed && $this->addDirectoryToZipBatched($zip, $modulesDir, 'protected/modules');
            }
        }

        if ($processed && $this->settings->backupConfig) {
            $configDir = $rootDir . '/protected/config';
            if (is_dir($configDir)) {
                $processed = $processed && $this->addDirectoryToZipBatched($zip, $configDir, 'protected/config');
            }
        }

        if ($processed && $this->settings->backupUploads) {
            $uploadsDir = $rootDir . '/uploads';
            if (is_dir($uploadsDir)) {
                $processed = $processed && $this->addDirectoryToZipBatched($zip, $uploadsDir, 'uploads');
            }
        }

        if ($processed && $this->settings->backupTheme && !empty($this->settings->themeName)) {
            $themePath = $rootDir . '/themes/' . $this->settings->themeName;
            if (is_dir($themePath)) {
                $processed = $processed && $this->addDirectoryToZipBatched($zip, $themePath, 'themes/' . $this->settings->themeName);
            }
        }

        if (!$processed) {
            Yii::error("Failed to process one or more directories", 'backup');
            $zip->close();
            if (file_exists($backupPath)) {
                @unlink($backupPath);
            }
            return false;
        }

        $metadata = [
            'timestamp' => time(),
            'humhub_version' => Yii::$app->version,
            'backup_date' => date('Y-m-d H:i:s'),
            'backup_components' => [
                'database' => false,
                'modules' => $this->settings->backupModules,
                'config' => $this->settings->backupConfig,
                'uploads' => $this->settings->backupUploads,
                'theme' => ($this->settings->backupTheme && !empty($this->settings->themeName)),
                'theme_name' => $this->settings->themeName,
            ],
        ];

        $zip->addFromString('backup-info.json', json_encode($metadata, JSON_PRETTY_PRINT));

        $closeResult = $zip->close();
        if ($closeResult !== true) {
            Yii::error("Failed to close ZIP archive", 'backup');
            if (file_exists($backupPath)) {
                @unlink($backupPath);
            }
            return false;
        }

        if (!file_exists($backupPath)) {
            Yii::error("ZIP file was not created: $backupPath", 'backup');
            return false;
        }

        if ($this->settings->keepBackups > 0) {
            $this->cleanupOldBackups();
        }

        return $filename;
    }

    /**
     * Gets a safe hostname string for file naming that works in both web and console environments
     * 
     * @return string A safe hostname string
     */
    private function getSafeHostname()
    {
        if (Yii::$app instanceof \yii\console\Application) {
            $hostname = Yii::$app->settings->get('maintenanceMode.hostname');

            if (empty($hostname) && isset(Yii::$app->params['hostname'])) {
                $hostname = Yii::$app->params['hostname'];
            }

            if (empty($hostname)) {
                $baseUrl = Yii::$app->settings->get('baseUrl');
                if (!empty($baseUrl)) {
                    $parts = parse_url($baseUrl);
                    if (isset($parts['host'])) {
                        $hostname = $parts['host'];
                    }
                }
            }

            if (empty($hostname) && isset($_SERVER['SERVER_NAME'])) {
                $hostname = $_SERVER['SERVER_NAME'];
            }

            if (empty($hostname)) {
                $hostname = 'console';
            }
        } else {

            $hostname = Yii::$app->request->hostName;
        }

        return preg_replace('/[^a-zA-Z0-9]/', '_', $hostname);
    }

    /**
     * Get backup directory, resolving path alias if necessary
     * 
     * @return string
     */
    public function getBackupDirectory()
    {
        $backupDir = $this->settings->backupDir;

        if (empty($backupDir)) {
            $backupDir = '@runtime/backups';
        }

        if (strpos($backupDir, '@') === 0) {
            $backupDir = Yii::getAlias($backupDir);
        } elseif (!FileHelper::isAbsolutePath($backupDir)) {
            $backupDir = Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . $backupDir;
        }

        return $backupDir;
    }

    /**
     * Add a directory to the zip archive recursively with batched processing
     * to prevent memory exhaustion
     * 
     * @param ZipArchive $zip
     * @param string $sourcePath Real path to the directory
     * @param string $zipPath Path within the zip file
     * @return bool Success or failure
     */
    private function addDirectoryToZipBatched($zip, $sourcePath, $zipPath)
    {
        if (!is_dir($sourcePath)) {
            Yii::warning("Directory does not exist: $sourcePath", 'backup');
            return true;
        }

        try {
            $allFiles = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isDir() && is_readable($file->getRealPath())) {
                    $allFiles[] = $file->getRealPath();
                }
            }

            $totalFiles = count($allFiles);
            $processedFiles = 0;

            while ($processedFiles < $totalFiles) {
                $batch = array_slice($allFiles, $processedFiles, $this->batchSize);
                foreach ($batch as $filePath) {
                    $relativePath = substr($filePath, strlen($sourcePath) + 1);
                    if (!$zip->addFile($filePath, $zipPath . '/' . $relativePath)) {
                        Yii::warning("Failed to add file to ZIP: $filePath", 'backup');
                    }
                }
                $processedFiles += count($batch);

                unset($batch);
                gc_collect_cycles();
            }
            
            return true;
        } catch (\Exception $e) {
            Yii::error("Error adding directory to ZIP: " . $e->getMessage(), 'backup');
            return false;
        }
    }

    /**
     * Extract backup metadata from zip file
     * 
     * @param string $filePath
     * @return array|null
     */
    private function extractBackupMetadata($filePath)
    {
        try {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                $contents = $zip->getFromName('backup-info.json');
                $zip->close();

                if ($contents) {
                    return json_decode($contents, true);
                }
            }
        } catch (\Exception $e) {
            Yii::error("Error extracting backup metadata: " . $e->getMessage(), 'backup');
        }

        return null;
    }

    /**
     * Format file size in human readable format
     * 
     * @param int $bytes
     * @return string
     */
    private function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get list of existing backups
     * 
     * @return array Array of backup files with metadata
     */
    public function getBackupsList()
    {
        $backupDir = $this->getBackupDirectory();
        $backups = [];

        if (!is_dir($backupDir)) {
            return $backups;
        }

        $files = glob($backupDir . DIRECTORY_SEPARATOR . "humhub_backup_*.zip");

        foreach ($files as $file) {
            $filename = basename($file);
            $fileSize = filesize($file);
            $fileDate = filemtime($file);

            $metadata = $this->extractBackupMetadata($file);

            $backups[] = [
                'filename' => $filename,
                'size' => $this->formatSize($fileSize),
                'date' => date('Y-m-d H:i:s', $fileDate),
                'timestamp' => $fileDate,
                'metadata' => $metadata,
            ];
        }

        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Delete old backups based on keepBackups setting
     * 
     * @return int Number of deleted backups
     */
    public function cleanupOldBackups()
    {
        $backups = $this->getBackupsList();
        $backupDir = $this->getBackupDirectory();
        $keep = (int)$this->settings->keepBackups;

        if ($keep <= 0 || count($backups) <= $keep) {
            return 0;
        }

        $deleted = 0;

        for ($i = $keep; $i < count($backups); $i++) {
            $file = $backupDir . DIRECTORY_SEPARATOR . $backups[$i]['filename'];
            if (file_exists($file) && unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete a specific backup file
     * 
     * @param string $filename Filename of the backup to delete
     * @return bool True if deletion was successful
     */
    public function deleteBackup($filename)
    {
        if (!preg_match('/^humhub_backup_[a-zA-Z0-9_]+_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $filename)) {
            return false;
        }

        $backupDir = $this->getBackupDirectory();
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($backupPath) && is_file($backupPath)) {
            return unlink($backupPath);
        }

        return false;
    }

    /**
     * Prepare a backup for download (returns the file path)
     * 
     * @param string $filename Filename of the backup
     * @return string|false Full path to the backup file or false if not found
     */
    public function prepareDownload($filename)
    {
        if (!preg_match('/^humhub_backup_[a-zA-Z0-9_]+_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $filename)) {
            return false;
        }

        $backupDir = $this->getBackupDirectory();
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($backupPath) && is_file($backupPath)) {
            return $backupPath;
        }

        return false;
    }

    /**
     * Restore a backup (this is a complex operation that should be used carefully)
     * 
     * @param string $filename Filename of the backup to restore
     * @return bool|array True on success, array of errors on failure
     */
    public function restoreBackup($filename)
    {
        if (!preg_match('/^humhub_backup_[a-zA-Z0-9_]+_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $filename)) {
            return ['Invalid backup filename'];
        }

        $backupDir = $this->getBackupDirectory();
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;
        $errors = [];

        if (!file_exists($backupPath) || !is_file($backupPath)) {
            return ['Backup file not found'];
        }

        if (!is_readable($backupPath)) {
            @chmod($backupPath, 0664);
            if (!is_readable($backupPath)) {
                return ['Backup file exists but is not readable (permission denied)'];
            }
        }

        $rootDir = Yii::getAlias('@webroot');
        $tempDir = Yii::getAlias('@runtime/backup_restore_' . time());

        if (!FileHelper::createDirectory($tempDir, 0775, true)) {
            return ['Could not create temporary directory for restoration'];
        }

        @chmod($tempDir, 0775);

        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            FileHelper::removeDirectory($tempDir);
            return ['Could not open backup archive'];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zip->extractTo($tempDir, [$zip->getNameIndex($i)]);

            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }

        $zip->close();

        $metadata = [];
        if (file_exists($tempDir . '/backup-info.json')) {
            $metadata = json_decode(file_get_contents($tempDir . '/backup-info.json'), true);
        }

        if (isset($metadata['backup_components']['modules']) && $metadata['backup_components']['modules']) {
            if (is_dir($tempDir . '/protected/modules')) {
                try {
                    $targetDir = $rootDir . '/protected/modules';
                    if (!is_dir($targetDir)) {
                        if (!FileHelper::createDirectory($targetDir, 0775, true)) {
                            $errors[] = 'Could not create modules directory';
                            Yii::error("Could not create modules directory: $targetDir", 'backup');
                        } else {
                            @chmod($targetDir, 0775);
                        }
                    } elseif (!is_writable($targetDir)) {
                        @chmod($targetDir, 0775);
                        if (!is_writable($targetDir)) {
                            $errors[] = 'Modules directory is not writable';
                            Yii::error("Modules directory is not writable: $targetDir", 'backup');
                        }
                    }

                    if (empty($errors) || !in_array('Modules directory is not writable', $errors)) {
                        FileHelper::copyDirectory($tempDir . '/protected/modules', $targetDir, [
                            'dirMode' => 0775,
                            'fileMode' => 0664
                        ]);
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Failed to restore modules: ' . $e->getMessage();
                    Yii::error("Failed to restore modules: " . $e->getMessage(), 'backup');
                }
            }
        }

        if (isset($metadata['backup_components']['config']) && $metadata['backup_components']['config']) {
            if (is_dir($tempDir . '/protected/config')) {
                try {
                    $targetDir = $rootDir . '/protected/config';
                    if (!is_dir($targetDir)) {
                        if (!FileHelper::createDirectory($targetDir, 0775, true)) {
                            $errors[] = 'Could not create config directory';
                            Yii::error("Could not create config directory: $targetDir", 'backup');
                        } else {
                            @chmod($targetDir, 0775);
                        }
                    } elseif (!is_writable($targetDir)) {
                        @chmod($targetDir, 0775);
                        if (!is_writable($targetDir)) {
                            $errors[] = 'Config directory is not writable';
                            Yii::error("Config directory is not writable: $targetDir", 'backup');
                        }
                    }

                    if (empty($errors) || !in_array('Config directory is not writable', $errors)) {
                        FileHelper::copyDirectory($tempDir . '/protected/config', $targetDir, [
                            'dirMode' => 0775,
                            'fileMode' => 0664
                        ]);
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Failed to restore config: ' . $e->getMessage();
                    Yii::error("Failed to restore config: " . $e->getMessage(), 'backup');
                }
            }
        }

        if (isset($metadata['backup_components']['uploads']) && $metadata['backup_components']['uploads']) {
            if (is_dir($tempDir . '/uploads')) {
                try {
                    $targetDir = $rootDir . '/uploads';
                    if (!is_dir($targetDir)) {
                        if (!FileHelper::createDirectory($targetDir, 0775, true)) {
                            $errors[] = 'Could not create uploads directory';
                            Yii::error("Could not create uploads directory: $targetDir", 'backup');
                        } else {
                            @chmod($targetDir, 0775);
                        }
                    } elseif (!is_writable($targetDir)) {
                        @chmod($targetDir, 0775);
                        if (!is_writable($targetDir)) {
                            $errors[] = 'Uploads directory is not writable';
                            Yii::error("Uploads directory is not writable: $targetDir", 'backup');
                        }
                    }

                    if (empty($errors) || !in_array('Uploads directory is not writable', $errors)) {
                        FileHelper::copyDirectory($tempDir . '/uploads', $targetDir, [
                            'dirMode' => 0775,
                            'fileMode' => 0664
                        ]);

                        $this->recursiveChmod($targetDir, 0775, 0664);
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Failed to restore uploads: ' . $e->getMessage();
                    Yii::error("Failed to restore uploads: " . $e->getMessage(), 'backup');
                }
            }
        }

        if (isset($metadata['backup_components']['theme']) && $metadata['backup_components']['theme'] && 
            !empty($metadata['backup_components']['theme_name'])) {
            $themeName = $metadata['backup_components']['theme_name'];
            if (is_dir($tempDir . '/themes/' . $themeName)) {
                try {
                    $themeDir = $rootDir . '/themes';
                    $targetDir = $themeDir . '/' . $themeName;

                    if (!is_dir($themeDir)) {
                        if (!FileHelper::createDirectory($themeDir, 0775, true)) {
                            $errors[] = 'Could not create themes directory';
                            Yii::error("Could not create themes directory: $themeDir", 'backup');
                        } else {
                            @chmod($themeDir, 0775);
                        }
                    } elseif (!is_writable($themeDir)) {
                        @chmod($themeDir, 0775);
                        if (!is_writable($themeDir)) {
                            $errors[] = 'Themes directory is not writable';
                            Yii::error("Themes directory is not writable: $themeDir", 'backup');
                        }
                    }

                    if (empty($errors) || !in_array('Themes directory is not writable', $errors)) {
                        FileHelper::copyDirectory($tempDir . '/themes/' . $themeName, $targetDir, [
                            'dirMode' => 0775,
                            'fileMode' => 0664
                        ]);
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Failed to restore theme: ' . $e->getMessage();
                    Yii::error("Failed to restore theme: " . $e->getMessage(), 'backup');
                }
            }
        }

        FileHelper::removeDirectory($tempDir);

        if (empty($errors)) {
            return true;
        }

        return $errors;
    }

    /**
     * Set permissions recursively on a directory
     * 
     * @param string $path Path to set permissions on
     * @param int $dirMode Permission mode for directories
     * @param int $fileMode Permission mode for files
     */
    private function recursiveChmod($path, $dirMode, $fileMode) 
    {
        if (!is_dir($path)) {
            return;
        }

        $directoryIterator = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
        
        $count = 0;
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @chmod($item->getRealPath(), $dirMode);
            } else {
                @chmod($item->getRealPath(), $fileMode);
            }

            if (++$count % 100 === 0) {
                gc_collect_cycles();
            }
        }

        @chmod($path, $dirMode);
    }
}
