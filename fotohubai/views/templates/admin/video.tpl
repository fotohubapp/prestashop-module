{**
 * FOTOhub AI — Video Generation Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-12">
            <h2><i class="icon-film"></i> FOTOhub AI — Video Generation</h2>
            <p class="text-muted">
                {l s='Generate product videos using AI. Create turntable 360 spins or lifestyle videos from your product images.' mod='fotohubai'}
            </p>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-film"></i> {l s='Generate Product Video' mod='fotohubai'}
    </div>
    <div class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_video_product">
                {l s='Product' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <select id="fotohub_video_product" name="fotohub_video_product" class="form-control">
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
            <label class="control-label col-lg-3">
                {l s='Video Type' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <label class="radio-inline">
                    <input type="radio" name="fotohub_video_type" value="turntable" checked="checked" />
                    {l s='Turntable 360' mod='fotohubai'}
                </label>
                <label class="radio-inline">
                    <input type="radio" name="fotohub_video_type" value="lifestyle" />
                    {l s='Lifestyle' mod='fotohubai'}
                </label>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_video_model">
                {l s='Model' mod='fotohubai'}
            </label>
            <div class="col-lg-4">
                <select id="fotohub_video_model" name="fotohub_video_model" class="form-control">
                    {foreach $fotohub_video_models as $model}
                        <option value="{$model.id|escape:'htmlall':'UTF-8'}">
                            {$model.name|escape:'htmlall':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3" for="fotohub_video_prompt">
                {l s='Custom Prompt (optional)' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <textarea id="fotohub_video_prompt"
                          class="form-control"
                          rows="3"
                          placeholder="{l s='Describe the video you want. Leave empty to auto-generate from product data.' mod='fotohubai'}"></textarea>
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-lg-3">
                {l s='Duration' mod='fotohubai'}
            </label>
            <div class="col-lg-5">
                <label class="radio-inline">
                    <input type="radio" name="fotohub_video_duration" value="3" checked="checked" />
                    {l s='3s' mod='fotohubai'}
                </label>
                <label class="radio-inline">
                    <input type="radio" name="fotohub_video_duration" value="5" />
                    {l s='5s' mod='fotohubai'}
                </label>
                <label class="radio-inline">
                    <input type="radio" name="fotohub_video_duration" value="10" />
                    {l s='10s' mod='fotohubai'}
                </label>
            </div>
        </div>

        <div class="form-group">
            <div class="col-lg-offset-3 col-lg-5">
                <button type="button" id="fotohub_generate_video_btn" class="btn btn-primary">
                    <i class="icon-film"></i> {l s='Generate Video' mod='fotohubai'}
                </button>
                <span id="fotohub_video_status" class="fotohub-status"></span>
            </div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i> {l s='Video Queue' mod='fotohubai'}
    </div>
    <table class="table" id="fotohub_video_queue_table">
        <thead>
            <tr>
                <th>{l s='Product' mod='fotohubai'}</th>
                <th>{l s='Type' mod='fotohubai'}</th>
                <th>{l s='Model' mod='fotohubai'}</th>
                <th>{l s='Status' mod='fotohubai'}</th>
                <th>{l s='Created' mod='fotohubai'}</th>
                <th>{l s='Actions' mod='fotohubai'}</th>
            </tr>
        </thead>
        <tbody id="fotohub_video_queue_body">
            {if isset($fotohub_video_queue) && $fotohub_video_queue|@count > 0}
                {foreach $fotohub_video_queue as $job}
                    <tr data-job-id="{$job.id|intval}">
                        <td>{$job.product_name|escape:'htmlall':'UTF-8'}</td>
                        <td>{$job.type|escape:'htmlall':'UTF-8'}</td>
                        <td>{$job.model|escape:'htmlall':'UTF-8'}</td>
                        <td>
                            {if $job.status == 'pending'}
                                <span class="badge badge-warning"><i class="icon-clock-o"></i> {l s='Pending' mod='fotohubai'}</span>
                            {elseif $job.status == 'processing'}
                                <span class="badge badge-info"><i class="icon-spinner icon-spin"></i> {l s='Processing' mod='fotohubai'}</span>
                            {elseif $job.status == 'completed'}
                                <span class="badge badge-success"><i class="icon-check"></i> {l s='Completed' mod='fotohubai'}</span>
                            {else}
                                <span class="badge badge-danger"><i class="icon-times"></i> {l s='Failed' mod='fotohubai'}</span>
                            {/if}
                        </td>
                        <td>{$job.created_at|escape:'htmlall':'UTF-8'}</td>
                        <td>
                            {if $job.status == 'completed' && isset($job.video_url)}
                                <button type="button" class="btn btn-xs btn-default fotohub-view-video"
                                        data-video-url="{$job.video_url|escape:'htmlall':'UTF-8'}">
                                    <i class="icon-play"></i> {l s='View' mod='fotohubai'}
                                </button>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            {else}
                <tr id="fotohub_video_queue_empty">
                    <td colspan="6" class="text-center text-muted">
                        {l s='No video jobs yet. Generate your first video above.' mod='fotohubai'}
                    </td>
                </tr>
            {/if}
        </tbody>
    </table>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-th"></i> {l s='Generated Videos Gallery' mod='fotohubai'}
    </div>
    {if isset($fotohub_generated_videos) && $fotohub_generated_videos|@count > 0}
        <div class="row">
            {foreach $fotohub_generated_videos as $video}
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="fotohub-video-thumb">
                        <video src="{$video.url|escape:'htmlall':'UTF-8'}" muted preload="metadata" class="img-responsive"></video>
                        <div class="fotohub-video-thumb-info">
                            <p class="fotohub-video-thumb-name">{$video.product_name|escape:'htmlall':'UTF-8'}</p>
                            <a href="{$video.url|escape:'htmlall':'UTF-8'}" download class="btn btn-xs btn-default">
                                <i class="icon-download"></i> {l s='Download' mod='fotohubai'}
                            </a>
                        </div>
                    </div>
                </div>
            {/foreach}
        </div>
    {else}
        <p class="text-center text-muted">
            {l s='No videos generated yet.' mod='fotohubai'}
        </p>
    {/if}
</div>

{* Video preview modal *}
<div class="modal fade" id="fotohub_video_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">{l s='Video Preview' mod='fotohubai'}</h4>
            </div>
            <div class="modal-body text-center">
                <video id="fotohub_video_player" controls class="img-responsive" style="max-width:100%;"></video>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
(function() {
    var generateBtn = document.getElementById('fotohub_generate_video_btn');
    var statusEl = document.getElementById('fotohub_video_status');
    var pollingInterval = null;

    generateBtn.addEventListener('click', function() {
        var productId = document.getElementById('fotohub_video_product').value;
        var videoType = document.querySelector('input[name="fotohub_video_type"]:checked').value;
        var model = document.getElementById('fotohub_video_model').value;
        var prompt = document.getElementById('fotohub_video_prompt').value;
        var duration = document.querySelector('input[name="fotohub_video_duration"]:checked').value;

        if (!productId) {
            statusEl.innerHTML = '<span class="text-danger"><i class="icon-warning"></i> {l s="Please select a product" mod="fotohubai" js=1}</span>';
            return;
        }

        generateBtn.disabled = true;
        statusEl.innerHTML = '<i class="icon-spinner icon-spin"></i> {l s="Submitting..." mod="fotohubai" js=1}';

        var xhr = new XMLHttpRequest();
        var url = '{$fotohub_video_url|escape:"javascript":"UTF-8"}&ajax=1&action=generateVideo';

        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            generateBtn.disabled = false;
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    statusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> ' +
                        (response.message || '{l s="Video job submitted!" mod="fotohubai" js=1}') + '</span>';
                    if (response.job_id) {
                        startPolling(response.job_id);
                    }
                } else {
                    statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> ' +
                        (response.error || '{l s="Failed to submit video job" mod="fotohubai" js=1}') + '</span>';
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
                     '&video_type=' + encodeURIComponent(videoType) +
                     '&model=' + encodeURIComponent(model) +
                     '&duration=' + encodeURIComponent(duration);
        if (prompt) {
            params += '&prompt=' + encodeURIComponent(prompt);
        }

        xhr.send(params);
    });

    function startPolling(jobId) {
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
        pollingInterval = setInterval(function() {
            var xhr = new XMLHttpRequest();
            var url = '{$fotohub_video_url|escape:"javascript":"UTF-8"}&ajax=1&action=checkStatus&job_id=' + jobId;
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.status === 'completed') {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                        statusEl.innerHTML = '<span class="text-success"><i class="icon-check"></i> {l s="Video ready!" mod="fotohubai" js=1}</span>';
                        window.location.reload();
                    } else if (response.status === 'failed') {
                        clearInterval(pollingInterval);
                        pollingInterval = null;
                        statusEl.innerHTML = '<span class="text-danger"><i class="icon-times"></i> ' +
                            (response.error || '{l s="Video generation failed" mod="fotohubai" js=1}') + '</span>';
                    } else {
                        statusEl.innerHTML = '<i class="icon-spinner icon-spin"></i> {l s="Processing..." mod="fotohubai" js=1}';
                    }
                } catch(e) {}
            };
            xhr.send();
        }, 5000);
    }

    // View video button handler
    var viewBtns = document.querySelectorAll('.fotohub-view-video');
    for (var i = 0; i < viewBtns.length; i++) {
        viewBtns[i].addEventListener('click', function() {
            var videoUrl = this.getAttribute('data-video-url');
            var player = document.getElementById('fotohub_video_player');
            player.src = videoUrl;
            $('#fotohub_video_modal').modal('show');
        });
    }

    $('#fotohub_video_modal').on('hidden.bs.modal', function() {
        document.getElementById('fotohub_video_player').pause();
        document.getElementById('fotohub_video_player').src = '';
    });
})();
</script>
