{**
 * FOTOhub AI — Bulk Processing Template
 *
 * Product picker filters, preset gallery, generation/description options,
 * estimate preflight, and the active-job progress table with retry-failed
 * and cancel.
 *
 * Every product-derived and API-derived string is escaped on output.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-page-header">
    <div class="fotohub-page-header-main">
        <h2 class="fotohub-page-title">
            <svg class="fotohub-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M7.5 5.6 10 4.5 7.5 3.4 6.4 1 5.3 3.4 3 4.5l2.3 1.1L6.4 8zM19 15.5l-1.1-2.4-1.1 2.4-2.3 1.1 2.3 1.1 1.1 2.3 1.1-2.3 2.4-1.1zM11.7 8.3 10 4.5 8.3 8.3 4.5 10l3.8 1.7L10 15.5l1.7-3.8L15.5 10z"/>
            </svg>
            {l s='Bulk Processing' mod='fotohubai'}
        </h2>
        <p class="fotohub-page-subtitle">
            {l s='Filter and select products, choose a preset and options, check the cost, then run. Results wait in Drafts Review — nothing goes live without your approval.' mod='fotohubai'}
        </p>
    </div>
    <div>
        <div class="fotohub-meter{if $fotohub_low_balance} is-low{/if}">
            <span class="fotohub-meter-label">{l s='Credits' mod='fotohubai'}</span>
            <span class="fotohub-meter-value">
                {if $fotohub_credits !== null}{$fotohub_credits|floatval|string_format:"%.1f"}{else}&mdash;{/if}
            </span>
        </div>
        {if $fotohub_pending_drafts > 0}
            <a href="{$fotohub_drafts_url|escape:'html':'UTF-8'}" class="btn btn-default btn-sm" style="margin-top:8px">
                {$fotohub_pending_drafts|intval} {l s='draft(s) awaiting review' mod='fotohubai'}
            </a>
        {/if}
    </div>
</div>

{if $fotohub_low_balance}
    <div class="alert alert-warning" role="status">
        {l s='Low balance: fewer than 50 credits remaining. Top up at fotohub.app/dashboard to avoid interrupted jobs.' mod='fotohubai'}
    </div>
{/if}

{if !$fotohub_bridge_connected}
    <div class="alert alert-info" role="status">
        {l s='No commerce-bridge connection registered — bulk actions will run in local mode, one product at a time.' mod='fotohubai'}
        <a href="{$fotohub_config_url|escape:'html':'UTF-8'}">{l s='Re-save your API key to connect.' mod='fotohubai'}</a>
    </div>
{/if}

{if !$fotohub_can_edit}
    <div class="alert alert-info" role="status">
        {l s='You have read-only access to this page. Running bulk jobs requires edit permission on the FOTOhub AI menu.' mod='fotohubai'}
    </div>
{/if}

{* ── Product picker filters (feature 2) ─────────────────────────────────── *}
<div class="panel">
    <div class="panel-heading">{l s='Product Filters' mod='fotohubai'}</div>

    <nav class="fotohub-filter-tabs" aria-label="{l s='Filter the product list' mod='fotohubai'}">
        <a class="fotohub-filter-tab{if empty($fotohub_active_filter)} is-active{/if}"
           href="{$current|escape:'html':'UTF-8'}&token={$token|escape:'html':'UTF-8'}">
            {l s='All products' mod='fotohubai'}
        </a>
        <a class="fotohub-filter-tab{if $fotohub_active_filter == 'missing_description'} is-active{/if}"
           href="{$current|escape:'html':'UTF-8'}&token={$token|escape:'html':'UTF-8'}&fh_filter=missing_description">
            {l s='Missing description' mod='fotohubai'}
        </a>
        <a class="fotohub-filter-tab{if $fotohub_active_filter == 'no_images'} is-active{/if}"
           href="{$current|escape:'html':'UTF-8'}&token={$token|escape:'html':'UTF-8'}&fh_filter=no_images">
            {l s='No images' mod='fotohubai'}
        </a>
        <a class="fotohub-filter-tab{if $fotohub_active_filter == 'few_images'} is-active{/if}"
           href="{$current|escape:'html':'UTF-8'}&token={$token|escape:'html':'UTF-8'}&fh_filter=few_images&fh_max_images={$fotohub_max_images|intval}">
            {l s='Fewer than' mod='fotohubai'} {$fotohub_max_images|intval} {l s='images' mod='fotohubai'}
        </a>
    </nav>

    <form method="get" action="{$current|escape:'html':'UTF-8'}" class="fotohub-field-grid">
        <input type="hidden" name="controller" value="AdminFotoHubBulk" />
        <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}" />
        <input type="hidden" name="fh_filter" value="{$fotohub_active_filter|escape:'html':'UTF-8'}" />

        <div class="fotohub-field">
            <label for="fh_max_images">{l s='Image count threshold' mod='fotohubai'}</label>
            <input type="number" id="fh_max_images" name="fh_max_images" class="form-control"
                   value="{$fotohub_max_images|intval}" min="1" max="20" step="1" />
            <span class="fotohub-help">{l s='Used by the "fewer than N images" filter.' mod='fotohubai'}</span>
        </div>
        <div class="fotohub-field">
            <label for="fh_price_min">{l s='Minimum price' mod='fotohubai'}</label>
            <input type="text" id="fh_price_min" name="fh_price_min" class="form-control"
                   value="{$fotohub_price_min|escape:'html':'UTF-8'}" inputmode="decimal" placeholder="0" />
        </div>
        <div class="fotohub-field">
            <label for="fh_price_max">{l s='Maximum price' mod='fotohubai'}</label>
            <input type="text" id="fh_price_max" name="fh_price_max" class="form-control"
                   value="{$fotohub_price_max|escape:'html':'UTF-8'}" inputmode="decimal" placeholder="9999" />
        </div>
        <div class="fotohub-field" style="align-self:end">
            <button type="submit" class="btn btn-default">{l s='Apply filters' mod='fotohubai'}</button>
        </div>
    </form>

    <p class="fotohub-help">
        {l s='Combine these with the category filter and search box in the product list below, then use "select all" to queue every matching product.' mod='fotohubai'}
    </p>
