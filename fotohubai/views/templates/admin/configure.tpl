{**
 * FOTOhub AI — Configuration Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-12">
            <h2><i class="icon-camera"></i> FOTOhub AI — Configuration</h2>
            <p class="text-muted">
                {l s='Generate AI product photos, remove backgrounds, and bulk-process your catalog.' mod='fotohubai'}
            </p>
        </div>
    </div>
</div>

{if isset($confirmations) && $confirmations|@count > 0}
    {foreach $confirmations as $confirmation}
        <div class="alert alert-success">{$confirmation}</div>
    {/foreach}
{/if}

{if isset($errors) && $errors|@count > 0}
    {foreach $errors as $error}
        <div class="alert alert-danger">{$error}</div>
    {/foreach}
{/if}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-key"></i> {l s='API Configuration' mod='fotohubai'}
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
                           placeholder="{l s='Enter your FOTOhub API key' mod='fotohubai'}" />
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" id="toggleApiKey">
                            <i class="icon-eye"></i>
                        </button>
                    </span>
                </div>
                <p class="help-block">
                    {l s='Get your API key from' mod='fotohubai'}
                    <a href="https://fotohub.app/settings/api" target="_blank">fotohub.app/settings/api</a>
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
                            {$model.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
                <p class="help-block">
                    {l s='AI model used for image generation. SeeDream 5.0 is recommended for product photos.' mod='fotohubai'}
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
                    {l s='Automatically generate an AI image when saving a product that has no images.' mod='fotohubai'}
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
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-info-circle"></i> {l s='Quick Links' mod='fotohubai'}
    </div>
    <div class="row">
        <div class="col-lg-4">
            <div class="fotohub-card">
                <h4><i class="icon-magic"></i> {l s='Bulk Processing' mod='fotohubai'}</h4>
                <p>{l s='Generate images, remove backgrounds, or upscale photos for multiple products at once.' mod='fotohubai'}</p>
                <a href="{$fotohub_bulk_url|escape:'htmlall':'UTF-8'}" class="btn btn-primary">
                    {l s='Open Bulk Processing' mod='fotohubai'}
                </a>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="fotohub-card">
                <h4><i class="icon-book"></i> {l s='Documentation' mod='fotohubai'}</h4>
                <p>{l s='Learn how to use FOTOhub AI with your PrestaShop store.' mod='fotohubai'}</p>
                <a href="https://docs.fotohub.app/integrations/prestashop" target="_blank" class="btn btn-default">
                    {l s='View Documentation' mod='fotohubai'}
                </a>
            </div>
        </div>
        <div class="col-lg-4">
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
</script>
