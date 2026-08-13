<?php
/** @var array $_ */
$appId = OCA\AIquila\AppInfo\Application::APP_ID;

// The page is a Vue app; everything it renders comes from the provider settings
// schema over /api/admin/providers, so there is nothing to build server-side.
//
// Loaded as an ES module like templates/main.php: `Util::addScript` emits a
// classic <script> tag, under which the bundle's chunk imports never resolve.
$scriptUrl = \OC::$server->getURLGenerator()->linkTo($appId, 'js/dist/aiquila-admin.js')
    . '?v=' . \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appId);
\OCP\Util::addHeader('script', [
    'type' => 'module',
    'src' => $scriptUrl,
    'nonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
], '');
?>

<div id="aiquila-admin-settings"
     class="section"
     data-search-enabled="<?php echo $_['search_enabled'] ? '1' : '0'; ?>"></div>
