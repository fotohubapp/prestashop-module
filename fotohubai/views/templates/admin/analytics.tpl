{**
 * FOTOhub AI — Analytics Dashboard Template
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 *}

<div class="fotohub-admin-header">
    <div class="row">
        <div class="col-lg-12">
            <h2><i class="icon-bar-chart"></i> FOTOhub AI — Analytics</h2>
            <p class="text-muted">
                {l s='Monitor your AI generation usage, credits consumption, and performance metrics.' mod='fotohubai'}
            </p>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-3 col-md-6">
        <div class="fotohub-stat-card">
            <div class="fotohub-stat-card-icon"><i class="icon-image"></i></div>
            <div class="fotohub-stat-card-value">{$fotohub_total_generations|intval}</div>
            <div class="fotohub-stat-card-label">{l s='Total Generations' mod='fotohubai'}</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="fotohub-stat-card">
            <div class="fotohub-stat-card-icon"><i class="icon-credit-card"></i></div>
            <div class="fotohub-stat-card-value">{$fotohub_total_credits|string_format:"%.2f"}</div>
            <div class="fotohub-stat-card-label">{l s='Credits Used' mod='fotohubai'}</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="fotohub-stat-card">
            <div class="fotohub-stat-card-icon"><i class="icon-star"></i></div>
            <div class="fotohub-stat-card-value">
                {if isset($fotohub_cost_breakdown) && $fotohub_cost_breakdown|@count > 0}
                    {$fotohub_cost_breakdown[0].action|escape:'htmlall':'UTF-8'}
                {else}
                    -
                {/if}
            </div>
            <div class="fotohub-stat-card-label">{l s='Top Action' mod='fotohubai'}</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="fotohub-stat-card">
            <div class="fotohub-stat-card-icon"><i class="icon-check-circle"></i></div>
            <div class="fotohub-stat-card-value">
                {if $fotohub_total_generations > 0}
                    {math equation="round((s/t)*100, 1)" s=$fotohub_success_count t=$fotohub_total_generations}%
                {else}
                    0%
                {/if}
            </div>
            <div class="fotohub-stat-card-label">{l s='Success Rate' mod='fotohubai'}</div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-line-chart"></i> {l s='Usage Over Time (Last 30 Days)' mod='fotohubai'}
    </div>
    <div style="padding:15px;">
        <canvas id="fotohub_usage_chart" width="900" height="250" style="width:100%; max-width:100%;"></canvas>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-pie-chart"></i> {l s='Cost Breakdown by Action' mod='fotohubai'}
    </div>
    {if isset($fotohub_cost_breakdown) && $fotohub_cost_breakdown|@count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Action' mod='fotohubai'}</th>
                    <th>{l s='Count' mod='fotohubai'}</th>
                    <th>{l s='Credits Used' mod='fotohubai'}</th>
                    <th>{l s='Percentage' mod='fotohubai'}</th>
                    <th style="width:30%;"></th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_cost_breakdown as $item}
                    <tr>
                        <td>{$item.action|escape:'htmlall':'UTF-8'}</td>
                        <td>{$item.count|intval}</td>
                        <td>{$item.credits|string_format:"%.2f"}</td>
                        <td>{$item.percentage|string_format:"%.1f"}%</td>
                        <td>
                            <div class="progress" style="margin-bottom:0;">
                                <div class="progress-bar progress-bar-primary" role="progressbar"
                                     style="width:{$item.percentage|floatval}%;">
                                </div>
                            </div>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-center text-muted" style="padding:20px;">
            {l s='No usage data yet.' mod='fotohubai'}
        </p>
    {/if}
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-trophy"></i> {l s='Top Products' mod='fotohubai'}
    </div>
    {if isset($fotohub_top_products) && $fotohub_top_products|@count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Product ID' mod='fotohubai'}</th>
                    <th>{l s='Product Name' mod='fotohubai'}</th>
                    <th>{l s='Generations' mod='fotohubai'}</th>
                    <th>{l s='Credits' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_top_products as $product}
                    <tr>
                        <td>{$product.id_product|intval}</td>
                        <td>{$product.name|escape:'htmlall':'UTF-8'}</td>
                        <td>{$product.generations|intval}</td>
                        <td>{$product.credits|string_format:"%.2f"}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-center text-muted" style="padding:20px;">
            {l s='No product data yet.' mod='fotohubai'}
        </p>
    {/if}
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-cogs"></i> {l s='Model Usage' mod='fotohubai'}
    </div>
    {if isset($fotohub_model_usage) && $fotohub_model_usage|@count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Model' mod='fotohubai'}</th>
                    <th>{l s='Times Used' mod='fotohubai'}</th>
                    <th>{l s='Credits' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_model_usage as $model}
                    <tr>
                        <td>{$model.name|escape:'htmlall':'UTF-8'}</td>
                        <td>{$model.count|intval}</td>
                        <td>{$model.credits|string_format:"%.2f"}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-center text-muted" style="padding:20px;">
            {l s='No model usage data yet.' mod='fotohubai'}
        </p>
    {/if}
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-clock-o"></i> {l s='Recent Activity' mod='fotohubai'}
    </div>
    {if isset($fotohub_recent_activity) && $fotohub_recent_activity|@count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Date' mod='fotohubai'}</th>
                    <th>{l s='Product' mod='fotohubai'}</th>
                    <th>{l s='Action' mod='fotohubai'}</th>
                    <th>{l s='Model' mod='fotohubai'}</th>
                    <th>{l s='Credits' mod='fotohubai'}</th>
                    <th>{l s='Status' mod='fotohubai'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $fotohub_recent_activity as $activity}
                    <tr>
                        <td>{$activity.date|escape:'htmlall':'UTF-8'}</td>
                        <td>{$activity.product_name|escape:'htmlall':'UTF-8'}</td>
                        <td>{$activity.action|escape:'htmlall':'UTF-8'}</td>
                        <td>{$activity.model|escape:'htmlall':'UTF-8'}</td>
                        <td>{$activity.credits|string_format:"%.2f"}</td>
                        <td>
                            {if $activity.status == 'success'}
                                <span class="badge badge-success"><i class="icon-check"></i> {l s='Success' mod='fotohubai'}</span>
                            {else}
                                <span class="badge badge-danger"><i class="icon-times"></i> {l s='Failed' mod='fotohubai'}</span>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-center text-muted" style="padding:20px;">
            {l s='No activity yet. Start generating to see your history here.' mod='fotohubai'}
        </p>
    {/if}
</div>

<div class="panel-footer text-right">
    <a href="{$fotohub_analytics_url|escape:'htmlall':'UTF-8'}&ajax=1&action=export&token={$fotohub_token|escape:'html':'UTF-8'}" class="btn btn-default">
        <i class="icon-download"></i> {l s='Export CSV (Last 90 days)' mod='fotohubai'}
    </a>
</div>

<script type="text/javascript">
(function() {
    var dailyUsageRaw = '{$fotohub_daily_usage|escape:"javascript":"UTF-8"}';
    var dailyUsage = [];

    try {
        dailyUsage = JSON.parse(dailyUsageRaw);
    } catch(e) {
        dailyUsage = [];
    }

    if (dailyUsage.length === 0) return;

    var canvas = document.getElementById('fotohub_usage_chart');
    var ctx = canvas.getContext('2d');

    // High DPI support
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    var W = rect.width;
    var H = rect.height;
    var padLeft = 50;
    var padBottom = 40;
    var padTop = 20;
    var padRight = 20;
    var chartW = W - padLeft - padRight;
    var chartH = H - padTop - padBottom;

    // Find max value
    var maxVal = 0;
    for (var i = 0; i < dailyUsage.length; i++) {
        if (dailyUsage[i].count > maxVal) maxVal = dailyUsage[i].count;
    }
    if (maxVal === 0) maxVal = 1;

    var barWidth = Math.max(2, (chartW / dailyUsage.length) - 2);

    // Draw axes
    ctx.strokeStyle = '#ddd';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(padLeft, padTop);
    ctx.lineTo(padLeft, padTop + chartH);
    ctx.lineTo(padLeft + chartW, padTop + chartH);
    ctx.stroke();

    // Y-axis labels
    ctx.fillStyle = '#666';
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'right';
    var ySteps = 4;
    for (var s = 0; s <= ySteps; s++) {
        var yVal = Math.round((maxVal / ySteps) * s);
        var yPos = padTop + chartH - (chartH * (s / ySteps));
        ctx.fillText(yVal.toString(), padLeft - 8, yPos + 4);
        // Grid line
        ctx.strokeStyle = '#f0f0f0';
        ctx.beginPath();
        ctx.moveTo(padLeft, yPos);
        ctx.lineTo(padLeft + chartW, yPos);
        ctx.stroke();
    }

    // Draw bars
    ctx.fillStyle = '#25b9d7';
    for (var i = 0; i < dailyUsage.length; i++) {
        var barH = (dailyUsage[i].count / maxVal) * chartH;
        var x = padLeft + (i * (chartW / dailyUsage.length)) + 1;
        var y = padTop + chartH - barH;
        ctx.fillRect(x, y, barWidth, barH);
    }

    // X-axis labels (show every 5th day)
    ctx.fillStyle = '#666';
    ctx.textAlign = 'center';
    ctx.font = '10px sans-serif';
    for (var i = 0; i < dailyUsage.length; i += 5) {
        var x = padLeft + (i * (chartW / dailyUsage.length)) + barWidth / 2;
        var label = dailyUsage[i].date ? dailyUsage[i].date.substring(5) : '';
        ctx.fillText(label, x, padTop + chartH + 15);
    }

    // Export button
    var exportBtn = document.querySelector('a[href*="action=export"]');
    if (exportBtn) {
        exportBtn.addEventListener('click', function(e) {
            window.location = this.href;
            e.preventDefault();
        });
    }
})();
</script>
