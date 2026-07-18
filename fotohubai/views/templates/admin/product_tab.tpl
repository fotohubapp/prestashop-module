{**
 * FOTOhub AI — Product Page Tab
 *
 * Displayed on the product edit page via hookDisplayAdminProductsExtra.
 * Shows a "Generate AI Photo" button with prompt editor.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="panel fotohub-product-panel">
    <div class="panel-heading">
        <i class="icon-camera"></i> {l s='FOTOhub AI — Image Generation' mod='fotohubai'}
    </div>

    {if !$fotohub_has_api_key}
        <div class="alert alert-warning">
            <i class="icon-warning"></i>
            {l s='FOTOhub API key not configured.' mod='fotohubai'}
            <a href="{$link->getAdminLink('AdminFotoHubConfig')|escape:'htmlall':'UTF-8'}">
                {l s='Configure now' mod='fotohubai'}
            </a>
        </div>
    {else}
        <div class="form-group">
            <label class="control-label col-lg-2">
                {l s='Prompt' mod='fotohubai'}
            </label>
            <div class="col-lg-8">
                <textarea id="fotohub_prompt"
                          class="form-control"
                          rows="3"
                          placeholder="{l s='Leave empty to auto-generate from product name, description, and category' mod='fotohubai'}"></textarea>
                <p class="help-block">
                    {l s='Describe the product photo you want. Uses product data if left empty.' mod='fotohubai'}
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-2">
                {l s='Model' mod='fotohubai'}
            </label>
            <div class="col-lg-4">
                <input type="text"
                       id="fotohub_model"
                       class="form-control"
                       value="{$fotohub_default_model|escape:'htmlall':'UTF-8'}"
                       readonly />
            </div>
            <label class="control-label col-lg-1">
                {l s='Size' mod='fotohubai'}
            </label>
            <div class="col-lg-3">
                <span class="text-muted">
                    {$fotohub_default_width|intval} x {$fotohub_default_height|intval} px
                </span>
            </div>
        </div>

        <div class="form-group">
            <div class="col-lg-offset-2 col-lg-8">
                <button type="button" id="fotohub_generate_btn" class="btn btn-primary">
                    <i class="icon-magic"></i> {l s='Generate AI Photo' mod='fotohubai'}
                </button>
                <span id="fotohub_generate_status" class="fotohub-status"></span>
            </div>
        </div>

        <div id="fotohub_result" class="fotohub-result" style="display:none;">
            <div class="col-lg-offset-2 col-lg-8">
                <div class="alert alert-success">
                    <i class="icon-check"></i>
                    <span id="fotohub_result_message"></span>
                </div>
                <img id="fotohub_result_image" src="" alt="Generated" class="fotohub-preview-image" />
            </div>
        </div>
    {/if}
</div>

{if $fotohub_has_api_key}
<script type="text/javascript">
(function() {
    var generateBtn = document.getElementById('fotohub_generate_btn');
    var statusEl = document.getElementById('fotohub_generate_status');
    var resultEl = document.getElementById('fotohub_result');
    var resultMsg = document.getElementById('fotohub_result_message');
    var resultImg = document.getElementById('fotohub_result_image');

    generateBtn.addEventListener('click', function() {
        var prompt = document.getElementById('fotohub_prompt').value;

        generateBtn.disabled = true;
        statusEl.innerHTML = '<i class="icon-spinner icon-spin"></i> {l s="Generating..." mod="fotohubai" js=1}';
        resultEl.style.display = 'none';

        var xhr = new XMLHttpRequest();
        var url = '{$fotohub_generate_url|escape:"javascript":"UTF-8"}';
        url += '&id_product={$fotohub_product_id|intval}';
        if (prompt) {
            url += '&prompt=' + encodeURIComponent(prompt);
        }

        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            generateBtn.disabled = false;
            statusEl.innerHTML = '';

            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    resultMsg.textContent = response.message || '{l s="Image generated successfully!" mod="fotohubai" js=1}';
                    if (response.image_url) {
                        resultImg.src = response.image_url;
                        resultImg.style.display = 'block';
                    }
                    resultEl.style.display = 'block';
                } else {
                    statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> ' +
                        (response.error || '{l s="Generation failed" mod="fotohubai" js=1}') + '</span>';
                }
            } catch(e) {
                statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> {l s="Invalid response from server" mod="fotohubai" js=1}</span>';
            }
        };

        xhr.onerror = function() {
            generateBtn.disabled = false;
            statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> {l s="Network error" mod="fotohubai" js=1}</span>';
        };

        xhr.send();
    });
})();
</script>
{/if}
