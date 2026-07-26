{**
 * FOTOhub AI — Configuration Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-8">
            <h2><i class="icon-camera"></i> FOTOhub AI — {l s='Configuration' mod='fotohubai'}</h2>
            <p class="text-muted">
                {l s='Generate AI product photos, remove backgrounds, and bulk-process your catalog.' mod='fotohubai'}
            </p>
        </div>
        <div class="col-lg-4 text-right">
            {* Credits meter widget (refreshed via AJAX) *}
            <div class="fotohub-credits-meter panel" id="fotohubCreditsMeter">
                <i class="icon-money"></i>
                <strong>{l s='Credits' mod='fotohubai'}:</strong>
                <span id="fotohubCreditsValue">
                    {if $fotohub_credits !== null}{$fotohub_credits|floatval|string_format:"%.1f"}{else}&mdash;{/if}
                </span>
                {if $fotohub_plan}
                    <span class="badge">{$fotohub_plan|escape:'htmlall':'UTF-8'}</span>
                {/if}
                <button type="button" class="btn btn-link btn-xs" id="fotohubRefreshCredits" title="{l s='Refresh' mod='fotohubai'}">
                    <i class="icon-refresh"></i>
                </button>
            </div>
            {if $fotohub_low_balance}
                <div class="alert alert-warning" id="fotohubLowBalance">
                    <i class="icon-warning"></i>
                    {l s='Low balance: fewer than 50 credits remaining. Top up at fotohub.app/dashboard to avoid interrupted jobs.' mod='fotohubai'}
                </div>
            {/if}
        </div>
    </div>
</div>

{* Messages can embed an upstream API error string, so they are escaped. The
   only exception is the API-key warning, which carries a deliberate link and is
   built from $this->l() output in the controller. *}
{if isset($confirmations) && $confirmations|@count > 0}
    {foreach $confirmations as $confirmation}
        <div class="alert alert-success" role="status">{$confirmation|escape:'html':'UTF-8'}</div>
    {/foreach}
{/if}

{if isset($warnings) && $warnings|@count > 0}
    {foreach $warnings as $warning}
        <div class="alert alert-warning" role="status">{$warning|escape:'html':'UTF-8'}</div>
    {/foreach}
{/if}

{if isset($errors) && $errors|@count > 0}
    {foreach $errors as $error}
        <div class="alert alert-danger" role="alert">{$error|escape:'html':'UTF-8'}</div>
    {/foreach}
{/if}

{* Nav tabs: Settings / MCP assistant *}
<ul class="nav nav-tabs" id="fotohubConfigTabs">
    <li class="active"><a href="#fotohub-tab-settings" data-toggle="tab">{l s='Settings' mod='fotohubai'}</a></li>
    <li><a href="#fotohub-tab-mcp" data-toggle="tab">{l s='AI Assistant (MCP)' mod='fotohubai'}</a></li>
</ul>

<div class="tab-content">

<div class="tab-pane active" id="fotohub-tab-settings">

<div class="panel">
    <div class="panel-heading">
        <i class="icon-key"></i> {l s='Connection Wizard' mod='fotohubai'}
        {if $fotohub_configured}
            <span class="badge badge-success">{l s='API key configured' mod='fotohubai'}</span>
        {/if}
        {if $fotohub_connection_id}
            <span class="badge badge-info" title="{$fotohub_connection_id|escape:'htmlall':'UTF-8'}">
                {l s='Bridge connected' mod='fotohubai'}
            </span>
        {elseif $fotohub_configured}
            <span class="badge badge-warning">{l s='Bridge not registered — re-save your API key' mod='fotohubai'}</span>
        {/if}
    </div>
    <form method="post" action="{$fotohub_config_url|escape:'htmlall':'UTF-8'}" class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3" for="FOTOHUBAI_API_KEY">
                {l s='API Key' mod='fotohubai'}
            </label>
            <div class="col-lg-6">
                <div class="input-group">
                    <input type="password"
                           id="FOTOHUBAI_API_KEY"
                           name="FOTOHUBAI_API_KEY"
                           class="form-control"
                           value="{if $fotohub_api_key_set}••••••••{/if}"
                           placeholder="{l s='Enter your FOTOhub API key (fh_live_...)' mod='fotohubai'}" />
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" id="toggleApiKey">
                            <i class="icon-eye"></i>
                        </button>
                    </span>
                </div>
                <p class="help-block">
                    {l s='Get your API key from' mod='fotohubai'}
                    <a href="https://fotohub.app/settings/api" target="_blank">fotohub.app/settings/api</a>.
                    {l s='Saving a new key validates it against your account and registers this store with the FOTOhub commerce bridge.' mod='fotohubai'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="FOTOHUBAI_DEFAULT_MODEL">
                {l s='Default Model' mod='fotohubai'}
            </label>
            <div class="col-lg-4">
                <select id="FOTOHUBAI_DEFAULT_MODEL" name="FOTOHUBAI_DEFAULT_MODEL" class="form-control">
                    {foreach $fotohub_models as $model}
                        <option value="{$model.id|escape:'htmlall':'UTF-8'}"
                                {if $model.id == $fotohub_default_model}selected="selected"{/if}>
                            {$model.name|escape:'htmlall':'UTF-8'} ({$model.credits|floatval} {l s='cr/img' mod='fotohubai'})
                        </option>
                    {/foreach}
                </select>
                <p class="help-block">
                    {l s='AI model used for image generation. SeedDream 5.0 is recommended for product photos.' mod='fotohubai'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='Default Image Size' mod='fotohubai'}
            </label>
            <div class="col-lg-2">
                <div class="input-group">
                    <input type="number"
                           id="FOTOHUBAI_DEFAULT_WIDTH"
                           name="FOTOHUBAI_DEFAULT_WIDTH"
                           class="form-control"
                           value="{$fotohub_default_width|intval}"
                           min="256" max="4096" step="64" />
                    <span class="input-group-addon">px</span>
                </div>
                <p class="help-block">{l s='Width' mod='fotohubai'}</p>
            </div>
            <div class="col-lg-2">
                <div class="input-group">
                    <input type="number"
                           id="FOTOHUBAI_DEFAULT_HEIGHT"
                           name="FOTOHUBAI_DEFAULT_HEIGHT"
                           class="form-control"
                           value="{$fotohub_default_height|intval}"
                           min="256" max="4096" step="64" />
                    <span class="input-group-addon">px</span>
                </div>
                <p class="help-block">{l s='Height' mod='fotohubai'}</p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="FOTOHUBAI_AUTO_GENERATE">
                {l s='Auto-generate on Save' mod='fotohubai'}
            </label>
            <div class="col-lg-6">
                <span class="switch prestashop-switch fixed-width-lg">
                    <input type="radio" name="FOTOHUBAI_AUTO_GENERATE" id="FOTOHUBAI_AUTO_GENERATE_on" value="1"
                           {if $fotohub_auto_generate}checked="checked"{/if} />
                    <label for="FOTOHUBAI_AUTO_GENERATE_on">{l s='Yes' mod='fotohubai'}</label>
                    <input type="radio" name="FOTOHUBAI_AUTO_GENERATE" id="FOTOHUBAI_AUTO_GENERATE_off" value="0"
                           {if !$fotohub_auto_generate}checked="checked"{/if} />
                    <label for="FOTOHUBAI_AUTO_GENERATE_off">{l s='No' mod='fotohubai'}</label>
                    <a class="slide-button btn"></a>
                </span>
                <p class="help-block">
                    {l s='Automatically generate an AI image when saving a product that has no images. Results wait in Drafts Review — nothing goes live without approval.' mod='fotohubai'}
                </p>
            </div>
        </div>

        <div class="panel-footer">
            <button type="submit" name="submitFotoHubConfig" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> {l s='Save' mod='fotohubai'}
            </button>
            <button type="submit" name="testConnection" class="btn btn-info">
                <i class="icon-plug"></i> {l s='Test Connection' mod='fotohubai'}
            </button>
            <button type="submit" name="healthCheck" class="btn btn-info">
                <i class="icon-heartbeat"></i> {l s='Health Check' mod='fotohubai'}
            </button>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-info-circle"></i> {l s='Quick Links' mod='fotohubai'}
    </div>
    <div class="row">
        <div class="col-lg-3">
            <div class="fotohub-card">
                <h4><i class="icon-magic"></i> {l s='Bulk Processing' mod='fotohubai'}</h4>
                <p>{l s='Generate images, remove backgrounds, write descriptions for multiple products at once.' mod='fotohubai'}</p>
                <a href="{$fotohub_bulk_url|escape:'htmlall':'UTF-8'}" class="btn btn-primary">
                    {l s='Open Bulk Processing' mod='fotohubai'}
                </a>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="fotohub-card">
                <h4><i class="icon-check-square-o"></i> {l s='Drafts Review' mod='fotohubai'}</h4>
                <p>{l s='Review and approve AI results before they touch your live catalog.' mod='fotohubai'}</p>
                <a href="{$fotohub_drafts_url|escape:'htmlall':'UTF-8'}" class="btn btn-primary">
                    {l s='Open Drafts Review' mod='fotohubai'}
                </a>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="fotohub-card">
                <h4><i class="icon-book"></i> {l s='Documentation' mod='fotohubai'}</h4>
                <p>{l s='Learn how to use FOTOhub AI with your PrestaShop store.' mod='fotohubai'}</p>
                <a href="https://docs.fotohub.app/integrations/prestashop" target="_blank" class="btn btn-default">
                    {l s='View Documentation' mod='fotohubai'}
                </a>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="fotohub-card">
                <h4><i class="icon-dashboard"></i> {l s='Dashboard' mod='fotohubai'}</h4>
                <p>{l s='Manage your account, check credits, and view generation history.' mod='fotohubai'}</p>
                <a href="https://fotohub.app/dashboard" target="_blank" class="btn btn-default">
                    {l s='Open Dashboard' mod='fotohubai'}
                </a>
            </div>
        </div>
    </div>
</div>

</div>{* /fotohub-tab-settings *}

{* MCP help tab (static, feature 10) *}
<div class="tab-pane" id="fotohub-tab-mcp">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-comments"></i> {l s='Manage your catalog with an AI assistant (MCP)' mod='fotohubai'}
        </div>
        <p>
            {l s='FOTOhub exposes a Model Context Protocol (MCP) server. Connect Claude Desktop, Cursor, or any MCP-capable assistant to your FOTOhub account and manage image generation, background removal, and more by chatting — the assistant calls the same API this module uses.' mod='fotohubai'}
        </p>
        <h4>{l s='Server URL' mod='fotohubai'}</h4>
        <pre>{$fotohub_mcp_url|escape:'htmlall':'UTF-8'}</pre>

        <h4>{l s='Claude Desktop / Cursor configuration' mod='fotohubai'}</h4>
        <p>{l s='Add this to your MCP configuration (claude_desktop_config.json or Cursor MCP settings), replacing fh_live_YOUR_KEY with your API key:' mod='fotohubai'}</p>
<pre>{literal}{
  "mcpServers": {
    "fotohub": {
      "url": "https://apis.fotohub.app/mcp/",
      "headers": {
        "Authorization": "Bearer fh_live_YOUR_KEY"
      }
    }
  }
}{/literal}</pre>

        <h4>{l s='What you can do' mod='fotohubai'}</h4>
        <ul>
            <li>{l s='"Generate a studio photo for product X and show me the result" — the assistant creates images with the same models available in this module.' mod='fotohubai'}</li>
            <li>{l s='"Remove the background from this image" — one-shot clean-ups without opening the back office.' mod='fotohubai'}</li>
            <li>{l s='"Write a product description in Polish, luxury tone" — copywriting on demand.' mod='fotohubai'}</li>
            <li>{l s='"How many credits do I have left?" — live account checks.' mod='fotohubai'}</li>
        </ul>
        <p class="text-muted">
            {l s='The MCP server uses the same fh_live_ key as this module. Credits are shared with all your FOTOhub usage.' mod='fotohubai'}
        </p>
    </div>
</div>{* /fotohub-tab-mcp *}

</div>{* /tab-content *}

<div class="panel-footer text-muted text-center">
    FOTOhub AI Module v{$fotohub_module_version|escape:'htmlall':'UTF-8'} &mdash;
    <a href="https://fotohub.app" target="_blank">fotohub.app</a>
</div>

<script type="text/javascript">
    document.getElementById('toggleApiKey').addEventListener('click', function() {
        var input = document.getElementById('FOTOHUBAI_API_KEY');
        var icon = this.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'icon-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'icon-eye';
        }
    });

    // Credits meter refresh
    var refreshBtn = document.getElementById('fotohubRefreshCredits');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            var target = document.getElementById('fotohubCreditsValue');
            target.textContent = '…';
            fetch('{$fotohub_config_url|escape:'javascript':'UTF-8'}&ajax=1&action=balance&token={$fotohub_token|escape:'javascript':'UTF-8'}', {ldelim}credentials: 'same-origin'{rdelim})
                .then(function(r) {ldelim} return r.json(); {rdelim})
                .then(function(data) {ldelim}
                    if (data.success) {ldelim}
                        target.textContent = data.credits;
                    {rdelim} else {ldelim}
                        target.textContent = '—';
                    {rdelim}
                {rdelim})
                .catch(function() {ldelim} target.textContent = '—'; {rdelim});
        });
    }
</script>
