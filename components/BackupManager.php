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
                'theme' => $this->settings->backupTheme && !empty($this->settings->themeName),
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
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);

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

        usort($backups, function ($a, $b) {
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
        $keep = (int) $this->settings->keepBackups;

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
     * Backup the database and add it to the zip archive
     *
     * @param ZipArchive $zip
     * @return bool Success status
     */
    private function backupDatabase($zip)
    {
        try {
            $db = Yii::$app->db;
            $dumpFileInfo = $this->createMysqlDump();

            if ($dumpFileInfo === false) {
                Yii::warning("MySQL dump creation failed, falling back to schema-only backup", 'backup');
                return $this->backupDatabaseSchemaOnly($zip);
            }

            list($dumpFile, $isSchemaOnly) = $dumpFileInfo;

            if (!file_exists($dumpFile) || !is_readable($dumpFile)) {
                Yii::error("Database dump file not accessible: $dumpFile", 'backup');
                @unlink($dumpFile);
                return $this->backupDatabaseSchemaOnly($zip);
            }

            Yii::info("Adding database dump file to backup: $dumpFile", 'backup');

            $fileContents = file_get_contents($dumpFile);
            if ($fileContents === false) {
                Yii::error("Failed to read database dump file", 'backup');
                @unlink($dumpFile);
                return false;
            }

            $addResult = $zip->addFromString('database/db_dump.sql', $fileContents);

            @unlink($dumpFile);

            if (!$addResult) {
                Yii::error("Failed to add database dump to ZIP", 'backup');
                return false;
            }

            $dbConfig = $db->getSchema()->defaultSchema;
            $dbInfo = [
                'driver' => $db->driverName,
                'host' => $this->getDsnParameter($db->dsn, 'host', 'localhost'),
                'database' => $dbConfig,
                'backup_method' => $isSchemaOnly ? 'schema_only' : 'mysqldump',
                'backup_time' => date('Y-m-d H:i:s'),
                'version' => $db->getServerVersion(),
            ];

            $zip->addFromString('database/db_info.json', json_encode($dbInfo, JSON_PRETTY_PRINT));

            return true;
        } catch (\Exception $e) {
            Yii::error("Error in backupDatabase: " . $e->getMessage(), 'backup');
            if (isset($dumpFile) && file_exists($dumpFile)) {
                @unlink($dumpFile);
            }
            return false;
        }
    }

    /**
     * Fall back to schema-only backup when full dump fails
     *
     * @param ZipArchive $zip
     * @return bool Success status
     */
    private function backupDatabaseSchemaOnly($zip)
    {
        try {
            $db = Yii::$app->db;
            $dbConfig = $db->getSchema()->defaultSchema;
            $schema = $db->schema;
            $tables = $schema->getTableNames();
            $sql = [];

            $sql[] = "-- HumHub Database Backup (SCHEMA ONLY - NO DATA)";
            $sql[] = "-- Generated: " . date('Y-m-d H:i:s');
            $sql[] = "-- HumHub Version: " . Yii::$app->version;
            $sql[] = "-- WARNING: This is a fallback backup containing only table schemas without data.";
            $sql[] = "-- It's recommended to use mysqldump for full database backups.";
            $sql[] = "";

            foreach ($tables as $table) {
                $createTableSql = $this->getCreateTableSql($table);
                if ($createTableSql) {
                    $sql[] = "-- Table structure for table `$table`";
                    $sql[] = $createTableSql . "\n";
                }
            }

            $zip->addFromString('database/db_schema.sql', implode("\n", $sql));

            $dbInfo = [
                'driver' => $db->driverName,
                'host' => $this->getDsnParameter($db->dsn, 'host', 'localhost'),
                'database' => $dbConfig,
                'backup_method' => 'schema_only',
                'note' => 'Only schema was backed up. Data needs to be migrated separately.',
                'backup_time' => date('Y-m-d H:i:s'),
                'version' => $db->getServerVersion(),
            ];

            $zip->addFromString('database/db_info.json', json_encode($dbInfo, JSON_PRETTY_PRINT));

            return true;
        } catch (\Exception $e) {
            Yii::error("Error in backupDatabaseSchemaOnly: " . $e->getMessage(), 'backup');
            return false;
        }
    }

    /**
     * Try to create a MySQL dump using the mysqldump command
     *
     * @return array|false [dump_file_path, is_schema_only] or false on failure
     */
    private function createMysqlDump()
    {
        if (!$this->validateDatabaseDriver()) {
            return false;
        }

        try {
            $db = Yii::$app->db;
            $backupDir = $this->ensureBackupDirectoryExists();

            if ($backupDir === false) {
                return false;
            }

            $tempFile = $backupDir . DIRECTORY_SEPARATOR . 'temp_db_dump_' . uniqid() . '.sql';

            $mysqldumpPath = $this->findMysqldumpPath();
            if ($mysqldumpPath === false) {
                return false;
            }

            $host = $this->getDsnParameter($db->dsn, 'host', 'localhost');
            $port = $this->getDsnParameter($db->dsn, 'port', null);
            $dbName = $this->getDsnParameter($db->dsn, 'dbname');

            if (empty($dbName)) {
                Yii::error("Could not extract database name from DSN", 'backup');
                return false;
            }

            if ($this->dumpWithConfigFile($mysqldumpPath, $tempFile, $backupDir, $host, $port, $dbName, $db->username, $db->password)) {
                return [$tempFile, false]; // Full dump
            }

            if ($this->dumpWithDirectCommand($mysqldumpPath, $tempFile, $host, $port, $dbName, $db->username, $db->password)) {
                return [$tempFile, false]; // Full dump
            }

            if ($this->dumpSchemaOnly($mysqldumpPath, $tempFile, $host, $port, $dbName, $db->username, $db->password)) {
                return [$tempFile, true]; // Schema-only dump
            }

            return false;
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
     * Validate if the database driver is MySQL
     *
     * @return bool
     */
    private function validateDatabaseDriver()
    {
        $db = Yii::$app->db;
        if ($db->driverName !== 'mysql') {
            Yii::info("Database is not MySQL, can't use mysqldump", 'backup');
            return false;
        }
        return true;
    }

    /**
     * Ensure the backup directory exists and is writable
     *
     * @return string|false Path to backup directory or false on failure
     */
    private function ensureBackupDirectoryExists()
    {
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

        return $backupDir;
    }

    /**
     * Find the mysqldump executable path
     *
     * @return string|false Path to mysqldump or false if not found
     */
    private function findMysqldumpPath()
    {
        $command = 'which mysqldump 2>/dev/null';
        $mysqldumpPath = exec($command, $output, $returnVar);

        if ($returnVar === 0 && !empty($mysqldumpPath) && is_executable($mysqldumpPath)) {
            return $mysqldumpPath;
        }

        $commonPaths = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/local/bin/mysqldump',
            '/opt/local/lib/mysql/bin/mysqldump',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp\\bin\\mysql\\mysql5.7.26\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql5.7.26\\bin\\mysqldump.exe',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        Yii::error("mysqldump command not found", 'backup');
        return false;
    }

    /**
     * Extract parameter from DSN string
     *
     * @param string $dsn
     * @param string $paramName
     * @param mixed $default
     * @return string|null
     */
    private function getDsnParameter($dsn, $paramName, $default = null)
    {
        if (preg_match("/$paramName=([^;]+)/", $dsn, $matches)) {
            return $matches[1];
        }
        return $default;
    }

    /**
     * Attempt to dump using config file method
     *
     * @param string $mysqldumpPath
     * @param string $tempFile
     * @param string $backupDir
     * @param string $host
     * @param string|null $port
     * @param string $dbName
     * @param string $user
     * @param string $password
     * @return bool
     */
    private function dumpWithConfigFile($mysqldumpPath, $tempFile, $backupDir, $host, $port, $dbName, $user, $password)
    {
        $configFile = $backupDir . DIRECTORY_SEPARATOR . 'temp_my_' . uniqid() . '.cnf';

        try {
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
            $command .= "--add-drop-table --opt --single-transaction --skip-lock-tables ";
            $command .= "--routines --triggers --events ";
            $command .= escapeshellarg($dbName) . " > " . escapeshellarg($tempFile) . " 2>/dev/null";

            Yii::info("Executing mysqldump command with config file", 'backup');
            exec($command, $output, $returnVar);

            @unlink($configFile);

            if ($returnVar === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                return true;
            }

            Yii::error("mysqldump command failed with code: $returnVar", 'backup');
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return false;
        } catch (\Exception $e) {
            Yii::error("Error in dumpWithConfigFile: " . $e->getMessage(), 'backup');
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
    }

    /**
     * Attempt to dump using direct command method
     *
     * @param string $mysqldumpPath
     * @param string $tempFile
     * @param string $host
     * @param string|null $port
     * @param string $dbName
     * @param string $user
     * @param string $password
     * @return bool
     */
    private function dumpWithDirectCommand($mysqldumpPath, $tempFile, $host, $port, $dbName, $user, $password)
    {
        try {
            $command = escapeshellarg($mysqldumpPath) . " --add-drop-table --opt --single-transaction --skip-lock-tables ";
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

            Yii::info("Executing mysqldump command directly", 'backup');
            exec($command, $output, $returnVar);

            if ($returnVar === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                return true;
            }

            Yii::error("Direct mysqldump command failed with code: $returnVar", 'backup');
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return false;
        } catch (\Exception $e) {
            Yii::error("Error in dumpWithDirectCommand: " . $e->getMessage(), 'backup');
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
    }

    /**
     * Attempt to dump schema only
     *
     * @param string $mysqldumpPath
     * @param string $tempFile
     * @param string $host
     * @param string|null $port
     * @param string $dbName
     * @param string $user
     * @param string $password
     * @return bool
     */
    private function dumpSchemaOnly($mysqldumpPath, $tempFile, $host, $port, $dbName, $user, $password)
    {
        try {
            $command = escapeshellarg($mysqldumpPath) . " --no-data --add-drop-table ";
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

            Yii::info("Executing schema-only mysqldump command", 'backup');
            exec($command, $output, $returnVar);

            if ($returnVar === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                return true;
            }

            Yii::error("Schema-only mysqldump command failed with code: $returnVar", 'backup');
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }

            return false;
        } catch (\Exception $e) {
            Yii::error("Error in dumpSchemaOnly: " . $e->getMessage(), 'backup');
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
    }

    /**
     * Restore database from SQL dump file
     *
     * @param string $sqlDumpPath Path to the SQL dump file
     * @return bool Success status
     */
    public function restoreDatabase($sqlDumpPath)
    {
        if (!$this->validateDatabaseDriver()) {
            return false;
        }

        if (!$this->validateDumpFile($sqlDumpPath)) {
            return false;
        }

        try {
            $db = Yii::$app->db;

            $host = $this->getDsnParameter($db->dsn, 'host', 'localhost');
            $port = $this->getDsnParameter($db->dsn, 'port');
            $dbName = $this->getDsnParameter($db->dsn, 'dbname');

            if (empty($dbName)) {
                Yii::error("Could not extract database name from DSN", 'backup');
                return false;
            }

            $mysqlPath = $this->findMysqlClientPath();
            if (!$mysqlPath) {
                return false;
            }

            $backupDir = $this->getBackupDirectory();
            if ($this->restoreWithConfigFile($mysqlPath, $sqlDumpPath, $backupDir, $host, $port, $dbName, $db->username, $db->password)) {
                return true;
            }

            if ($this->restoreWithDirectCommand($mysqlPath, $sqlDumpPath, $host, $port, $dbName, $db->username, $db->password)) {
                return true;
            }

            return $this->restoreWithPdo($sqlDumpPath, $db);
        } catch (\Exception $e) {
            Yii::error("Error in restoreDatabase: " . $e->getMessage(), 'backup');
            return false;
        }
    }

    /**
     * Validate SQL dump file
     *
     * @param string $sqlDumpPath
     * @return bool
     */
    private function validateDumpFile($sqlDumpPath)
    {
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

        return true;
    }

    /**
     * Find the mysql client executable path
     *
     * @return string|false Path to mysql client or false if not found
     */
    private function findMysqlClientPath()
    {
        $command = 'which mysql 2>/dev/null';
        $mysqlPath = exec($command, $output, $returnVar);

        if ($returnVar === 0 && !empty($mysqlPath) && is_executable($mysqlPath)) {
            return $mysqlPath;
        }

        $commonPaths = [
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            '/opt/local/bin/mysql',
            '/opt/local/lib/mysql/bin/mysql',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\wamp\\bin\\mysql\\mysql5.7.26\\bin\\mysql.exe',
            'C:\\wamp64\\bin\\mysql\\mysql5.7.26\\bin\\mysql.exe',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        Yii::error("mysql client command not found", 'backup');
        return false;
    }

    /**
     * Restore database using config file method
     *
     * @param string $mysqlPath
     * @param string $sqlDumpPath
     * @param string $backupDir
     * @param string $host
     * @param string|null $port
     * @param string $dbName
     * @param string $user
     * @param string $password
     * @return bool
     */
    private function restoreWithConfigFile($mysqlPath, $sqlDumpPath, $backupDir, $host, $port, $dbName, $user, $password)
    {
        $configFile = $backupDir . DIRECTORY_SEPARATOR . 'temp_my_restore_' . uniqid() . '.cnf';

        try {
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
                Yii::error("Could not create temporary MySQL config file for restore", 'backup');
                return false;
            }

            chmod($configFile, 0600);

            $command = escapeshellarg($mysqlPath) . " --defaults-file=" . escapeshellarg($configFile) . " ";
            $command .= escapeshellarg($dbName) . " < " . escapeshellarg($sqlDumpPath) . " 2>/dev/null";

            Yii::info("Executing mysql restore command with config file", 'backup');
            exec($command, $output, $returnVar);

            @unlink($configFile);

            return $returnVar === 0;
        } catch (\Exception $e) {
            Yii::error("Error in restoreWithConfigFile: " . $e->getMessage(), 'backup');
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            return false;
        }
    }

    /**
     * Restore database using direct command method
     *
     * @param string $mysqlPath
     * @param string $sqlDumpPath
     * @param string $host
     * @param string|null $port
     * @param string $dbName
     * @param string $user
     * @param string $password
     * @return bool
     */
    private function restoreWithDirectCommand($mysqlPath, $sqlDumpPath, $host, $port, $dbName, $user, $password)
    {
        try {
            $command = escapeshellarg($mysqlPath) . " ";
            $command .= "-h " . escapeshellarg($host) . " ";
            if ($port) {
                $command .= "-P " . escapeshellarg($port) . " ";
            }
            $command .= "-u " . escapeshellarg($user) . " ";

            if (!empty($password)) {
                $command = "MYSQL_PWD=" . escapeshellarg($password) . " " . $command;
            }

            $command .= escapeshellarg($dbName) . " < " . escapeshellarg($sqlDumpPath) . " 2>/dev/null";

            Yii::info("Executing mysql restore command directly", 'backup');
            exec($command, $output, $returnVar);

            return $returnVar === 0;
        } catch (\Exception $e) {
            Yii::error("Error in restoreWithDirectCommand: " . $e->getMessage(), 'backup');
            return false;
        }
    }

    /**
     * Last resort - restore using PDO in PHP
     * This will be much slower but might work when shell access fails
     *
     * @param string $sqlDumpPath
     * @param \yii\db\Connection $db
     * @return bool
     */
    private function restoreWithPdo($sqlDumpPath, $db)
    {
        try {
            Yii::info("Attempting to restore database using PDO", 'backup');

            $sql = file_get_contents($sqlDumpPath);
            if ($sql === false) {
                Yii::error("Failed to read SQL file content", 'backup');
                return false;
            }
            $statements = array_filter(array_map('trim', explode(';', $sql)), function ($stmt) {
                return !empty($stmt);
            });

            $success = true;
            $transaction = $db->beginTransaction();

            try {
                foreach ($statements as $statement) {
                    $success = $db->createCommand($statement)->execute() !== false && $success;
                }

                if ($success) {
                    $transaction->commit();
                    Yii::info("Database restored successfully using PDO", 'backup');
                } else {
                    $transaction->rollBack();
                    Yii::error("Failed to execute one or more SQL statements", 'backup');
                }
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::error("PDO restore failed: " . $e->getMessage(), 'backup');
                return false;
            }

            return $success;
        } catch (\Exception $e) {
            Yii::error("Error in restoreWithPdo: " . $e->getMessage(), 'backup');
            return false;
        }
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
