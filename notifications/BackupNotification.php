<?php

namespace humhub\modules\backup\notifications;

use Yii;
use humhub\modules\notification\components\BaseNotification;
use humhub\modules\backup\widgets\BackupNotificationIcon;

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
     * @var string The title of the notification
     */
    public $title;

    /**
     * @var string The message content
     */
    public $message;

    /**
     * @inheritdoc
     */
    public function html()
    {
        return $this->message;
    }

    /**
     * @inheritdoc
     */
    public function getMailSubject()
    {
        return $this->title;
    }

    /**
     * @inheritdoc
     */
    public function getUrl()
    {
        return ['/backup/admin/index'];
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
    public function getTitle()
    {
        if (!empty($this->title)) {
            return $this->title;
        }

        return Yii::t('BackupModule.base', 'Backup Notification');
    }

    /**
     * @inheritdoc
     */
    public function getAsHtml()
    {
        if (!empty($this->message)) {
            return $this->message;
        }

        return Yii::t('BackupModule.base', 'A backup operation has been completed.');
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
