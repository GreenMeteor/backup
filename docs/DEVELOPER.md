# Developer Guide

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
    public function actionBackup()
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
