<?php
/** @var array $_ */
script('aiquila', 'user');
style('aiquila', 'user');
?>

<div id="aiquila-user" class="section">
    <h2>AIquila Personal Settings</h2>

    <p class="settings-hint">
        Configure your personal Claude API key. This will override the admin-configured key.
        Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
    </p>

    <form id="aiquila-user-form">
        <label for="aiquila-user-api-key">Personal Claude API Key</label>
        <input type="password"
               id="aiquila-user-api-key"
               name="api_key"
               placeholder="<?php echo $_['has_key'] ? 'Personal API key configured' : 'sk-ant-... (optional)'; ?>"
               value="">

        <button type="submit" class="primary">Save</button>
        <button type="button" id="aiquila-clear-key" class="secondary">Clear Key</button>
        <span id="aiquila-user-status"></span>
    </form>

    <p class="settings-note">
        Note: If no personal key is set, the admin-configured key will be used.
    </p>
</div>
