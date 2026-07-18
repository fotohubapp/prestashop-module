{**
 * FOTOhub AI — Creative Tools Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-12">
            <h2><i class="icon-wrench"></i> FOTOhub AI — Creative Tools</h2>
            <p class="text-muted">
                {l s='Advanced Stability AI tools for editing, retouching, and transforming your product images.' mod='fotohubai'}
            </p>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-th-large"></i> {l s='Select Tool' mod='fotohubai'}
    </div>
    <div class="row" id="fotohub_tools_grid">
        {foreach $fotohub_tools as $tool}
            <div class="col-lg-4 col-md-4 col-sm-6">
                <div class="fotohub-tool-card" data-tool-id="{$tool.id|escape:'htmlall':'UTF-8'}" data-requires-mask="{$tool.requires_mask|intval}">
                    <div class="fotohub-tool-card-icon">
                        <i class="icon-{$tool.icon|escape:'htmlall':'UTF-8'|default:'magic'}"></i>
                    </div>
                    <h4>{$tool.name|escape:'htmlall':'UTF-8'}</h4>
                    <p class="text-muted">{$tool.description|escape:'htmlall':'UTF-8'}</p>
                </div>
            </div>
        {/foreach}
    </div>
</div>

<div class="panel" id="fotohub_tools_process_panel" style="display:none;">
    <div class="panel-heading">
        <i class="icon-magic"></i> {l s='Process Image' mod='fotohubai'}
    </div>
    <div class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='Image Source' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <label class="radio-inline">
                    <input type="radio" name="fotohub_tool_source" value="product" checked="checked" />
                    {l s='From Product' mod='fotohubai'}
                </label>
                <label class="radio-inline">
                    <input type="radio" name="fotohub_tool_source" value="upload" />
                    {l s='Upload Image' mod='fotohubai'}
                </label>
            </div>
        </div>

        <div class="form-group" id="fotohub_tool_product_group">
            <label class="control-label col-lg-3" for="fotohub_tool_product">
                {l s='Product' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <select id="fotohub_tool_product" class="form-control">
                    <option value="">{l s='— Select a product —' mod='fotohubai'}</option>
                    {foreach $fotohub_products as $product}
                        <option value="{$product.id_product|intval}" data-image="{$product.image_url|escape:'htmlall':'UTF-8'|default:''}">
                            {$product.name|escape:'htmlall':'UTF-8'} (ID: {$product.id_product|intval})
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group" id="fotohub_tool_upload_group" style="display:none;">
            <label class="control-label col-lg-3">
                {l s='Upload Image' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <input type="file" id="fotohub_tool_file" accept="image/*" class="form-control" />
                <div id="fotohub_tool_upload_preview" class="fotohub-upload-preview" style="display:none;">
                    <img id="fotohub_tool_preview_img" src="" alt="Preview" class="img-responsive" />
                </div>
            </div>
        </div>

        {* Mask canvas — for erase-object, inpaint *}
        <div class="form-group fotohub-tool-option" id="fotohub_tool_mask_group" style="display:none;">
            <label class="control-label col-lg-3">
                {l s='Mask' mod='fotohubai'}
            </label>
            <div class="col-lg-7">
                <p class="help-block">
                    <i class="icon-pencil"></i> {l s='Draw on the image to define the area to process. White areas will be affected.' mod='fotohubai'}
                </p>
                <canvas id="fotohub_tool_mask_canvas" width="512" height="512" class="fotohub-mask-canvas"></canvas>
                <div class="btn-group" style="margin-top:5px;">
                    <button type="button" id="fotohub_mask_clear" class="btn btn-xs btn-default">
                        <i class="icon-eraser"></i> {l s='Clear Mask' mod='fotohubai'}
                    </button>
                    <button type="button" id="fotohub_mask_brush_sm" class="btn btn-xs btn-default">
                        {l s='Small Brush' mod='fotohubai'}
                    </button>
                    <button type="button" id="fotohub_mask_brush_lg" class="btn btn-xs btn-default active">
                        {l s='Large Brush' mod='fotohubai'}
                    </button>
                </div>
            </div>
        </div>

        {* Prompt input — for inpaint, search-replace, search-recolor, creative-upscale, control-sketch, control-structure *}
        <div class="form-group fotohub-tool-option" id="fotohub_tool_prompt_group" style="display:none;">
            <label class="control-label col-lg-3" for="fotohub_tool_prompt">
                {l s='Prompt' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <textarea id="fotohub_tool_prompt" class="form-control" rows="2"
                          placeholder="{l s='Describe what you want in the processed area' mod='fotohubai'}"></textarea>
            </div>
        </div>

        {* Direction selector — for outpaint *}
        <div class="form-group fotohub-tool-option" id="fotohub_tool_direction_group" style="display:none;">
            <label class="control-label col-lg-3">
                {l s='Expand Direction' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <label class="checkbox-inline">
                    <input type="checkbox" name="fotohub_tool_direction[]" value="left" /> {l s='Left' mod='fotohubai'}
                </label>
                <label class="checkbox-inline">
                    <input type="checkbox" name="fotohub_tool_direction[]" value="right" checked="checked" /> {l s='Right' mod='fotohubai'}
                </label>
                <label class="checkbox-inline">
                    <input type="checkbox" name="fotohub_tool_direction[]" value="up" /> {l s='Up' mod='fotohubai'}
                </label>
                <label class="checkbox-inline">
                    <input type="checkbox" name="fotohub_tool_direction[]" value="down" /> {l s='Down' mod='fotohubai'}
                </label>
            </div>
        </div>

        {* Search/Replace prompts — for search-replace, search-recolor *}
        <div class="form-group fotohub-tool-option" id="fotohub_tool_search_group" style="display:none;">
            <label class="control-label col-lg-3" for="fotohub_tool_search_prompt">
                {l s='Search Prompt' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <input type="text" id="fotohub_tool_search_prompt" class="form-control"
                       placeholder="{l s='What to find in the image' mod='fotohubai'}" />
            </div>
        </div>
        <div class="form-group fotohub-tool-option" id="fotohub_tool_replace_group" style="display:none;">
            <label class="control-label col-lg-3" for="fotohub_tool_replace_prompt">
                {l s='Replace Prompt' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <input type="text" id="fotohub_tool_replace_prompt" class="form-control"
                       placeholder="{l s='What to replace it with' mod='fotohubai'}" />
            </div>
        </div>

        {* Style image upload — for style-transfer *}
        <div class="form-group fotohub-tool-option" id="fotohub_tool_style_group" style="display:none;">
            <label class="control-label col-lg-3">
                {l s='Style Image' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <input type="file" id="fotohub_tool_style_file" accept="image/*" class="form-control" />
                <p class="help-block">{l s='Upload a reference image whose style will be applied.' mod='fotohubai'}</p>
            </div>
        </div>

        <div class="form-group">
            <div class="col-lg-offset-3 col-lg-5">
                <button type="button" id="fotohub_tool_process_btn" class="btn btn-primary">
                    <i class="icon-magic"></i> {l s='Process' mod='fotohubai'}
                </button>
                <span id="fotohub_tool_status" class="fotohub-status"></span>
            </div>
        </div>
    </div>
</div>

<div class="panel" id="fotohub_tools_result_panel" style="display:none;">
    <div class="panel-heading">
        <i class="icon-picture-o"></i> {l s='Result' mod='fotohubai'}
    </div>
    <div class="row">
        <div class="col-lg-5 text-center">
            <h5>{l s='Before' mod='fotohubai'}</h5>
            <img id="fotohub_tool_before_img" src="" alt="Before" class="img-responsive fotohub-compare-img" />
        </div>
        <div class="col-lg-1 text-center" style="padding-top:100px;">
            <i class="icon-arrow-right" style="font-size:24px;"></i>
        </div>
        <div class="col-lg-5 text-center">
            <h5>{l s='After' mod='fotohubai'}</h5>
            <img id="fotohub_tool_after_img" src="" alt="After" class="img-responsive fotohub-compare-img" />
        </div>
    </div>
    <div class="panel-footer">
        <button type="button" id="fotohub_tool_save_btn" class="btn btn-success" style="display:none;">
            <i class="icon-save"></i> {l s='Save to Product' mod='fotohubai'}
        </button>
        <a id="fotohub_tool_download_btn" href="#" download class="btn btn-default">
            <i class="icon-download"></i> {l s='Download' mod='fotohubai'}
        </a>
    </div>
</div>

<script type="text/javascript">
(function() {
    var selectedTool = null;
    var selectedSource = 'product';
    var brushSize = 20;
    var isDrawing = false;
    var maskCanvas = document.getElementById('fotohub_tool_mask_canvas');
    var maskCtx = maskCanvas.getContext('2d');
    var uploadedImageBase64 = null;
    var styleImageBase64 = null;

    // Tool-specific option mapping
    var toolOptions = {
        'erase-object':       { mask: true, prompt: false, direction: false, search: false, style: false },
        'inpaint':            { mask: true, prompt: true,  direction: false, search: false, style: false },
        'search-replace':     { mask: false, prompt: false, direction: false, search: true,  style: false },
        'search-recolor':     { mask: false, prompt: false, direction: false, search: true,  style: false },
        'outpaint':           { mask: false, prompt: false, direction: true,  search: false, style: false },
        'remove-background':  { mask: false, prompt: false, direction: false, search: false, style: false },
        'creative-upscale':   { mask: false, prompt: true,  direction: false, search: false, style: false },
        'control-sketch':     { mask: false, prompt: true,  direction: false, search: false, style: false },
        'control-structure':  { mask: false, prompt: true,  direction: false, search: false, style: false },
        'style-transfer':     { mask: false, prompt: false, direction: false, search: false, style: true  }
    };

    // Tool card selection
    var toolCards = document.querySelectorAll('.fotohub-tool-card');
    for (var i = 0; i < toolCards.length; i++) {
        toolCards[i].addEventListener('click', function() {
            for (var j = 0; j < toolCards.length; j++) {
                toolCards[j].classList.remove('selected');
            }
            this.classList.add('selected');
            selectedTool = this.getAttribute('data-tool-id');
            document.getElementById('fotohub_tools_process_panel').style.display = 'block';
            showToolOptions(selectedTool);
        });
    }

    function showToolOptions(toolId) {
        var opts = toolOptions[toolId] || {};
        document.getElementById('fotohub_tool_mask_group').style.display = opts.mask ? '' : 'none';
        document.getElementById('fotohub_tool_prompt_group').style.display = opts.prompt ? '' : 'none';
        document.getElementById('fotohub_tool_direction_group').style.display = opts.direction ? '' : 'none';
        document.getElementById('fotohub_tool_search_group').style.display = opts.search ? '' : 'none';
        document.getElementById('fotohub_tool_replace_group').style.display = opts.search ? '' : 'none';
        document.getElementById('fotohub_tool_style_group').style.display = opts.style ? '' : 'none';
    }

    // Source toggle
    var sourceRadios = document.querySelectorAll('input[name="fotohub_tool_source"]');
    for (var i = 0; i < sourceRadios.length; i++) {
        sourceRadios[i].addEventListener('change', function() {
            selectedSource = this.value;
            document.getElementById('fotohub_tool_product_group').style.display = (selectedSource === 'product') ? '' : 'none';
            document.getElementById('fotohub_tool_upload_group').style.display = (selectedSource === 'upload') ? '' : 'none';
        });
    }

    // File upload preview
    document.getElementById('fotohub_tool_file').addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            uploadedImageBase64 = ev.target.result;
            document.getElementById('fotohub_tool_preview_img').src = uploadedImageBase64;
            document.getElementById('fotohub_tool_upload_preview').style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    // Style image upload
    document.getElementById('fotohub_tool_style_file').addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            styleImageBase64 = ev.target.result;
        };
        reader.readAsDataURL(file);
    });

    // Mask canvas drawing
    maskCanvas.addEventListener('mousedown', function(e) { isDrawing = true; draw(e); });
    maskCanvas.addEventListener('mousemove', function(e) { if (isDrawing) draw(e); });
    maskCanvas.addEventListener('mouseup', function() { isDrawing = false; });
    maskCanvas.addEventListener('mouseleave', function() { isDrawing = false; });

    function draw(e) {
        var rect = maskCanvas.getBoundingClientRect();
        var x = e.clientX - rect.left;
        var y = e.clientY - rect.top;
        maskCtx.fillStyle = '#ffffff';
        maskCtx.beginPath();
        maskCtx.arc(x, y, brushSize, 0, Math.PI * 2);
        maskCtx.fill();
    }

    document.getElementById('fotohub_mask_clear').addEventListener('click', function() {
        maskCtx.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
    });
    document.getElementById('fotohub_mask_brush_sm').addEventListener('click', function() {
        brushSize = 10;
        this.classList.add('active');
        document.getElementById('fotohub_mask_brush_lg').classList.remove('active');
    });
    document.getElementById('fotohub_mask_brush_lg').addEventListener('click', function() {
        brushSize = 20;
        this.classList.add('active');
        document.getElementById('fotohub_mask_brush_sm').classList.remove('active');
    });

    // Process button
    document.getElementById('fotohub_tool_process_btn').addEventListener('click', function() {
        var statusEl = document.getElementById('fotohub_tool_status');
        var btn = this;

        if (!selectedTool) {
            statusEl.innerHTML = '<span class="text-danger"><i class="icon-warning"></i> {l s="Please select a tool" mod="fotohubai" js=1}</span>';
            return;
        }

        btn.disabled = true;
        statusEl.innerHTML = '<i class="icon-spinner icon-spin"></i> {l s="Processing..." mod="fotohubai" js=1}';

        var data = {
            tool: selectedTool,
            source: selectedSource
        };

        if (selectedSource === 'product') {
            data.id_product = document.getElementById('fotohub_tool_product').value;
        } else {
            data.image_base64 = uploadedImageBase64;
        }

        var opts = toolOptions[selectedTool] || {};
        if (opts.mask) {
            data.mask_base64 = maskCanvas.toDataURL('image/png');
        }
        if (opts.prompt) {
            data.prompt = document.getElementById('fotohub_tool_prompt').value;
        }
        if (opts.direction) {
            var dirs = [];
            var dirChecks = document.querySelectorAll('input[name="fotohub_tool_direction[]"]:checked');
            for (var d = 0; d < dirChecks.length; d++) { dirs.push(dirChecks[d].value); }
            data.directions = dirs;
        }
        if (opts.search) {
            data.search_prompt = document.getElementById('fotohub_tool_search_prompt').value;
            data.replace_prompt = document.getElementById('fotohub_tool_replace_prompt').value;
        }
        if (opts.style) {
            data.style_image_base64 = styleImageBase64;
        }

        var xhr = new XMLHttpRequest();
        var url = '{$fotohub_tools_url|escape:"javascript":"UTF-8"}&ajax=1&action=processTool';
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            btn.disabled = false;
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    statusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> {l s="Done!" mod="fotohubai" js=1}</span>';
                    document.getElementById('fotohub_tools_result_panel').style.display = 'block';
                    if (response.before_url) {
                        document.getElementById('fotohub_tool_before_img').src = response.before_url;
                    }
                    document.getElementById('fotohub_tool_after_img').src = response.result_url;
                    document.getElementById('fotohub_tool_download_btn').href = response.result_url;
                    if (selectedSource === 'product') {
                        document.getElementById('fotohub_tool_save_btn').style.display = '';
                    }
                } else {
                    statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> ' +
                        (response.error || '{l s="Processing failed" mod="fotohubai" js=1}') + '</span>';
                }
            } catch(e) {
                statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> {l s="Invalid response from server" mod="fotohubai" js=1}</span>';
            }
        };

        xhr.onerror = function() {
            btn.disabled = false;
            statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> {l s="Network error" mod="fotohubai" js=1}</span>';
        };

        xhr.send(JSON.stringify(data));
    });

    // Save to product
    document.getElementById('fotohub_tool_save_btn').addEventListener('click', function() {
        var resultUrl = document.getElementById('fotohub_tool_after_img').src;
        var productId = document.getElementById('fotohub_tool_product').value;
        var btn = this;

        btn.disabled = true;
        var xhr = new XMLHttpRequest();
        var url = '{$fotohub_tools_url|escape:"javascript":"UTF-8"}&ajax=1&action=saveToProduct';
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            btn.disabled = false;
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    btn.innerHTML = '<i class="icon-check"></i> {l s="Saved!" mod="fotohubai" js=1}';
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-default');
                }
            } catch(e) {}
        };
        xhr.send('id_product=' + encodeURIComponent(productId) + '&image_url=' + encodeURIComponent(resultUrl));
    });
})();
</script>