</div>

{* ── Progress UI: active bridge jobs (feature 6) ────────────────────────── *}
{if !empty($fotohub_active_jobs)}
    <div class="panel" id="fotohubJobsPanel">
        <div class="panel-heading">{l s='Active Jobs' mod='fotohubai'}</div>

        <div class="table-responsive">
        <table class="table fotohub-table">
            <thead>
                <tr>
                    <th>{l s='Job ID' mod='fotohubai'}</th>
                    <th>{l s='Kind' mod='fotohubai'}</th>
                    <th>{l s='Started' mod='fotohubai'}</th>
                    <th>{l s='Progress' mod='fotohubai'}</th>
                    <th class="fotohub-col-status">{l s='Status' mod='fotohubai'}</th>
                    <th class="fotohub-col-actions">{l s='Actions' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_active_jobs as $job}
                    <tr class="fotohub-job-row" data-job-id="{$job.job_id|escape:'html':'UTF-8'}">
                        <td><code>{$job.job_id|escape:'html':'UTF-8'}</code></td>
                        <td>{$job.kind|default:''|escape:'html':'UTF-8'}</td>
                        <td>{$job.created_at|default:''|escape:'html':'UTF-8'}</td>
                        <td class="fotohub-job-progress">
                            {if isset($job.total_items) && $job.total_items > 0}
                                <span class="fotohub-progress-text">
                                    {$job.done_items|default:0|intval}/{$job.total_items|intval}
                                    {if $job.failed_items|default:0 > 0}
                                        &middot; {$job.failed_items|intval} {l s='failed' mod='fotohubai'}
                                    {/if}
                                </span>
                                <span class="fotohub-progress" role="progressbar"
                                      aria-valuenow="{$job.done_items|default:0|intval}"
                                      aria-valuemin="0" aria-valuemax="{$job.total_items|intval}">
                                    <span class="fotohub-progress-fill"
                                          style="width:{$job.percent|default:0|intval}%"></span>
                                </span>
                            {else}
                                <span class="fotohub-muted">{l s='waiting' mod='fotohubai'}</span>
                            {/if}
                        </td>
                        <td class="fotohub-col-status">
                            <span class="fotohub-status fotohub-status-pending fotohub-job-status">
                                {$job.status|default:'queued'|escape:'html':'UTF-8'}
                            </span>
                            {if $job.status == 'awaiting_credits'}
                                <span class="fotohub-error-text">{l s='Top up credits to resume' mod='fotohubai'}</span>
                            {/if}
                        </td>
                        <td class="fotohub-col-actions">
                            {if $fotohub_can_edit}
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}" />
                                    <input type="hidden" name="bridge_job_id" value="{$job.job_id|escape:'html':'UTF-8'}" />
                                    <button type="submit" name="retryFailedJob" class="btn btn-default btn-xs"
                                            title="{l s='Requeue only the failed items' mod='fotohubai'}">
                                        {l s='Retry failed only' mod='fotohubai'}
                                    </button>
                                    <button type="submit" name="cancelBridgeJob" class="btn btn-default btn-xs"
                                            data-fotohub-confirm="{l s='Cancel this job?' mod='fotohubai'}">
                                        {l s='Cancel' mod='fotohubai'}
                                    </button>
                                </form>
                            {else}
                                <span class="fotohub-muted">&mdash;</span>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        </div>

        <p class="fotohub-help" style="padding:0 12px 12px">
            {l s='Jobs keep running on FOTOhub even if you close this page; progress is restored on reload. Completed results appear in Drafts Review.' mod='fotohubai'}
        </p>
    </div>
{/if}

