<?php

namespace humhub\modules\backup;

use Yii;
use yii\helpers\Url;
use yii\helpers\Html;
use humhub\components\Module as BaseModule;
use humhub\modules\backup\widgets\BackupAdminMenu;
use humhub\modules\admin\permissions\ManageModules;

/**
 * Backup Module for HumHub
 * 
 * @author ArchBlood
 * @package humhub\modules\backup
 */
class Module extends BaseModule
{
    /**
    * @inheritdoc
    */
    public $resourcesPath = 'resources';

    /**
     * @inheritdoc
     */
    public function getConfigUrl()
    {
        return Url::to(['/backup/admin/index']);
    }

    /**
     * @inheritdoc
     */
    public function getPermissions($contentContainer = null)
    {
        if ($contentContainer === null) {
            return [
                new ManageModules(),
            ];
        }

        return [];
    }

    /**
     * @inheritdoc
     */
    public function disable()
    {
        $this->publishAssets(true);

        parent::disable();
    }

    /**
     * @inheritdoc
     */
    public function enable()
    {
        parent::enable();

        $settings = new models\ConfigureForm();
        $settings->load([]);
        $settings->save();
    }
}