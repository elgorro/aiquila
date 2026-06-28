<?php
$appId = OCA\AIquila\AppInfo\Application::APP_ID;

// Load the Vite entry as an ES module so its emitted chunk imports work.
// `Util::addScript` emits a classic <script> tag and cannot load modules.
$scriptUrl = \OC::$server->getURLGenerator()->linkTo($appId, 'js/dist/aiquila-main.js')
    . '?v=' . \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appId);
\OCP\Util::addHeader('script', [
    'type' => 'module',
    'src' => $scriptUrl,
    'nonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
], '');
?>

<div id="aiquila-app"></div>