{* ── Options wizard: models, description fields, presets, estimate ──────── *}
<div class="panel" id="fotohubOptionsPanel">
    <div class="panel-heading">{l s='Generation Options' mod='fotohubai'}</div>
    <p class="fotohub-help">
        {l s='These options apply to the next bulk action you launch from the product list below.' mod='fotohubai'}
    </p>

    <div class="fotohub-field-grid">
        <div class="fotohub-field">
            <label for="fh_model">{l s='Image model' mod='fotohubai'}</label>
            <select id="fh_model" name="fh_model" class="form-control" form="form-product">
                {foreach $fotohub_image_models as $modelId => $meta}
                    <option value="{$modelId|escape:'html':'UTF-8'}">
                        {$meta.name|escape:'html':'UTF-8'} — {$meta.credits|floatval} {l s='cr' mod='fotohubai'}
                    </option>
                {/foreach}
            </select>
            <span class="fotohub-help">{l s='Credit cost is per generated image.' mod='fotohubai'}</span>
        </div>

        <div class="fotohub-field">
            <label for="fh_num_images">{l s='Images per product' mod='fotohubai'}</label>
            <select id="fh_num_images" name="fh_num_images" class="form-control" form="form-product">
                {foreach [1, 2, 3, 4] as $n}
                    <option value="{$n|intval}">{$n|intval}</option>
                {/foreach}
            </select>
        </div>

        <div class="fotohub-field">
            <label for="fh_aspect_ratio">{l s='Aspect ratio' mod='fotohubai'}</label>
            <select id="fh_aspect_ratio" name="fh_aspect_ratio" class="form-control" form="form-product">
                {foreach ['1:1', '4:3', '3:4', '16:9', '9:16'] as $ratio}
                    <option value="{$ratio|escape:'html':'UTF-8'}">{$ratio|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
        </div>

        <div class="fotohub-field">
            <label for="fh_language">{l s='Text language' mod='fotohubai'}</label>
            <select id="fh_language" name="fh_language" class="form-control" form="form-product">
                {foreach $fotohub_languages as $lang}
                    <option value="{$lang|escape:'html':'UTF-8'}">{$lang|upper|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
        </div>

        <div class="fotohub-field">
            <label for="fh_tone">{l s='Tone' mod='fotohubai'}</label>
            <select id="fh_tone" name="fh_tone" class="form-control" form="form-product">
                {foreach $fotohub_tones as $tone}
                    <option value="{$tone|escape:'html':'UTF-8'}">{$tone|capitalize|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
            <span class="fotohub-help">{l s='Applies to descriptions and alt texts.' mod='fotohubai'}</span>
        </div>

        <div class="fotohub-field">
            <span class="fotohub-field-label">{l s='Variants' mod='fotohubai'}</span>
            <label class="fotohub-checkbox" for="fh_per_variant">
                <input type="checkbox" id="fh_per_variant" name="fh_per_variant" value="1" form="form-product" />
                {l s='One result per combination' mod='fotohubai'}
            </label>
            <span class="fotohub-help">
                {l s='Generates a separate image for every variant, using its own attributes. Costs credits per variant.' mod='fotohubai'}
            </span>
        </div>
    </div>

    {* Description field checkboxes (feature 8) *}
    <h3 class="fotohub-section-title">{l s='Text fields to generate' mod='fotohubai'}</h3>
    <div class="fotohub-checkbox-grid">
        {assign var='fotohubFieldLabels' value=[
            'title'            => "{l s='Title' mod='fotohubai' js=1}",
            'short_description'=> "{l s='Short description' mod='fotohubai' js=1}",
            'description'      => "{l s='Long description' mod='fotohubai' js=1}",
            'meta_title'       => "{l s='Meta title' mod='fotohubai' js=1}",
            'meta_description' => "{l s='Meta description' mod='fotohubai' js=1}",
            'alt_text'         => "{l s='Image alt text' mod='fotohubai' js=1}",
            'faq'              => "{l s='FAQ' mod='fotohubai' js=1}",
            'json_ld'          => "{l s='JSON-LD structured data' mod='fotohubai' js=1}"
        ]}
        {foreach $fotohub_text_fields as $field}
            <label class="fotohub-checkbox" for="fh_field_{$field|escape:'html':'UTF-8'}">
                <input type="checkbox" id="fh_field_{$field|escape:'html':'UTF-8'}"
                       name="fh_fields[]" value="{$field|escape:'html':'UTF-8'}"
                       form="form-product"
                       {if $field == 'description' || $field == 'meta_description'}checked="checked"{/if} />
                {$fotohubFieldLabels[$field]|default:$field|escape:'html':'UTF-8'}
            </label>
        {/foreach}
    </div>
    <p class="fotohub-help">
        {l s='Used by the Generate Descriptions, Generate Alt Texts and Complete Listing actions.' mod='fotohubai'}
    </p>

    <h3 class="fotohub-section-title">{l s='Image transform options' mod='fotohubai'}</h3>
    <div class="fotohub-field-grid">
        <div class="fotohub-field">
            <label for="fh_background">{l s='Background' mod='fotohubai'}</label>
            <input type="text" id="fh_background" name="fh_background" class="form-control" form="form-product"
                   placeholder="{l s='e.g. clean white studio, #FFFFFF' mod='fotohubai'}" />
            <span class="fotohub-help">{l s='Used by Replace Backgrounds.' mod='fotohubai'}</span>
        </div>
        <div class="fotohub-field">
            <label for="fh_target_object">{l s='Target object' mod='fotohubai'}</label>
            <input type="text" id="fh_target_object" name="fh_target_object" class="form-control" form="form-product"
                   placeholder="{l s='e.g. the sofa cushion' mod='fotohubai'}" />
            <span class="fotohub-help">{l s='Used by Recolor Object.' mod='fotohubai'}</span>
        </div>
        <div class="fotohub-field">
            <label for="fh_recolor_prompt">{l s='New colour' mod='fotohubai'}</label>
            <input type="text" id="fh_recolor_prompt" name="fh_recolor_prompt" class="form-control" form="form-product"
                   placeholder="{l s='e.g. deep navy blue' mod='fotohubai'}" />
            <span class="fotohub-help">{l s='Used by Recolor Object.' mod='fotohubai'}</span>
        </div>
        <div class="fotohub-field">
            <label for="fh_brand_rules">{l s='Brand rules' mod='fotohubai'}</label>
            <input type="text" id="fh_brand_rules" name="fh_brand_rules" class="form-control" form="form-product"
                   placeholder="{l s='e.g. always mention the 2-year warranty' mod='fotohubai'}" />
            <span class="fotohub-help">{l s='Extra instructions applied to every item in the job.' mod='fotohubai'}</span>
        </div>
    </div>

    {* Preset gallery (feature 3) *}
    {if !empty($fotohub_presets)}
        <h3 class="fotohub-section-title">{l s='Preset Gallery' mod='fotohubai'}</h3>
        <input type="hidden" id="fh_preset" name="fh_preset" form="form-product"
               value="{$fotohub_default_preset|escape:'html':'UTF-8'}" />

        {foreach $fotohub_presets as $category => $presets}
            <div class="fotohub-preset-category">
                <h4 class="fotohub-preset-category-title">
                    {$category|replace:'_':' '|capitalize|escape:'html':'UTF-8'}
                    {if $category == 'bundle'}
                        <span class="fotohub-preset-featured">{l s='Recommended' mod='fotohubai'}</span>
                    {/if}
                </h4>
                <div class="fotohub-preset-grid">
                    {foreach $presets as $preset}
                        <button type="button"
                                class="fotohub-preset-card{if $preset.slug == $fotohub_default_preset} is-selected{/if}"
                                data-slug="{$preset.slug|escape:'html':'UTF-8'}"
                                aria-pressed="{if $preset.slug == $fotohub_default_preset}true{else}false{/if}"
                                title="{$preset.description|default:''|escape:'html':'UTF-8'}">
                            {if !empty($preset.thumbnail_url)}
                                <img src="{$preset.thumbnail_url|escape:'html':'UTF-8'}" alt="" loading="lazy" />
                            {else}
                                <span class="fotohub-preset-placeholder" aria-hidden="true">
                                    <svg width="22" height="22" viewBox="0 0 24 24" focusable="false">
                                        <path fill="currentColor" d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2zM8.5 13.5l2.5 3 3.5-4.5 4.5 6H5z"/>
                                    </svg>
                                </span>
                            {/if}
                            <span class="fotohub-preset-name">{$preset.display_name|escape:'html':'UTF-8'}</span>
                        </button>
                    {/foreach}
                </div>
            </div>
        {/foreach}
        <p class="fotohub-help">
            {l s='Click a preset to use it for the next job. Your choice is remembered as the store default.' mod='fotohubai'}
        </p>
    {/if}

    {* Estimate box (feature 4) *}
    <div class="fotohub-estimate">
        <div class="fotohub-field" style="margin:0">
            <label class="fotohub-visually-hidden" for="fh_estimate_kind">{l s='Action to estimate' mod='fotohubai'}</label>
            <select id="fh_estimate_kind" class="form-control">
                <option value="image_generate">{l s='Generate AI Images' mod='fotohubai'}</option>
                <option value="image_edit">{l s='AI Edit Images' mod='fotohubai'}</option>
                <option value="bg_remove">{l s='Remove Backgrounds' mod='fotohubai'}</option>
                <option value="bg_replace">{l s='Replace Backgrounds' mod='fotohubai'}</option>
                <option value="upscale">{l s='Upscale Images' mod='fotohubai'}</option>
                <option value="recolor">{l s='Recolor Object' mod='fotohubai'}</option>
                <option value="description">{l s='Generate Descriptions' mod='fotohubai'}</option>
                <option value="alt_text">{l s='Generate Alt Texts' mod='fotohubai'}</option>
                <option value="complete_listing">{l s='Complete Listing' mod='fotohubai'}</option>
            </select>
        </div>
        <button type="button" class="btn btn-default" id="fotohubEstimateBtn">
            {l s='Estimate cost for selection' mod='fotohubai'}
        </button>
        <div id="fotohubEstimateResult" class="fotohub-estimate-result" role="status" aria-live="polite" hidden>
            {l s='Select products below, then estimate.' mod='fotohubai'}
        </div>
    </div>
</div>

{* ── Local fallback results table ───────────────────────────────────────── *}
{if isset($fotohub_bulk_results) && $fotohub_bulk_results}
    <div class="panel">
        <div class="panel-heading">{l s='Processing Results' mod='fotohubai'}</div>

        {if isset($fotohub_bulk_summary)}
            <div class="fotohub-stats">
                <div class="fotohub-stat fotohub-stat-total">
                    <span class="fotohub-stat-number">{$fotohub_bulk_summary.total|intval}</span>
                    <span class="fotohub-stat-label">{l s='Total' mod='fotohubai'}</span>
                </div>
                <div class="fotohub-stat fotohub-stat-success">
                    <span class="fotohub-stat-number">{$fotohub_bulk_summary.success|intval}</span>
                    <span class="fotohub-stat-label">{l s='Success' mod='fotohubai'}</span>
                </div>
                <div class="fotohub-stat fotohub-stat-error">
                    <span class="fotohub-stat-number">{$fotohub_bulk_summary.error|intval}</span>
                    <span class="fotohub-stat-label">{l s='Errors' mod='fotohubai'}</span>
                </div>
                <div class="fotohub-stat fotohub-stat-skipped">
                    <span class="fotohub-stat-number">{$fotohub_bulk_summary.skipped|intval}</span>
                    <span class="fotohub-stat-label">{l s='Skipped' mod='fotohubai'}</span>
                </div>
            </div>
        {/if}

        <div class="table-responsive">
        <table class="table fotohub-table">
            <thead>
                <tr>
                    <th class="fotohub-col-id">{l s='Product ID' mod='fotohubai'}</th>
                    <th>{l s='Product' mod='fotohubai'}</th>
                    <th class="fotohub-col-status">{l s='Status' mod='fotohubai'}</th>
                    <th>{l s='Message' mod='fotohubai'}</th>
                    <th>{l s='Preview' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_bulk_results as $result}
                    <tr class="fotohub-result-{$result.status|escape:'html':'UTF-8'}">
                        <td class="fotohub-col-id">{$result.id_product|intval}</td>
                        <td>{$result.product_name|default:'-'|escape:'html':'UTF-8'}</td>
                        <td class="fotohub-col-status">
                            {if $result.status == 'success'}
                                <span class="fotohub-status fotohub-status-approved">{l s='Success' mod='fotohubai'}</span>
                            {elseif $result.status == 'error'}
                                <span class="fotohub-status fotohub-status-failed">{l s='Error' mod='fotohubai'}</span>
                            {else}
                                <span class="fotohub-status fotohub-status-pending">{l s='Skipped' mod='fotohubai'}</span>
                            {/if}
                        </td>
                        <td>{$result.message|default:''|escape:'html':'UTF-8'}</td>
                        <td>
                            {if !empty($result.image_url)}
                                <img src="{$result.image_url|escape:'html':'UTF-8'}"
                                     alt="{l s='Generated image' mod='fotohubai'}"
                                     class="fotohub-thumb" loading="lazy" />
                            {else}
                                <span class="fotohub-muted">&mdash;</span>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        </div>
    </div>
{/if}

{* The product list with bulk actions is rendered by the parent controller *}
{$list|default:'' nofilter}

<div class="fotohub-toast-region" id="fotohubToasts" aria-live="polite" aria-atomic="false"></div>

<script type="text/javascript">
(function () {
    'use strict';

    var ajaxUrl = '{$fotohub_bulk_ajax_url|escape:'javascript':'UTF-8'}';
    var token = '{$token|escape:'javascript':'UTF-8'}';

    var i18n = {
        selectFirst: '{l s='Select at least one product in the list below first.' mod='fotohubai' js=1}',
        calculating: '{l s='Calculating…' mod='fotohubai' js=1}',
        products: '{l s='item(s)' mod='fotohubai' js=1}',
        credits: '{l s='credits' mod='fotohubai' js=1}',
        youHave: '{l s='you have' mod='fotohubai' js=1}',
        insufficient: '{l s='Insufficient credits — top up before running this job.' mod='fotohubai' js=1}',
        failed: '{l s='Estimation failed' mod='fotohubai' js=1}',
        presetSelected: '{l s='Preset selected' mod='fotohubai' js=1}',
        failedItems: '{l s='failed' mod='fotohubai' js=1}'
    };

    function toast(message, kind) {
        var region = document.getElementById('fotohubToasts');
        if (!region) { return; }
        var el = document.createElement('div');
        el.className = 'fotohub-toast is-' + (kind || 'info');
        el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        el.textContent = message;
        region.appendChild(el);
        window.setTimeout(function () { el.remove(); }, 5000);
    }

    // ── Preset selection ───────────────────────────────────────────────────
    var presetInput = document.getElementById('fh_preset');
    Array.prototype.forEach.call(document.querySelectorAll('.fotohub-preset-card'), function (card) {
        card.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('.fotohub-preset-card.is-selected'), function (c) {
                c.classList.remove('is-selected');
                c.setAttribute('aria-pressed', 'false');
            });
            card.classList.add('is-selected');
            card.setAttribute('aria-pressed', 'true');
            if (presetInput) { presetInput.value = card.getAttribute('data-slug'); }
            toast(i18n.presetSelected + ': ' + card.getAttribute('data-slug'), 'info');
        });
    });

    // ── Confirmation prompts ───────────────────────────────────────────────
    Array.prototype.forEach.call(document.querySelectorAll('[data-fotohub-confirm]'), function (btn) {
        btn.addEventListener('click', function (event) {
            if (!window.confirm(btn.getAttribute('data-fotohub-confirm'))) {
                event.preventDefault();
            }
        });
    });

    function selectedProductCount() {
        return document.querySelectorAll('input[name="productBox[]"]:checked').length;
    }

    // ── Estimate preflight ─────────────────────────────────────────────────
    var estimateBtn = document.getElementById('fotohubEstimateBtn');
    if (estimateBtn) {
        estimateBtn.addEventListener('click', function () {
            var box = document.getElementById('fotohubEstimateResult');
            var count = selectedProductCount();

            box.hidden = false;
            box.className = 'fotohub-estimate-result';

            if (count === 0) {
                box.textContent = i18n.selectFirst;
                return;
            }

            box.classList.add('is-loading', 'fotohub-skeleton');
            box.textContent = i18n.calculating;

            var kindEl = document.getElementById('fh_estimate_kind');
            var modelEl = document.getElementById('fh_model');
            var numEl = document.getElementById('fh_num_images');
            var numImages = numEl ? numEl.value : 1;

            var params = '&fh_action=estimate'
                + '&token=' + encodeURIComponent(token)
                + '&kind=' + encodeURIComponent(kindEl ? kindEl.value : 'image_generate')
                + '&num_items=' + encodeURIComponent(count)
                + '&model=' + encodeURIComponent(modelEl ? modelEl.value : '')
                + '&num_images=' + encodeURIComponent(numImages);

            fetch(ajaxUrl + params, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    box.classList.remove('is-loading', 'fotohub-skeleton');

                    if (!data.success || !data.estimate) {
                        box.classList.add('is-blocked');
                        box.textContent = data.error || i18n.failed;
                        return;
                    }

                    var e = data.estimate;
                    var total = (e.total_credits !== undefined) ? e.total_credits : '?';
                    var have = (e.available_credits !== undefined) ? e.available_credits : '?';

                    var msg = count + ' ' + i18n.products + ' × ' + numImages
                        + ' = ' + total + ' ' + i18n.credits
                        + ' (' + i18n.youHave + ' ' + have + ')';

                    if (e.sufficient === false) {
                        box.classList.add('is-blocked');
                        box.textContent = msg + ' — ' + i18n.insufficient;
                    } else {
                        box.classList.add('is-ok');
                        box.textContent = msg;
                    }
                })
                .catch(function () {
                    box.classList.remove('is-loading', 'fotohub-skeleton');
                    box.classList.add('is-blocked');
                    box.textContent = i18n.failed;
                });
        });
    }

    // ── Live progress polling for active jobs ──────────────────────────────
    var jobRows = Array.prototype.slice.call(document.querySelectorAll('.fotohub-job-row'));

    if (jobRows.length > 0) {
        window.setInterval(function () {
            jobRows.forEach(function (row) {
                var jobId = row.getAttribute('data-job-id');

                fetch(ajaxUrl + '&fh_action=job_status&token=' + encodeURIComponent(token)
                        + '&job_id=' + encodeURIComponent(jobId), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success || !data.job) { return; }

                        var job = data.job;
                        var textEl = row.querySelector('.fotohub-progress-text');
                        var fillEl = row.querySelector('.fotohub-progress-fill');
                        var barEl = row.querySelector('.fotohub-progress');
                        var statusEl = row.querySelector('.fotohub-job-status');
                        var total = parseInt(job.total_items, 10) || 0;
                        var done = parseInt(job.done_items, 10) || 0;
                        var failed = parseInt(job.failed_items, 10) || 0;

                        if (textEl && total > 0) {
                            textEl.textContent = done + '/' + total
                                + (failed ? ' · ' + failed + ' ' + i18n.failedItems : '');
                        }

                        if (fillEl && total > 0) {
                            fillEl.style.width = Math.round(100 * done / total) + '%';
                        }

                        if (barEl && total > 0) {
                            barEl.setAttribute('aria-valuenow', String(done));
                            barEl.setAttribute('aria-valuemax', String(total));
                        }

                        if (statusEl && job.status) {
                            statusEl.textContent = job.status;
                        }
                    })
                    .catch(function () {});
            });
        }, 10000);
    }
})();
</script>
