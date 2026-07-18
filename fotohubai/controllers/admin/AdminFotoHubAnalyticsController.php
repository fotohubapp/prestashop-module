<?php
/**
 * FOTOhub AI Analytics Controller
 *
 * Admin controller for usage analytics dashboard — credits, generations, costs.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubAnalytics.php';

class AdminFotoHubAnalyticsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — Analytics');
    }

    /**
     * Initialize page content
     */
    public function initContent(): void
    {
        parent::initContent();

        // Handle AJAX requests
        if (Tools::getValue('ajax') && Tools::getValue('action')) {
            $action = Tools::getValue('action');

            switch ($action) {
                case 'getChartData':
                    $this->ajaxProcessGetChartData();
                    break;
                case 'export':
                    $this->ajaxProcessExport();
                    break;
                default:
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Unknown action']);
                    exit;
            }

            return;
        }

        // Get analytics data
        $totalGenerations = FotoHubAnalytics::getTotalGenerations();
        $totalCredits = FotoHubAnalytics::getTotalCreditsUsed();
        $costBreakdown = FotoHubAnalytics::getCostBreakdown();
        $topProducts = FotoHubAnalytics::getTopProducts();
        $modelUsage = FotoHubAnalytics::getModelUsage();
        $dailyUsage = FotoHubAnalytics::getDailyUsage(30);
        $recentActivity = FotoHubAnalytics::getRecentActivity();

        $this->context->smarty->assign([
            'fotohub_total_generations' => $totalGenerations,
            'fotohub_total_credits' => $totalCredits,
            'fotohub_cost_breakdown' => $costBreakdown,
            'fotohub_top_products' => $topProducts,
            'fotohub_model_usage' => $modelUsage,
            'fotohub_daily_usage' => json_encode($dailyUsage),
            'fotohub_recent_activity' => $recentActivity,
            'fotohub_analytics_url' => $this->context->link->getAdminLink('AdminFotoHubAnalytics'),
        ]);

        $this->setTemplate('analytics.tpl');
    }

    /**
     * AJAX: Get chart data for a given time range
     */
    public function ajaxProcessGetChartData(): void
    {
        header('Content-Type: application/json');

        $days = (int) Tools::getValue('days', 30);

        if ($days < 1 || $days > 365) {
            $days = 30;
        }

        try {
            $dailyUsage = FotoHubAnalytics::getDailyUsage($days);
            $modelUsage = FotoHubAnalytics::getModelUsage($days);
            $costBreakdown = FotoHubAnalytics::getCostBreakdown($days);

            echo json_encode([
                'success' => true,
                'daily_usage' => $dailyUsage,
                'model_usage' => $modelUsage,
                'cost_breakdown' => $costBreakdown,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: Export analytics as CSV
     */
    public function ajaxProcessExport(): void
    {
        $days = (int) Tools::getValue('days', 90);

        if ($days < 1 || $days > 365) {
            $days = 90;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fotohub-analytics-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo FotoHubAnalytics::exportCsv($days);

        exit;
    }

    /**
     * Set the admin template directory
     */
    public function setMedia($isNewTheme = false): bool
    {
        parent::setMedia($isNewTheme);

        $this->addCSS(_PS_MODULE_DIR_ . 'fotohubai/views/css/admin.css');

        return true;
    }

    /**
     * Override template directory to point to module views
     */
    public function setTemplate($template, $params = [], $locale = null): void
    {
        if (file_exists(_PS_MODULE_DIR_ . 'fotohubai/views/templates/admin/' . $template)) {
            $this->context->smarty->assign('module_template_dir', _PS_MODULE_DIR_ . 'fotohubai/views/templates/admin/');
            parent::setTemplate(
                _PS_MODULE_DIR_ . 'fotohubai/views/templates/admin/' . $template
            );
        } else {
            parent::setTemplate($template, $params, $locale);
        }
    }
}
