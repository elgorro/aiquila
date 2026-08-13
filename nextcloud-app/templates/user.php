<?php
/** @var array $_ */
$appId = OCA\AIquila\AppInfo\Application::APP_ID;

// See templates/admin.php: the bundle is an ES module, so it needs a
// type="module" tag rather than Util::addScript's classic one.
$scriptUrl = \OC::$server->getURLGenerator()->linkTo($appId, 'js/dist/aiquila-personal.js')
    . '?v=' . \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appId);
\OCP\Util::addHeader('script', [
    'type' => 'module',
    'src' => $scriptUrl,
    'nonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
], '');
?>

<div id="aiquila-personal-settings" class="section"></div>
