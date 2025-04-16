<?php
// Events.php

namespace humhub\modules\backup;

use Yii;
use yii\base\Event;
use humhub\modules\admin\widgets\AdminMenu;
use humhub\modules\admin\permissions\ManageModules;

/**
 * Events provides callbacks for all relevant events.
 */
class Events
{
    /**
     * Handle the event when admin menu is initialized
     * 
     * @param Event $event
     */
    /*public static function onAdminMenuInit($event)
    {
        if (!Yii::$app->user->can(ManageModules::class)) {
            return;
        }
        
        $event->sender->addItem([
            'label' => Yii::t('BackupModule.base', 'Backup'),
            'url' => ['/backup/admin/index'],
            'group' => 'settings',
            'icon' => '<i class="fa fa-archive"></i>',
            'isActive' => Yii::$app->controller->module && Yii::$app->controller->module->id == 'backup',
            'sortOrder' => 650,
        ]);
    }*/
}