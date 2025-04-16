<?php

namespace humhub\modules\backup\notifications;

use Yii;
use yii\helpers\Url;
use humhub\modules\backup\widgets\BackupNotificationIcon;
use humhub\modules\notification\components\BaseNotification;
use humhub\modules\admin\notifications\AdminNotificationCategory;

/**
 * BackupNotification
 * 
 * This notification is sent after a backup operation is completed or failed
 */
class BackupNotification extends BaseNotification
{
    /**
     * @inheritdoc
     */
    public $moduleId = 'backup';

    /**
     * @inheritdoc
     */
    public $viewName = 'backup_notification';

    /**
     * @inheritdoc
     */
    public $requireOriginator = false;

    /**
     * @inheritdoc
     */
    public $requireSource = false;

    /**
     * @inheritdoc
     */
    public function getUrl()
    {
        return Url::to(['/backup/admin/index']);
    }

    /**
     * @inheritdoc
     */
    public function getIcon()
    {
        return BackupNotificationIcon::getByType($this->getTitle());
    }

    /**
     * @inheritdoc
     */
    public function category()
    {
        return new AdminNotificationCategory();
    }

    /**
     * @inheritdoc
     */
    public function html()
    {
        return Yii::t('BackupModule.notification', "Backup is  available.");
    }

    /**
     * @inheritdoc
     */
    public function send(\humhub\modules\user\models\User $user)
    {
        if (!$user->can('humhub\modules\admin\permissions\ManageModules')) {
            return;
        }

        return parent::send($user);
    }
}
