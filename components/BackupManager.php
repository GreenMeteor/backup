<?php

namespace humhub\modules\backup\components;

use Yii;
use ZipArchive;
use yii\helpers\FileHelper;
use humhub\modules\backup\models\ConfigureForm;

/**
 * BackupManager handles the creation and management of backups
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

        if ($this->settings->backupDatabase) {
            $this->backupDatabase($zip);
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
                'database' => $this->settings->backupDatabase,
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
     * Backup the database and add it to the zip archive
     * 
     * @param ZipArchive $zip
     */
    private function backupDatabase($zip)
    {
        $db = Yii::$app->db;
        $dbConfig = $db->getSchema()->defaultSchema;

        $dumpFile = $this->createMysqlDump();
        $backupDatabase = Yii::getAlias('@runtime/backups');

        if ($dumpFile) {
            if (file_exists($dumpFile) && is_readable($dumpFile)) {
                Yii::info("Adding database dump file to backup: $dumpFile", 'backup');
                if (!$zip->addFile($dumpFile, $this->getBackupDirectory() . '/database/db_dump.sql')) {
                    Yii::error("Failed to add database dump to ZIP", 'backup');
                }
            } else {
                Yii::error("Database dump file not accessible: $dumpFile", 'backup');
            }

            $dbInfo = [
                'driver' => $db->driverName,
                'host' => $db->dsn,
                'database' => $dbConfig,
                'backup_method' => 'mysqldump',
                'backup_time' => date('Y-m-d H:i:s')
            ];
            $zip->addFromString($this->getBackupDirectory() . 'database/db_info.json', json_encode($dbInfo, JSON_PRETTY_PRINT));

            @unlink($dumpFile);
        } else {
            Yii::warning("MySQLDump not available or failed, falling back to schema-only backup", 'backup');
            $schema = $db->schema;
            $tables = $schema->getTableNames();
            $sql = [];

            foreach ($tables as $table) {
                $tableSchema = $schema->getTableSchema($table);
                if ($tableSchema) {
                    $createTableSql = $this->getCreateTableSql($table);
                    if ($createTableSql) {
                        $sql[] = "-- Table structure for table `$table`";
                        $sql[] = $createTableSql;
                    }
                }
            }

            $sql = array_merge([
                "-- HumHub Database Backup (SCHEMA ONLY - NO DATA)",
                "-- Generated: " . date('Y-m-d H:i:s'),
                "-- HumHub Version: " . Yii::$app->version,
                "-- WARNING: This is a fallback backup containing only table schemas without data.",
                "-- It's recommended to use mysqldump for full database backups.",
                ""
            ], $sql);

            $zip->addFromString($this->getBackupDirectory() . 'database/db_schema.sql', implode("\n\n", $sql));

            $dbInfo = [
                'driver' => $db->driverName,
                'host' => $db->dsn,
                'database' => $dbConfig,
                'backup_method' => 'schema_only',
                'note' => 'Only schema was backed up. Data needs to be migrated separately.',
                'backup_time' => date('Y-m-d H:i:s')
            ];
            $zip->addFromString($this->getBackupDirectory() . 'database/db_info.json', json_encode($dbInfo, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Try to create a MySQL dump using the mysqldump command
     * 
     * @return string|false Path to the dump file or false on failure
     */
    private function createMysqlDump()
    {
        try {
            $db = Yii::$app->db;

            if ($db->driverName !== 'mysql') {
                Yii::info("Database is not MySQL, can't use mysqldump", 'backup');
                return false;
            }

            $backupDir = $this->getBackupDirectory();
            if (!is_dir($backupDir)) {
                if (!FileHelper::createDirectory($backupDir, 0775, true)) {
                    Yii::error("Could not create backup directory: $backupDir", 'backup');
                    return false;
                }
                @chmod($backupDir, 0775);
            }

            if (!is_writable($backupDir)) {
                @chmod($backupDir, 0775);
                if (!is_writable($backupDir)) {
                    Yii::error("Backup directory is not writable: $backupDir", 'backup');
                    return false;
                }
            }

            $tempFile = $backupDir . DIRECTORY_SEPARATOR . 'temp_db_dump_' . uniqid() . '.sql';

            if (file_exists($tempFile)) {
                @chmod($tempFile, 0664);
            }

            $dsn = $db->dsn;
            $host = 'localhost';
            $port = null;
            $dbName = '';

            if (preg_match('/host=([^;]+)/', $dsn, $hostMatches)) {
                if (strpos($hostMatches[1], ':') !== false) {
                    list($host, $port) = explode(':', $hostMatches[1], 2);
                } else {
                    $host = $hostMatches[1];
                }
            }

            if (preg_match('/dbname=([^;]+)/', $dsn, $dbNameMatches)) {
                $dbName = $dbNameMatches[1];
            } else {
                Yii::error("Could not extract database name from DSN", 'backup');
                return false;
            }

            $user = $db->username;
            $password = $db->password;

            $command = 'which mysqldump 2>/dev/null';
            $mysqldumpPath = exec($command, $output, $returnVar);

            if ($returnVar !== 0 || empty($mysqldumpPath)) {
                $commonPaths = [
                    '/usr/bin/mysqldump',
                    '/usr/local/bin/mysqldump',
                    '/usr/local/mysql/bin/mysqldump',
                    '/opt/local/bin/mysqldump',
                    '/opt/local/lib/mysql/bin/mysqldump',
                    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                    'C:\\wamp\\bin\\mysql\\mysql5.7.26\\bin\\mysqldump.exe'
                ];

                foreach ($commonPaths as $path) {
                    if (file_exists($path)) {
                        $mysqldumpPath = $path;
                        break;
                    }
                }

                if (empty($mysqldumpPath)) {
                    Yii::error("mysqldump command not found", 'backup');
                    return false;
                }
            }

            Yii::warning("Using mysqldump at: $mysqldumpPath", 'backup');

            $configFile = $backupDir . DIRECTORY_SEPARATOR . 'temp_my_' . uniqid() . '.cnf';
            $configContent = "[client]\n";
            $configContent .= "host = \"$host\"\n";
            if ($port) {
                $configContent .= "port = \"$port\"\n";
            }
            $configContent .= "user = \"$user\"\n";
            if (!empty($password)) {
                $configContent .= "password = \"" . str_replace('"', '\\"', $password) . "\"\n";
            }

            if (file_put_contents($configFile, $configContent) === false) {
                Yii::error("Could not create temporary MySQL config file", 'backup');
                return false;
            }
            chmod($configFile, 0600);

            $command = escapeshellarg($mysqldumpPath) . " --defaults-file=" . escapeshellarg($configFile) . " ";
            $command .= "--opt --single-transaction --skip-lock-tables --routines --triggers --events ";
            $command .= escapeshellarg($dbName) . " > " . escapeshellarg($tempFile) . " 2>/dev/null";

            Yii::info("Executing mysqldump command", 'backup');
            exec($command, $output, $returnVar);

            @unlink($configFile);

            if ($returnVar !== 0 || !file_exists($tempFile) || filesize($tempFile) === 0) {
                Yii::error("mysqldump command failed with code: $returnVar", 'backup');
                @unlink($tempFile);

                $command = escapeshellarg($mysqldumpPath) . " --opt --single-transaction --skip-lock-tables ";
                $command .= "--routines --triggers --events ";
                $command .= "-h " . escapeshellarg($host) . " ";
                if ($port) {
                    $command .= "-P " . escapeshellarg($port) . " ";
                }
                $command .= "-u " . escapeshellarg($user) . " ";

                if (!empty($password)) {
                    $command = "MYSQL_PWD=" . escapeshellarg($password) . " " . $command;
                }

                $command .= escapeshellarg($dbName) . " > " . escapeshellarg($tempFile) . " 2>/dev/null";

                exec($command, $output, $returnVar);

                if ($returnVar !== 0 || !file_exists($tempFile) || filesize($tempFile) === 0) {
                    Yii::error("Second attempt at mysqldump also failed with code: $returnVar", 'backup');
                    @unlink($tempFile);
                    return false;
                }
            }

            return $tempFile;

        } catch (\Exception $e) {
            Yii::error("Error in createMysqlDump: " . $e->getMessage(), 'backup');
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if (isset($configFile) && file_exists($configFile)) {
                @unlink($configFile);
            }
            return false;
        }
    }

    /**
     * Get CREATE TABLE SQL for a table
     * 
     * @param string $tableName
     * @return string|false
     */
    private function getCreateTableSql($tableName)
    {
        try {
            $result = Yii::$app->db->createCommand("SHOW CREATE TABLE " . Yii::$app->db->quoteTableName($tableName))->queryOne();
            if (isset($result['Create Table'])) {
                return $result['Create Table'] . ';';
            }
        } catch (\Exception $e) {
            Yii::error("Error getting CREATE TABLE SQL for $tableName: " . $e->getMessage(), 'backup');
        }

        return false;
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

        if (isset($metadata['backup_components']['database']) && $metadata['backup_components']['database']) {
            $dbRestored = false;

            if (file_exists($tempDir . '/database/db_dump.sql')) {
                $dbRestored = $this->restoreDatabase($tempDir . '/database/db_dump.sql');
                if (!$dbRestored) {
                    $errors[] = 'Failed to restore database';
                }
            } else {
                $errors[] = 'Database backup not found in archive';
            }
        }

        FileHelper::removeDirectory($tempDir);

        if (empty($errors)) {
            return true;
        }

        return $errors;
    }

    private function restoreDatabase($sqlDumpPath)
    {
        $db = Yii::$app->db;

        if ($db->driverName !== 'mysql') {
            return false;
        }

        if (!file_exists($sqlDumpPath) || !is_file($sqlDumpPath)) {
            Yii::error("SQL dump file does not exist: $sqlDumpPath", 'backup');
            return false;
        }

        if (!is_readable($sqlDumpPath)) {
            @chmod($sqlDumpPath, 0664);
            if (!is_readable($sqlDumpPath)) {
                Yii::error("SQL dump file is not readable: $sqlDumpPath", 'backup');
                return false;
            }
        }

        $dsn = $db->dsn;
        preg_match('/host=([^;]*)/', $dsn, $hostMatches);
        preg_match('/dbname=([^;]*)/', $dsn, $dbNameMatches);

        if (empty($hostMatches[1]) || empty($dbNameMatches[1])) {
            return false;
        }

        $host = $hostMatches[1];
        $dbName = $dbNameMatches[1];
        $user = $db->username;
        $password = $db->password;

        $command = 'which mysql 2>/dev/null';
        $mysqlPath = exec($command, $output, $returnVar);

        if ($returnVar !== 0 || empty($mysqlPath)) {
            return false;
        }

        $command = "$mysqlPath ";

        $command .= "-h " . escapeshellarg($host) . " ";
        $command .= "-u " . escapeshellarg($user) . " ";

        if (!empty($password)) {
            $command .= "-p" . escapeshellarg($password) . " ";
        }

        $command .= escapeshellarg($dbName) . " < " . escapeshellarg($sqlDumpPath) . " 2>/dev/null";

        exec($command, $output, $returnVar);

        return ($returnVar === 0);
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
