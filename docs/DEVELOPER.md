# Directory Structure and Usage Guide
### Usage Examples
#### Basic Usage
```php
// In your controller or service
use humhub\modules\backup\components\BackupManager;

$backupManager = new BackupManager();

// Create a full backup (all types)
$success = $backupManager->createBackup();

// Create a specific backup type
$success = $backupManager->createBackup('database');

// Get list of all backups
$backups = $backupManager->getBackups();

// Delete a backup
$success = $backupManager->deleteBackup('2025-05-14_12-30-45');
```
### Advanced Usage
If you need more control, you can use the backup classes directly:
```php
use humhub\modules\backup\components\DatabaseBackup;
use humhub\modules\backup\factories\BackupFactory;

// Method 1: Create directly
$dbBackup = new DatabaseBackup();
$dbBackup->mysqldumpPath = '/usr/bin/mysqldump'; // Custom path
$dbBackup->init();
$success = $dbBackup->execute();

// Method 2: Use the factory
$backup = BackupFactory::create('database');
$backup->init();
$success = $backup->execute();
```

```php
// Creating a custom backup implementation
class CustomBackup extends BaseBackup
{
    public function execute()
    {
        $timestampedDir = $this->createTimestampedBackupDir();
        // Custom backup logic here
        return true;
    }
}
```
```php
// Then extend the factory to support your custom type
class ExtendedBackupFactory extends BackupFactory
{
    public static function create($type)
    {
        if ($type === 'custom') {
            return new CustomBackup();
        }
        
        return parent::create($type);
    }
}
```
### Configuration in `Module.php`
```php
// Not required but won't hurt
public function configure()
{
    // Configure the backup manager
    Yii::$app->set('backupManager', [
        'class' => 'humhub\modules\backup\components\BackupManager',
        'defaultType' => 'full',
        'mysqldumpPath' => '/usr/bin/mysqldump',
    ]);
}
