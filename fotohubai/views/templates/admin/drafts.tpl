{**
 * FOTOhub AI — Drafts Review Template
 *
 * Before/after comparison (images) and per-field diff (text) with per-item
 * Approve/Reject, bulk approve/reject of the checked rows, and approve-all.
 * Only approval writes to the live product.
 *
 * Every product-derived string is escaped — product titles and AI output are
 * untrusted input.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-page-header">
    <div class="fotohub-page-header-main">
        <h2 class="fotohub-page-title">
            <svg class="fotohub-icon" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M7.6 13.4 4.2 10l-1.4 1.4 4.8 4.8L17.2 6.6 15.8 5.2z"/>
            </svg>
            {l s='Drafts Review' mod='fotohubai'}
        </h2>
        <p class="fotohub-page-subtitle">
            {l s='AI results wait here as drafts. Review each one and approve it to apply it to the live product. Nothing is published without your approval.' mod='fotohubai'}
        </p>
    </div>
</div>

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

{if !$fotohub_can_edit}
    <div class="alert alert-info" role="status">
        {l s='You have read-only access to this page. Approving or rejecting drafts requires edit permission on the FOTOhub AI menu.' mod='fotohubai'}
    </div>
{/if}

{* ── Status filter tabs ─────────────────────────────────────────────────── *}
<div class="fotohub-toolbar">
    <nav class="fotohub-filter-tabs" aria-label="{l s='Filter drafts by status' mod='fotohubai'}">
        {assign var='fotohubStatusLabels' value=[
            'pending'  => "{l s='Pending' mod='fotohubai' js=1}",
            'approved' => "{l s='Approved' mod='fotohubai' js=1}",
            'rejected' => "{l s='Rejected' mod='fotohubai' js=1}",
            'failed'   => "{l s='Failed' mod='fotohubai' js=1}",
            'all'      => "{l s='All' mod='fotohubai' js=1}"
        ]}
        {foreach $fotohubStatusLabels as $slug => $label}
            <a class="fotohub-filter-tab{if $fotohub_draft_status == $slug} is-active{/if}"
               href="{$fotohub_drafts_url|escape:'html':'UTF-8'}&draft_status={$slug|escape:'url'}"
               {if $fotohub_draft_status == $slug}aria-current="page"{/if}>
                {$label|escape:'html':'UTF-8'}
                {if isset($fotohub_counts[$slug])}
                    <span class="fotohub-count">{$fotohub_counts[$slug]|intval}</span>
                {/if}
            </a>
        {/foreach}
    </nav>
</div>

{if empty($fotohub_drafts)}
    {* ── Empty state with a primary action ──────────────────────────────── *}
    <div class="panel fotohub-empty-state">
        <svg width="44" height="44" viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="fotohub-empty-icon">
            <path fill="currentColor" d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 16H5V5h14v14zM7 12h10v2H7zm0-4h10v2H7zm0 8h6v2H7z"/>
        </svg>
        <h3>{l s='Nothing to review here' mod='fotohubai'}</h3>
        <p>{l s='Run a bulk job and the generated images and descriptions will queue up on this page for approval.' mod='fotohubai'}</p>
        <a href="{$link->getAdminLink('AdminFotoHubBulk')|escape:'html':'UTF-8'}" class="btn btn-primary">
            {l s='Open Bulk Processing' mod='fotohubai'}
        </a>
    </div>
{else}
<form method="post" action="{$fotohub_drafts_url|escape:'html':'UTF-8'}" id="fotohubDraftsForm">
    <input type="hidden" name="token" value="{$fotohub_token|escape:'html':'UTF-8'}" />
    <input type="hidden" name="draft_status" value="{$fotohub_draft_status|escape:'html':'UTF-8'}" />

    <div class="panel">
        <div class="panel-heading">
            {l s='Drafts' mod='fotohubai'}
            <span class="badge">{$fotohub_pending_count|intval} {l s='pending' mod='fotohubai'}</span>
        </div>

        {if $fotohub_can_edit}
            <div class="fotohub-bulk-bar">
                <button type="submit" name="bulkApproveDrafts" class="btn btn-success btn-sm"
                        data-fotohub-confirm="{l s='Approve the selected drafts and apply them to your live products?' mod='fotohubai'}">
                    {l s='Approve selected' mod='fotohubai'}
                </button>
                <button type="submit" name="bulkRejectDrafts" class="btn btn-default btn-sm"
                        data-fotohub-confirm="{l s='Reject the selected drafts?' mod='fotohubai'}">
                    {l s='Reject selected' mod='fotohubai'}
                </button>
                <span class="fotohub-bulk-sep" aria-hidden="true"></span>
                <button type="submit" name="approveAllDrafts" class="btn btn-default btn-sm"
                        data-fotohub-confirm="{l s='Approve ALL pending drafts and apply them to your live products?' mod='fotohubai'}">
                    {l s='Approve all pending' mod='fotohubai'}
                </button>
                <span class="fotohub-bulk-count" id="fotohubSelectedCount" aria-live="polite"></span>
            </div>
        {/if}

        <div class="table-responsive">
        <table class="table fotohub-table">
            <thead>
                <tr>
                    {if $fotohub_can_edit}
                        <th class="fotohub-col-check">
                            <input type="checkbox" id="fotohubCheckAll"
                                   aria-label="{l s='Select all drafts on this page' mod='fotohubai'}" />
                        </th>
                    {/if}
                    <th class="fotohub-col-id">{l s='ID' mod='fotohubai'}</th>
                    <th>{l s='Product' mod='fotohubai'}</th>
                    <th class="fotohub-col-type">{l s='Type' mod='fotohubai'}</th>
                    <th>{l s='Before (live)' mod='fotohubai'}</th>
                    <th>{l s='After (draft)' mod='fotohubai'}</th>
                    <th class="fotohub-col-status">{l s='Status' mod='fotohubai'}</th>
                    <th class="fotohub-col-actions">{l s='Actions' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_drafts as $draft}
                    <tr>
                        {if $fotohub_can_edit}
                            <td class="fotohub-col-check">
                                {if $draft.status == 'pending'}
                                    <input type="checkbox" name="draft_box[]" value="{$draft.id_draft|intval}"
                                           class="fotohub-draft-check"
                                           aria-label="{l s='Select draft' mod='fotohubai'} #{$draft.id_draft|intval}" />
                                {/if}
                            </td>
                        {/if}
                        <td class="fotohub-col-id">{$draft.id_draft|intval}</td>
                        <td>
                            <a href="{$draft.product_url|escape:'html':'UTF-8'}" class="fotohub-product-link">
                                {$draft.product_name|default:''|escape:'html':'UTF-8'}
                            </a>
                            <span class="fotohub-meta">
                                #{$draft.id_product|intval}
                                {if !empty($draft.kind)} &middot; {$draft.kind|escape:'html':'UTF-8'}{/if}
                            </span>
                            {if !empty($draft.combination_name)}
                                <span class="fotohub-variant-tag">
                                    {$draft.combination_name|escape:'html':'UTF-8'}
                                </span>
                            {/if}
                        </td>
                        <td class="fotohub-col-type">
                            {if $draft.type == 'image'}
                                <span class="fotohub-tag fotohub-tag-image">
                                    <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path fill="currentColor" d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2zM8.5 13.5l2.5 3 3.5-4.5 4.5 6H5z"/>
                                    </svg>
                                    {l s='Image' mod='fotohubai'}
                                </span>
                            {else}
                                <span class="fotohub-tag fotohub-tag-text">
                                    <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm2 16H8v-2h8zm0-4H8v-2h8zm-3-6V3.5L18.5 9z"/>
                                    </svg>
                                    {l s='Text' mod='fotohubai'}
                                </span>
                            {/if}
                        </td>

                        {* ── BEFORE ─────────────────────────────────────── *}
                        <td class="fotohub-compare-cell">
                            {if $draft.type == 'image'}
                                {if !empty($draft.before.image_url)}
                                    <img src="{$draft.before.image_url|escape:'html':'UTF-8'}"
                                         alt="{l s='Current product image' mod='fotohubai'}"
                                         class="fotohub-thumb" loading="lazy" />
                                {else}
                                    <span class="fotohub-muted">{l s='No image yet' mod='fotohubai'}</span>
                                {/if}
                            {else}
                                {if !empty($draft.diff)}
                                    <dl class="fotohub-diff">
                                        {foreach $draft.diff as $row}
                                            <dt>{$row.label|escape:'html':'UTF-8'}</dt>
                                            <dd class="fotohub-diff-before">
                                                {if $row.before}
                                                    {$row.before|strip_tags|truncate:160|escape:'html':'UTF-8'}
                                                {else}
                                                    <span class="fotohub-muted">{l s='empty' mod='fotohubai'}</span>
                                                {/if}
                                            </dd>
                                        {/foreach}
                                    </dl>
                                {else}
                                    <span class="fotohub-muted">{l s='No comparable fields' mod='fotohubai'}</span>
                                {/if}
                            {/if}
                        </td>

                        {* ── AFTER ──────────────────────────────────────── *}
                        <td class="fotohub-compare-cell">
                            {if $draft.type == 'image'}
                                {foreach $draft.payload.image_urls|default:[] as $url}
                                    <img src="{$url|escape:'html':'UTF-8'}"
                                         alt="{l s='Proposed AI image' mod='fotohubai'}"
                                         class="fotohub-thumb fotohub-thumb-new" loading="lazy" />
                                {/foreach}
                            {else}
                                {if !empty($draft.diff)}
                                    <dl class="fotohub-diff">
                                        {foreach $draft.diff as $row}
                                            <dt>
                                                {$row.label|escape:'html':'UTF-8'}
                                                {if $row.changed}
                                                    <span class="fotohub-diff-flag">{l s='changed' mod='fotohubai'}</span>
                                                {/if}
                                            </dt>
                                            <dd class="fotohub-diff-after">
                                                {$row.after|strip_tags|truncate:160|escape:'html':'UTF-8'}
                                            </dd>
                                        {/foreach}
                                    </dl>
                                {/if}
                            {/if}
                        </td>

                        <td class="fotohub-col-status">
                            {if $draft.status == 'pending'}
                                <span class="fotohub-status fotohub-status-pending">{l s='Pending' mod='fotohubai'}</span>
                            {elseif $draft.status == 'approved'}
                                <span class="fotohub-status fotohub-status-approved">{l s='Approved' mod='fotohubai'}</span>
                            {elseif $draft.status == 'rejected'}
                                <span class="fotohub-status fotohub-status-rejected">{l s='Rejected' mod='fotohubai'}</span>
                            {else}
                                <span class="fotohub-status fotohub-status-failed">{l s='Failed' mod='fotohubai'}</span>
                                {if !empty($draft.error_message)}
                                    <span class="fotohub-error-text">
                                        {$draft.error_message|truncate:140|escape:'html':'UTF-8'}
                                    </span>
                                {/if}
                            {/if}
                        </td>

                        <td class="fotohub-col-actions">
                            {if $draft.status == 'pending' && $fotohub_can_edit}
                                <button type="submit" name="approveDraft" value="1"
                                        class="btn btn-success btn-xs"
                                        formaction="{$fotohub_drafts_url|escape:'html':'UTF-8'}"
                                        onclick="document.getElementById('fotohubDraftId').value='{$draft.id_draft|intval}';">
                                    {l s='Approve' mod='fotohubai'}
                                </button>
                                <button type="submit" name="rejectDraft" value="1"
                                        class="btn btn-default btn-xs"
                                        onclick="document.getElementById('fotohubDraftId').value='{$draft.id_draft|intval}';">
                                    {l s='Reject' mod='fotohubai'}
                                </button>
                            {else}
                                <span class="fotohub-muted">&mdash;</span>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        </div>

        {* Carries the id for the single-row Approve/Reject buttons *}
        <input type="hidden" name="id_draft" id="fotohubDraftId" value="" />

        {* ── Pagination ─────────────────────────────────────────────────── *}
        {if $fotohub_draft_page > 1 || $fotohub_draft_has_next}
            <nav class="fotohub-pagination" aria-label="{l s='Draft pages' mod='fotohubai'}">
                {if $fotohub_draft_page > 1}
                    <a class="btn btn-default btn-sm"
                       href="{$fotohub_drafts_url|escape:'html':'UTF-8'}&draft_status={$fotohub_draft_status|escape:'url'}&draft_page={$fotohub_draft_page-1}">
                        {l s='Previous' mod='fotohubai'}
                    </a>
                {/if}
                <span class="fotohub-page-indicator">
                    {l s='Page' mod='fotohubai'} {$fotohub_draft_page|intval}
                </span>
                {if $fotohub_draft_has_next}
                    <a class="btn btn-default btn-sm"
                       href="{$fotohub_drafts_url|escape:'html':'UTF-8'}&draft_status={$fotohub_draft_status|escape:'url'}&draft_page={$fotohub_draft_page+1}">
                        {l s='Next' mod='fotohubai'}
                    </a>
                {/if}
            </nav>
        {/if}
    </div>
</form>
{/if}

<script type="text/javascript">
(function () {
    'use strict';

    // Select-all + live selection counter
    var checkAll = document.getElementById('fotohubCheckAll');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.fotohub-draft-check'));
    var counter = document.getElementById('fotohubSelectedCount');
    var selectedLabel = '{l s='%d selected' mod='fotohubai' js=1}';

    function updateCount() {
        if (!counter) { return; }
        var n = boxes.filter(function (b) { return b.checked; }).length;
        counter.textContent = n > 0 ? selectedLabel.replace('%d', n) : '';
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = checkAll.checked; });
            updateCount();
        });
    }

    boxes.forEach(function (b) { b.addEventListener('change', updateCount); });

    // Confirmation prompts for destructive/bulk actions. Once confirmed the
    // table is swapped for a skeleton: approving writes to live products and
    // the round trip can take several seconds per draft.
    var table = document.querySelector('.fotohub-table');

    function showSkeleton() {
        if (!table || !table.tBodies.length) { return; }

        var columns = table.tHead && table.tHead.rows.length
            ? table.tHead.rows[0].cells.length
            : 8;
        var body = table.tBodies[0];

        body.setAttribute('aria-busy', 'true');
        body.textContent = '';

        for (var r = 0; r < 4; r++) {
            var row = body.insertRow();
            row.className = 'fotohub-skeleton-row';
            var cell = row.insertCell();
            cell.colSpan = columns;
            var bar = document.createElement('span');
            bar.className = 'fotohub-skeleton';
            cell.appendChild(bar);
        }
    }

    Array.prototype.forEach.call(
        document.querySelectorAll('[data-fotohub-confirm]'),
        function (btn) {
            btn.addEventListener('click', function (event) {
                if (!window.confirm(btn.getAttribute('data-fotohub-confirm'))) {
                    event.preventDefault();
                }
            });
        }
    );

    // One submit handler covers the bulk bar and the per-row Approve/Reject
    // buttons. The swap is deferred to the next tick: the per-row buttons live
    // inside the tbody, and removing the clicked submit button before the
    // browser has serialised the form would drop its name from the POST.
    var form = document.getElementById('fotohubDraftsForm');

    if (form) {
        form.addEventListener('submit', function () {
            window.setTimeout(showSkeleton, 0);
        });
    }
})();
</script>
