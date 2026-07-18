{**
 * FOTOhub AI — AI Copywriter Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-12">
            <h2><i class="icon-pencil"></i> FOTOhub AI — AI Copywriter</h2>
            <p class="text-muted">
                {l s='Generate product descriptions, SEO titles, social media posts, and marketing copy using AI.' mod='fotohubai'}
            </p>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-pencil"></i> {l s='Generate Content' mod='fotohubai'}
    </div>
    <div class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_copy_product">
                {l s='Product' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <select id="fotohub_copy_product" class="form-control">
                    <option value="">{l s='— Select a product —' mod='fotohubai'}</option>
                    {foreach $fotohub_products as $product}
                        <option value="{$product.id_product|intval}">
                            {$product.name|escape:'htmlall':'UTF-8'} (ID: {$product.id_product|intval})
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_copy_type">
                {l s='Content Type' mod='fotohubai'}
            </label>
            <div class="col-lg-4">
                <select id="fotohub_copy_type" class="form-control">
                    {foreach $fotohub_content_types as $type}
                        <option value="{$type.id|escape:'htmlall':'UTF-8'}">
                            {$type.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_copy_language">
                {l s='Language' mod='fotohubai'}
            </label>
            <div class="col-lg-3">
                <select id="fotohub_copy_language" class="form-control">
                    {foreach $fotohub_languages as $lang}
                        <option value="{$lang.iso_code|escape:'htmlall':'UTF-8'}"
                                {if $lang.iso_code == $fotohub_default_language}selected="selected"{/if}>
                            {$lang.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_copy_tone">
                {l s='Tone' mod='fotohubai'}
            </label>
            <div class="col-lg-3">
                <select id="fotohub_copy_tone" class="form-control">
                    {foreach $fotohub_tones as $tone}
                        <option value="{$tone.id|escape:'htmlall':'UTF-8'}">
                            {$tone.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group" id="fotohub_copy_platform_group" style="display:none;">
            <label class="control-label col-lg-3">
                {l s='Platform' mod='fotohubai'}
            </label>
            <div class="col-lg-5" id="fotohub_copy_platform_badges">
                <span class="badge fotohub-platform-badge" data-platform="instagram">Instagram</span>
                <span class="badge fotohub-platform-badge" data-platform="facebook">Facebook</span>
                <span class="badge fotohub-platform-badge" data-platform="twitter">Twitter/X</span>
                <span class="badge fotohub-platform-badge" data-platform="tiktok">TikTok</span>
                <span class="badge fotohub-platform-badge" data-platform="linkedin">LinkedIn</span>
            </div>
        </div>

        <div class="form-group">
            <div class="col-lg-offset-3 col-lg-5">
                <label class="checkbox-inline">
                    <input type="checkbox" id="fotohub_copy_auto_apply" />
                    {l s='Auto-apply to product' mod='fotohubai'}
                </label>
            </div>
        </div>

        <div class="form-group">
            <div class="col-lg-offset-3 col-lg-5">
                <button type="button" id="fotohub_copy_generate_btn" class="btn btn-primary">
                    <i class="icon-pencil"></i> {l s='Generate' mod='fotohubai'}
                </button>
                <span id="fotohub_copy_status" class="fotohub-status"></span>
            </div>
        </div>
    </div>
</div>

<div class="panel" id="fotohub_copy_result_panel" style="display:none;">
    <div class="panel-heading">
        <i class="icon-file-text-o"></i> {l s='Generated Content' mod='fotohubai'}
    </div>
    <div class="form-horizontal">
        <div class="form-group">
            <div class="col-lg-10 col-lg-offset-1">
                <textarea id="fotohub_copy_output" class="form-control" rows="8"></textarea>
                <p class="help-block text-right">
                    <span id="fotohub_copy_char_count">0</span> {l s='characters' mod='fotohubai'}
                </p>
            </div>
        </div>
        <div class="form-group">
            <div class="col-lg-10 col-lg-offset-1">
                <button type="button" id="fotohub_copy_clipboard_btn" class="btn btn-default">
                    <i class="icon-copy"></i> {l s='Copy to Clipboard' mod='fotohubai'}
                </button>
                <button type="button" id="fotohub_copy_apply_btn" class="btn btn-success">
                    <i class="icon-check"></i> {l s='Apply to Product' mod='fotohubai'}
                </button>
                <button type="button" id="fotohub_copy_regenerate_btn" class="btn btn-warning">
                    <i class="icon-refresh"></i> {l s='Regenerate' mod='fotohubai'}
                </button>
                <span id="fotohub_copy_action_status" class="fotohub-status"></span>
            </div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-tasks"></i> {l s='Bulk Generation' mod='fotohubai'}
    </div>
    <div class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='Select Products' mod='fotohubai'}
            </label>
            <div class="col-lg-7">
                <div class="fotohub-product-checklist" id="fotohub_copy_bulk_products">
                    {foreach $fotohub_products as $product}
                        <label class="checkbox">
                            <input type="checkbox" name="fotohub_bulk_products[]" value="{$product.id_product|intval}" />
                            {$product.name|escape:'htmlall':'UTF-8'} (ID: {$product.id_product|intval})
                        </label>
                    {/foreach}
                </div>
                <div style="margin-top:5px;">
                    <button type="button" id="fotohub_copy_select_all" class="btn btn-xs btn-default">
                        {l s='Select All' mod='fotohubai'}
                    </button>
                    <button type="button" id="fotohub_copy_select_none" class="btn btn-xs btn-default">
                        {l s='Select None' mod='fotohubai'}
                    </button>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_bulk_type">
                {l s='Content Type' mod='fotohubai'}
            </label>
            <div class="col-lg-4">
                <select id="fotohub_bulk_type" class="form-control">
                    {foreach $fotohub_content_types as $type}
                        <option value="{$type.id|escape:'htmlall':'UTF-8'}">
                            {$type.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_bulk_language">
                {l s='Language' mod='fotohubai'}
            </label>
            <div class="col-lg-3">
                <select id="fotohub_bulk_language" class="form-control">
                    {foreach $fotohub_languages as $lang}
                        <option value="{$lang.iso_code|escape:'htmlall':'UTF-8'}"
                                {if $lang.iso_code == $fotohub_default_language}selected="selected"{/if}>
                            {$lang.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_bulk_tone">
                {l s='Tone' mod='fotohubai'}
            </label>
            <div class="col-lg-3">
                <select id="fotohub_bulk_tone" class="form-control">
                    {foreach $fotohub_tones as $tone}
                        <option value="{$tone.id|escape:'htmlall':'UTF-8'}">
                            {$tone.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="col-lg-offset-3 col-lg-5">
                <button type="button" id="fotohub_copy_bulk_btn" class="btn btn-primary">
                    <i class="icon-pencil"></i> {l s='Generate for Selected' mod='fotohubai'}
                </button>
            </div>
        </div>

        <div class="form-group" id="fotohub_bulk_progress_group" style="display:none;">
            <div class="col-lg-offset-3 col-lg-7">
                <div class="progress">
                    <div id="fotohub_bulk_progress_bar" class="progress-bar progress-bar-primary" role="progressbar" style="width:0%;">
                        0%
                    </div>
                </div>
            </div>
        </div>

        <div id="fotohub_bulk_results_table" style="display:none;">
            <table class="table">
                <thead>
                    <tr>
                        <th>{l s='Product' mod='fotohubai'}</th>
                        <th>{l s='Generated Content' mod='fotohubai'}</th>
                        <th>{l s='Status' mod='fotohubai'}</th>
                    </tr>
                </thead>
                <tbody id="fotohub_bulk_results_body">
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
(function() {
    var generateBtn = document.getElementById('fotohub_copy_generate_btn');
    var statusEl = document.getElementById('fotohub_copy_status');
    var resultPanel = document.getElementById('fotohub_copy_result_panel');
    var outputEl = document.getElementById('fotohub_copy_output');
    var charCountEl = document.getElementById('fotohub_copy_char_count');
    var actionStatusEl = document.getElementById('fotohub_copy_action_status');

    // Show platform badges for social content types
    document.getElementById('fotohub_copy_type').addEventListener('change', function() {
        var socialTypes = ['social-post', 'social-caption', 'social-story'];
        var isSocial = socialTypes.indexOf(this.value) !== -1;
        document.getElementById('fotohub_copy_platform_group').style.display = isSocial ? '' : 'none';
    });

    // Platform badge selection
    var badges = document.querySelectorAll('.fotohub-platform-badge');
    for (var i = 0; i < badges.length; i++) {
        badges[i].addEventListener('click', function() {
            this.classList.toggle('active');
        });
    }

    // Character count
    outputEl.addEventListener('input', function() {
        charCountEl.textContent = this.value.length;
    });

    // Generate
    generateBtn.addEventListener('click', function() {
        doGenerate();
    });

    function doGenerate() {
        var productId = document.getElementById('fotohub_copy_product').value;
        var contentType = document.getElementById('fotohub_copy_type').value;
        var language = document.getElementById('fotohub_copy_language').value;
        var tone = document.getElementById('fotohub_copy_tone').value;
        var autoApply = document.getElementById('fotohub_copy_auto_apply').checked;

        if (!productId) {
            statusEl.innerHTML = '<span class="text-danger"><i class="icon-warning"></i> {l s="Please select a product" mod="fotohubai" js=1}</span>';
            return;
        }

        generateBtn.disabled = true;
        statusEl.innerHTML = '<i class="icon-spinner icon-spin"></i> {l s="Generating..." mod="fotohubai" js=1}';

        var xhr = new XMLHttpRequest();
        var url = '{$fotohub_copy_url|escape:"javascript":"UTF-8"}&ajax=1&action=generate';
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            generateBtn.disabled = false;
            statusEl.innerHTML = '';
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    outputEl.value = response.content;
                    charCountEl.textContent = response.content.length;
                    resultPanel.style.display = 'block';
                    if (autoApply && response.applied) {
                        actionStatusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> {l s="Auto-applied to product" mod="fotohubai" js=1}</span>';
                    }
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

        var params = 'id_product=' + encodeURIComponent(productId) +
                     '&content_type=' + encodeURIComponent(contentType) +
                     '&language=' + encodeURIComponent(language) +
                     '&tone=' + encodeURIComponent(tone) +
                     '&auto_apply=' + (autoApply ? '1' : '0');

        var activePlatforms = document.querySelectorAll('.fotohub-platform-badge.active');
        for (var p = 0; p < activePlatforms.length; p++) {
            params += '&platforms[]=' + encodeURIComponent(activePlatforms[p].getAttribute('data-platform'));
        }

        xhr.send(params);
    }

    // Copy to clipboard
    document.getElementById('fotohub_copy_clipboard_btn').addEventListener('click', function() {
        var text = outputEl.value;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                actionStatusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> {l s="Copied!" mod="fotohubai" js=1}</span>';
            });
        } else {
            outputEl.select();
            document.execCommand('copy');
            actionStatusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> {l s="Copied!" mod="fotohubai" js=1}</span>';
        }
    });

    // Apply to product
    document.getElementById('fotohub_copy_apply_btn').addEventListener('click', function() {
        var productId = document.getElementById('fotohub_copy_product').value;
        var content = outputEl.value;
        var contentType = document.getElementById('fotohub_copy_type').value;
        var btn = this;

        btn.disabled = true;
        actionStatusEl.innerHTML = '<i class="icon-spinner icon-spin"></i>';

        var xhr = new XMLHttpRequest();
        var url = '{$fotohub_copy_url|escape:"javascript":"UTF-8"}&ajax=1&action=apply';
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            btn.disabled = false;
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    actionStatusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> {l s="Applied to product!" mod="fotohubai" js=1}</span>';
                } else {
                    actionStatusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> ' +
                        (response.error || '{l s="Failed to apply" mod="fotohubai" js=1}') + '</span>';
                }
            } catch(e) {
                actionStatusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> {l s="Error" mod="fotohubai" js=1}</span>';
            }
        };

        xhr.send('id_product=' + encodeURIComponent(productId) +
                 '&content=' + encodeURIComponent(content) +
                 '&content_type=' + encodeURIComponent(contentType));
    });

    // Regenerate
    document.getElementById('fotohub_copy_regenerate_btn').addEventListener('click', function() {
        doGenerate();
    });

    // Select All / None
    document.getElementById('fotohub_copy_select_all').addEventListener('click', function() {
        var checks = document.querySelectorAll('#fotohub_copy_bulk_products input[type="checkbox"]');
        for (var i = 0; i < checks.length; i++) { checks[i].checked = true; }
    });
    document.getElementById('fotohub_copy_select_none').addEventListener('click', function() {
        var checks = document.querySelectorAll('#fotohub_copy_bulk_products input[type="checkbox"]');
        for (var i = 0; i < checks.length; i++) { checks[i].checked = false; }
    });

    // Bulk generation
    document.getElementById('fotohub_copy_bulk_btn').addEventListener('click', function() {
        var checks = document.querySelectorAll('#fotohub_copy_bulk_products input[type="checkbox"]:checked');
        if (checks.length === 0) {
            alert('{l s="Please select at least one product" mod="fotohubai" js=1}');
            return;
        }

        var productIds = [];
        for (var i = 0; i < checks.length; i++) { productIds.push(checks[i].value); }

        var contentType = document.getElementById('fotohub_bulk_type').value;
        var language = document.getElementById('fotohub_bulk_language').value;
        var tone = document.getElementById('fotohub_bulk_tone').value;
        var btn = this;

        btn.disabled = true;
        document.getElementById('fotohub_bulk_progress_group').style.display = '';
        document.getElementById('fotohub_bulk_results_table').style.display = '';
        var progressBar = document.getElementById('fotohub_bulk_progress_bar');
        var resultsBody = document.getElementById('fotohub_bulk_results_body');
        resultsBody.innerHTML = '';

        var completed = 0;
        var total = productIds.length;

        function processNext(index) {
            if (index >= total) {
                btn.disabled = false;
                progressBar.style.width = '100%';
                progressBar.textContent = '{l s="Complete!" mod="fotohubai" js=1}';
                return;
            }

            var xhr = new XMLHttpRequest();
            var url = '{$fotohub_copy_url|escape:"javascript":"UTF-8"}&ajax=1&action=generate';
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onload = function() {
                completed++;
                var pct = Math.round((completed / total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';

                try {
                    var response = JSON.parse(xhr.responseText);
                    var row = '<tr><td>' + (response.product_name || productIds[index]) + '</td>';
                    if (response.success) {
                        var preview = response.content.substring(0, 80) + (response.content.length > 80 ? '...' : '');
                        row += '<td>' + preview + '</td>';
                        row += '<td><span class="badge badge-success"><i class="icon-check"></i> {l s="OK" mod="fotohubai" js=1}</span></td>';
                    } else {
                        row += '<td>-</td>';
                        row += '<td><span class="badge badge-danger"><i class="icon-times"></i> {l s="Failed" mod="fotohubai" js=1}</span></td>';
                    }
                    row += '</tr>';
                    resultsBody.innerHTML += row;
                } catch(e) {
                    resultsBody.innerHTML += '<tr><td>' + productIds[index] + '</td><td>-</td><td><span class="badge badge-danger">{l s="Error" mod="fotohubai" js=1}</span></td></tr>';
                }

                processNext(index + 1);
            };

            xhr.onerror = function() {
                completed++;
                var pct = Math.round((completed / total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
                resultsBody.innerHTML += '<tr><td>' + productIds[index] + '</td><td>-</td><td><span class="badge badge-danger">{l s="Network error" mod="fotohubai" js=1}</span></td></tr>';
                processNext(index + 1);
            };

            xhr.send('id_product=' + encodeURIComponent(productIds[index]) +
                     '&content_type=' + encodeURIComponent(contentType) +
                     '&language=' + encodeURIComponent(language) +
                     '&tone=' + encodeURIComponent(tone) +
                     '&auto_apply=1');
        }

        processNext(0);
    });
})();
</script>
