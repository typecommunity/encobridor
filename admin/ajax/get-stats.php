<?php
/**
 * Cloaker Pro - AJAX Get Stats
 * Handler para obter estatísticas via AJAX
 */

require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';
require_once '../../core/Analytics.php';
require_once '../../core/Campaign.php';
require_once '../../core/GeoIP.php';
require_once '../../core/Utils.php';

// Verificar se é requisição AJAX
if (!Utils::isAjax()) {
    Utils::jsonResponse(['error' => 'Invalid request'], 400);
}

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    Utils::jsonResponse(['error' => 'Unauthorized'], 401);
}

try {
    $analytics = new Analytics();
    $campaign = new Campaign();
    $geoIP = new GeoIP();
    
    // Obter parâmetros
    $type = $_GET['type'] ?? 'dashboard';
    $period = $_GET['period'] ?? 'today';
    $campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    $response = [];
    
    switch ($type) {
        case 'dashboard':
            // Estatísticas gerais do dashboard
            $response = [
                'total_visitors' => $analytics->getTotalVisitors($period),
                'unique_visitors' => $analytics->getUniqueVisitors($period),
                'total_campaigns' => $campaign->countCampaigns(),
                'active_campaigns' => $campaign->countCampaigns(['status' => 'active']),
                'safe_redirects' => $analytics->getRedirects('safe', $period),
                'money_redirects' => $analytics->getRedirects('money', $period),
                'bot_blocks' => $analytics->getBotBlocks($period),
                'vpn_blocks' => $analytics->getVpnBlocks($period),
                'conversion_rate' => $analytics->getConversionRate($period),
                'hourly_traffic' => $analytics->getHourlyTraffic($period),
                'top_countries' => $analytics->getTopCountries(10, $period),
                'device_breakdown' => $analytics->getDeviceBreakdown($period),
                'top_referrers' => $analytics->getTopReferrers(10, $period),
                'recent_visitors' => $analytics->getRecentVisitors(20)
            ];
            break;
            
        case 'campaign':
            // Estatísticas de campanha específica
            if (!$campaignId) {
                Utils::jsonResponse(['error' => 'Campaign ID required'], 400);
            }
            
            $camp = $campaign->get($campaignId);
            if (!$camp) {
                Utils::jsonResponse(['error' => 'Campaign not found'], 404);
            }
            
            $stats = $analytics->getCampaignStats($campaignId, $period);
            $response = [
                'campaign' => $camp,
                'stats' => $stats,
                'hourly_traffic' => $analytics->getCampaignHourlyTraffic($campaignId, $period),
                'daily_traffic' => $analytics->getCampaignDailyTraffic($campaignId, 30),
                'top_countries' => $analytics->getCampaignTopCountries($campaignId, 10, $period),
                'device_breakdown' => $analytics->getCampaignDeviceBreakdown($campaignId, $period),
                'referrers' => $analytics->getCampaignReferrers($campaignId, 10, $period),
                'conversion_funnel' => [
                    'visitors' => $stats['total'],
                    'unique' => $stats['unique'],
                    'money_page' => $stats['money'],
                    'conversions' => $stats['conversions']
                ]
            ];
            break;
            
        case 'realtime':
            // Estatísticas em tempo real
            $response = [
                'active_visitors' => $analytics->getActiveVisitors(),
                'visitors_last_5min' => $analytics->getVisitorsLastMinutes(5),
                'visitors_last_15min' => $analytics->getVisitorsLastMinutes(15),
                'current_campaigns' => $analytics->getCurrentActiveCampaigns(),
                'recent_events' => $analytics->getRecentEvents(50),
                'server_time' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'geographic':
            // Dados geográficos
            $response = [
                'world_map_data' => $analytics->getWorldMapData($period),
                'top_countries' => $analytics->getTopCountries(20, $period),
                'top_cities' => $analytics->getTopCities(20, $period),
                'top_regions' => $analytics->getTopRegions(20, $period)
            ];
            break;
            
        case 'devices':
            // Análise de dispositivos
            $response = [
                'device_types' => $analytics->getDeviceBreakdown($period),
                'browsers' => $analytics->getBrowserBreakdown($period),
                'operating_systems' => $analytics->getOSBreakdown($period),
                'screen_resolutions' => $analytics->getScreenResolutions($period),
                'mobile_devices' => $analytics->getMobileDevices($period)
            ];
            break;
            
        case 'traffic_sources':
            // Fontes de tráfego
            $response = [
                'referrers' => $analytics->getTopReferrers(20, $period),
                'search_engines' => $analytics->getSearchEngines($period),
                'social_media' => $analytics->getSocialMediaSources($period),
                'direct_traffic' => $analytics->getDirectTraffic($period),
                'utm_campaigns' => $analytics->getUTMCampaigns($period)
            ];
            break;
            
        case 'security':
            // Análise de segurança
            $response = [
                'bot_detections' => $analytics->getBotDetections($period),
                'vpn_detections' => $analytics->getVpnDetections($period),
                'suspicious_ips' => $analytics->getSuspiciousIPs($period),
                'blocked_countries' => $analytics->getBlockedCountries($period),
                'risk_scores' => $analytics->getRiskScoreDistribution($period),
                'security_events' => $analytics->getSecurityEvents($period)
            ];
            break;
            
        case 'performance':
            // Métricas de performance
            $response = [
                'page_load_times' => $analytics->getPageLoadTimes($period),
                'redirect_times' => $analytics->getRedirectTimes($period),
                'cache_hit_rate' => $analytics->getCacheHitRate($period),
                'error_rates' => $analytics->getErrorRates($period),
                'uptime' => $analytics->getUptime($period)
            ];
            break;
            
        case 'export':
            // Preparar dados para exportação
            $format = $_GET['format'] ?? 'json';
            $data = $analytics->exportData($campaignId, $period, $startDate, $endDate);
            
            if ($format === 'csv') {
                // Converter para CSV e enviar
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="cloaker-stats-' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                fputcsv($output, array_keys($data[0])); // Headers
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
                fclose($output);
                exit;
            } else {
                $response = $data;
            }
            break;
            
        case 'comparison':
            // Comparação entre períodos ou campanhas
            $compareWith = $_GET['compare_with'] ?? 'previous_period';
            $currentStats = $analytics->getPeriodStats($period, $campaignId);
            $compareStats = $analytics->getComparisonStats($compareWith, $period, $campaignId);
            
            $response = [
                'current' => $currentStats,
                'compare' => $compareStats,
                'changes' => [
                    'visitors' => $analytics->calculateChange($currentStats['visitors'], $compareStats['visitors']),
                    'conversions' => $analytics->calculateChange($currentStats['conversions'], $compareStats['conversions']),
                    'safe_rate' => $analytics->calculateChange($currentStats['safe_rate'], $compareStats['safe_rate']),
                    'money_rate' => $analytics->calculateChange($currentStats['money_rate'], $compareStats['money_rate'])
                ]
            ];
            break;
            
        default:
            Utils::jsonResponse(['error' => 'Invalid stats type'], 400);
    }
    
    // Adicionar metadata
    $response['_meta'] = [
        'generated_at' => date('c'),
        'period' => $period,
        'type' => $type,
        'user' => $auth->getCurrentUser()['username']
    ];
    
    // Cache response for 1 minute
    header('Cache-Control: private, max-age=60');
    
    Utils::jsonResponse($response);
    
} catch (Exception $e) {
    Utils::logError('Error getting stats: ' . $e->getMessage());
    Utils::jsonResponse(['error' => 'Erro ao obter estatísticas'], 500);
}