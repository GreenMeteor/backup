# Backup Module for HumHub
> [!WARNING]
> 🚧 This module is under active development and should not be used in production till stated otherwise. 🚧

The **Backup Module** by Green Meteor provides automated backup functionality for HumHub installations. It allows site administrators to generate full backups of critical platform components including the database, `/protected/modules`, `/uploads`, `/themes`, and `/protected/config`.

## Features

- 🔁 Scheduled or manual backups through admin UI
- 🗃️ Backups may include: database, config, uploads, modules, and active theme
- 📦 Compressed ZIP output with a metadata manifest (`backup-info.json`)
- 🔧 Admin-configurable options with retention limits
- 🧹 Manual cleanup of older backups

## Usage
This module is intended to be installed like any other HumHub module. After enabling it from the admin panel, backups can be triggered manually or automatically.

### Triggering from a Custom Module Controller
If you want to trigger a backup from your own module (does not fit all cases), the recommended approach is to use a controller action:

```php
<?php

namespace humhub\modules\yourModule\controllers;

use Yii;
use humhub\modules\backup\components\BackupManager;
use humhub\modules\admin\components\Controller;

class BackupController extends Controller
{
    public function actionIndex()
    {
        $manager = new BackupManager();

        try {
            $file = $manager->createBackup();
            $this->view->success('Backup created: {$file}');
        } catch (\Throwable $e) {
            Yii::error($e);
            $this->view->error('Backup failed: ' . $e->getMessage());
        }

        return $this->redirect(['index']);
    }
}
```
