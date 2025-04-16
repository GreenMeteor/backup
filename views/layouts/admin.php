<?php

use humhub\modules\admin\widgets\AdminMenu;
use humhub\widgets\TopMenu;

/** @var $content string */
/** @var $this \humhub\modules\ui\view\components\View */

$this->beginContent('@admin/views/layouts/main.php');
?>

<div class="container">
    <div class="row">
        <div class="col-md-3">
            <?= AdminMenu::widget(); ?>
        </div>
        <div class="col-md-9">
            <?= $content; ?>
        </div>
    </div>
</div>

<?php $this->endContent(); ?>