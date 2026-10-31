<?php
/** @var array $_ */
\OCA\AIquila\Template\ViteAssets::load('aiquila-admin');
?>

<div id="aiquila-admin-settings"
     class="section"
     data-search-enabled="<?php echo $_['search_enabled'] ? '1' : '0'; ?>"
     data-auto-cache="<?php echo $_['auto_cache'] ? '1' : '0'; ?>"
     data-files-api="<?php echo $_['files_api'] ? '1' : '0'; ?>"></div>
