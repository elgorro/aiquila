<?php
/** @var array $_ */
script('nextclaude', 'admin');
style('nextclaude', 'admin');
?>

<div id="nextclaude-admin" class="section">
    <h2>NextClaude Settings</h2>

    <p class="settings-hint">
        Configure Claude AI integration. Get your API key from
        <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
    </p>

    <form id="nextclaude-admin-form">
        <label for="nextclaude-api-key">Claude API Key</label>
        <input type="password"
               id="nextclaude-api-key"
               name="api_key"
               placeholder="<?php echo $_['has_key'] ? 'API key configured' : 'sk-ant-...'; ?>"
               value="">

        <button type="submit" class="primary">Save</button>
        <span id="nextclaude-status"></span>
    </form>
</div>
