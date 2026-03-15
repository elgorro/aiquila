<?php
$appId = OCA\AIquila\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-main');
\OCP\Util::addStyle($appId, 'chat');
?>

<div id="content"></div>
