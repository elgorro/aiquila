<?php
/** @var array $_ */
script('aiquila', 'admin');
style('aiquila', 'admin');
?>

<div id="aiquila-admin" class="section">
    <h2>AIquila Settings</h2>

    <p class="settings-hint">
        Configure Claude AI integration. Get your API key from
        <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
    </p>

    <form id="aiquila-admin-form">
        <label for="aiquila-api-key">Claude API Key</label>
        <input type="password"
               id="aiquila-api-key"
               name="api_key"
               placeholder="<?php echo $_['has_key'] ? 'API key configured' : 'sk-ant-...'; ?>"
               value="">

        <button type="submit" class="primary">Save</button>
        <span id="aiquila-status"></span>
    </form>
</div>
