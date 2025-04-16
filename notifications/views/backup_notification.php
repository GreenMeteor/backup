<?php

use yii\helpers\Html;
use humhub\modules\backup\widgets\BackupNotificationIcon;

/* @var $notification \humhub\modules\backup\notifications\BackupNotification */
/* @var $source \humhub\modules\backup\jobs\BackupJob */
?>

<div class="media">
    <div class="media-left">
        <div class="media-object img-rounded">
            <?= BackupNotificationIcon::getByType($notification->getTitle()); ?>
        </div>
    </div>
    <div class="media-body">
        <strong><?= Html::encode($notification->getTitle()); ?></strong><br>
        <span class="text-break"><?= $notification->html(); ?></span>
    </div>
</div>
