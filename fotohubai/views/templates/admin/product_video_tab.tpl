{**
 * FOTOhub AI — Product Video Tab (inline in product editor left column)
 *
 * Variables:
 *   $fotohub_product_id       - int
 *   $fotohub_product_videos   - array of video objects
 *   $fotohub_video_models     - array of available model names
 *   $fotohub_video_url        - admin link to video controller
 *}

<div class="fotohub-video-panel card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-header-title mb-0">
            <i class="material-icons">videocam</i>
            {l s='AI Video' mod='fotohubai'}
        </h3>
        <span class="badge badge-primary">{$fotohub_product_videos|count} {l s='videos' mod='fotohubai'}</span>
    </div>
    <div class="card-body">
        {if $fotohub_product_videos|count > 0}
            <div class="fotohub-video-list mb-3">
                {foreach from=$fotohub_product_videos item=video}
                    <div class="fotohub-video-item d-flex align-items-center mb-2 p-2 border rounded">
                        <div class="fotohub-video-thumb mr-2">
                            {if !empty($video.thumbnail_url)}
                                <img src="{$video.thumbnail_url|escape:'htmlall':'UTF-8'}" alt="" width="60" height="40" class="rounded" />
                            {else}
                                <div class="fotohub-video-placeholder rounded d-flex align-items-center justify-content-center" style="width:60px;height:40px;background:#f0f0f0;">
                                    <i class="material-icons text-muted">movie</i>
                                </div>
                            {/if}
                        </div>
                        <div class="fotohub-video-info flex-grow-1">
                            <small class="text-muted d-block">{$video.model|escape:'htmlall':'UTF-8'}</small>
                            <small class="text-muted">{$video.created_at|escape:'htmlall':'UTF-8'}</small>
                        </div>
                        <div class="fotohub-video-status">
                            {if $video.status == 'completed'}
                                <span class="badge badge-success">{l s='Ready' mod='fotohubai'}</span>
                            {elseif $video.status == 'processing'}
                                <span class="badge badge-warning">{l s='Processing' mod='fotohubai'}</span>
                            {else}
                                <span class="badge badge-secondary">{$video.status|escape:'htmlall':'UTF-8'}</span>
                            {/if}
                        </div>
                    </div>
                {/foreach}
            </div>
        {else}
            <p class="text-muted mb-3">
                <i class="material-icons" style="vertical-align:middle;font-size:16px;">info</i>
                {l s='No videos generated yet for this product.' mod='fotohubai'}
            </p>
        {/if}

        <a href="{$fotohub_video_url|escape:'htmlall':'UTF-8'}&id_product={$fotohub_product_id}" class="btn btn-primary btn-sm">
            <i class="material-icons">add_circle</i>
            {l s='Generate Video' mod='fotohubai'}
        </a>
    </div>
</div>
