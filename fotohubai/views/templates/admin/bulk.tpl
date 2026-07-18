{**
 * FOTOhub AI — Bulk Processing Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-12">
            <h2><i class="icon-magic"></i> FOTOhub AI — Bulk Processing</h2>
            <p class="text-muted">
                {l s='Select products and choose an action to process them in bulk.' mod='fotohubai'}
            </p>
        </div>
    </div>
</div>

{if isset($fotohub_bulk_results) && $fotohub_bulk_results}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-check-circle"></i> {l s='Processing Results' mod='fotohubai'}
        </div>

        {if isset($fotohub_bulk_summary)}
            <div class="row fotohub-summary">
                <div class="col-lg-3">
                    <div class="fotohub-stat fotohub-stat-total">
                        <span class="fotohub-stat-number">{$fotohub_bulk_summary.total|intval}</span>
                        <span class="fotohub-stat-label">{l s='Total' mod='fotohubai'}</span>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="fotohub-stat fotohub-stat-success">
                        <span class="fotohub-stat-number">{$fotohub_bulk_summary.success|intval}</span>
                        <span class="fotohub-stat-label">{l s='Success' mod='fotohubai'}</span>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="fotohub-stat fotohub-stat-error">
                        <span class="fotohub-stat-number">{$fotohub_bulk_summary.error|intval}</span>
                        <span class="fotohub-stat-label">{l s='Errors' mod='fotohubai'}</span>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="fotohub-stat fotohub-stat-skipped">
                        <span class="fotohub-stat-number">{$fotohub_bulk_summary.skipped|intval}</span>
                        <span class="fotohub-stat-label">{l s='Skipped' mod='fotohubai'}</span>
                    </div>
                </div>
            </div>
        {/if}

        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Product ID' mod='fotohubai'}</th>
                    <th>{l s='Product' mod='fotohubai'}</th>
                    <th>{l s='Status' mod='fotohubai'}</th>
                    <th>{l s='Message' mod='fotohubai'}</th>
                    <th>{l s='Preview' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_bulk_results as $result}
                    <tr class="fotohub-result-{$result.status|escape:'htmlall':'UTF-8'}">
                        <td>{$result.id_product|intval}</td>
                        <td>{$result.product_name|escape:'htmlall':'UTF-8'|default:'-'}</td>
                        <td>
                            {if $result.status == 'success'}
                                <span class="badge badge-success"><i class="icon-check"></i> {l s='Success' mod='fotohubai'}</span>
                            {elseif $result.status == 'error'}
                                <span class="badge badge-danger"><i class="icon-times"></i> {l s='Error' mod='fotohubai'}</span>
                            {else}
                                <span class="badge badge-warning"><i class="icon-forward"></i> {l s='Skipped' mod='fotohubai'}</span>
                            {/if}
                        </td>
                        <td>{$result.message|escape:'htmlall':'UTF-8'}</td>
                        <td>
                            {if isset($result.image_url) && $result.image_url}
                                <img src="{$result.image_url|escape:'htmlall':'UTF-8'}"
                                     alt="Generated"
                                     class="fotohub-thumbnail" />
                            {else}
                                -
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}

{* The product list with bulk actions is rendered by the parent controller *}
{$list|default:''}
