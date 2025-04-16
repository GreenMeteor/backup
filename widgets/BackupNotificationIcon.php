<?php

namespace humhub\modules\backup\widgets;

use Yii;
use humhub\components\Widget;
use humhub\libs\Html;

/**
 * BackupNotificationIcon
 * 
 * Widget to display the appropriate icon for backup notifications
 */
class BackupNotificationIcon extends Widget
{
    /**
     * @var string The title of the notification (used to determine icon type)
     */
    public $title;

    /**
     * @inheritdoc
     */
    public function run()
    {
        return $this->renderIcon($this->title);
    }

    /**
     * Returns the appropriate icon based on the notification title
     * 
     * @param string $title The notification title
     * @return string The HTML markup for the icon
     */
    public static function getByType($title)
    {
        $widget = new self(['title' => $title]);
        return $widget->run();
    }

    /**
     * Renders the appropriate icon based on the notification title
     * 
     * @param string $title The notification title
     * @return string The HTML markup for the icon
     */
    protected function renderIcon($title)
    {
        if ($title && strpos($title, Yii::t('BackupModule.base', 'Backup Failed')) !== false) {
            return Html::tag('i', '', ['class' => 'fa fa-exclamation-circle', 'style' => 'color: #EB4431']);
        }

        if ($title && strpos($title, Yii::t('BackupModule.base', 'Backup Completed')) !== false) {
            return Html::tag('i', '', ['class' => 'fa fa-check-circle', 'style' => 'color: #5cb85c']);
        }

        return Html::tag('i', '', ['class' => 'fa fa-archive']);
    }
}
