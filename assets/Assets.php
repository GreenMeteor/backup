<?php

namespace humhub\modules\backup\assets;

use Yii;
use yii\web\AssetBundle;

/**
 * Backup related assets.
 *
 * @author ArchBlood
 */
class Assets extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = '@backup/resources';

    /**
     * @inheritdoc
     */
    public $publishOptions = ['forceCopy' => false];

    public $css = [
        'css/backup.css'
    ];
}