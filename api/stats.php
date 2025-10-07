<?php
/**
 * Cloaker Pro - Public Stats API
 * API pública para estatísticas
 */

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Analytics.php';
require_once '../core/Campaign.php';
require_once '../core/Utils.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Verificar API Key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
    if (defined('API_KEY_REQUIRED') && API_KEY_REQUIRED) {
        if (!$apiKey || $apiKey !== API_KEY) {
            Utils::jsonResponse([
                'error' => 'Invalid or missing API key',
                'code' => 'AUTH_REQUIRED'
            ], 401);
        }
    }
    
    // Rate limiting
    $clientIP = Utils::getRealIP();
    if (!Utils::rateLimit('api_stats_' . $clientIP, 60, 60)) { // 60 requests per minute
        Utils::jsonResponse([
            'error' => 'Rate limit exceeded. Please try again later.',
            'code' => 'RATE_LIMIT'
        ], 429);
    }
    
    // Parâmetros
    $campaignSlug = $_GET['campaign'] ?? null;
    $period = $_GET['period'] ?? 'today';
    $metric = $_GET['metric'] ?? 'overview';
    $format = $_GET['format'] ?? 'json';
    
    // Validar período
    $validPeriods = ['today', 'yesterday', 'last7days', 'last30days', 'thismonth', 'lastmonth', 'all'];
    if (!in_array($period, $validPeriods)) {
        Utils::jsonResponse([
            'error' => 'Invalid period. Valid options: ' . implode(', ', $validPeriods),
            'code' => 'INVALID_PERIOD'
        ], 400);
    }
    
    $analytics = new Analytics();
    $campaign = new Campaign();
    
    // Se uma campanha específica foi solicitada
    if ($campaignSlug) {
        $camp = $campaign->getBySlug($campaignSlug);
        if (!$camp) {
            Utils::jsonResponse([
                'error' => 'Campaign not found',
                'code' => 'CAMPAIGN_NOT_FOUND'
            ], 404);
        }
        
        $campaignId = $camp['id'];
        $stats = getCampaignStats($analytics, $campaignId, $period, $metric);
        $stats['campaign'] = [
            'name' => $camp['name'],
            'slug' => $camp['slug'],
            'status' => $camp['status']
        ];
    } else {
        // Estatísticas globais
        $stats = getGlobalStats($analytics, $period, $metric);
    }
    
    // Adicionar metadata
    $response = [
        'success' => true,
        'data' => $stats,
        'meta' => [
            'period' => $period,
            'metric' => $metric,
            'generated_at' => date('c'),
            'cache_expires' => date('c', strtotime('+5 minutes'))
        ]
    ];
    
    // Formato de resposta
    if ($format === 'csv') {
        outputCSV($stats);
    } else {
        // Cache headers (5 minutos)
        header('Cache-Control: public, max-age=300');
        header('X-RateLimit-Limit: 60');
        header('X-RateLimit-Remaining: ' . (60 - Utils::rateLimit('check_' . $clientIP, 60, 60, false)));
        
        Utils::jsonResponse($response);
    }
    
} catch (Exception $e) {
    Utils::logError('Stats API Error: ' . $e->getMessage());
    Utils::jsonResponse([
        'error' => 'Internal server error',
        'code' => 'SERVER_ERROR'
    ], 500);
}

/**
 * Obter estatísticas de campanha
 */
function getCampaignStats($analytics, $campaignId, $period, $metric) {
    switch ($metric) {
        case 'overview':
            return [
                'total_visitors' => $analytics->getCampaignVisitors($campaignId, $period),
                'unique_visitors' => $analytics->getCampaignUniqueVisitors($campaignId, $period),
                'safe_redirects' => $analytics->getCampaignRedirects($campaignId, 'safe', $period),
                'money_redirects' => $analytics->getCampaignRedirects($campaignId, 'money', $period),
                'bot_blocks' => $analytics->getCampaignBotBlocks($campaignId, $period),
                'conversion_rate' => $analytics->getCampaignConversionRate($campaignId, $period)
            ];
            
        case 'geographic':
            return [
                'top_countries' => $analytics->getCampaignTopCountries($campaignId, 10, $period),
                'top_cities' => $analytics->getCampaignTopCities($campaignId, 10, $period)
            ];
            
        case 'devices':
            return [
                'device_types' => $analytics->getCampaignDeviceBreakdown($campaignId, $period),
                'browsers' => $analytics->getCampaignBrowsers($campaignId, $period),
                'operating_systems' => $analytics->getCampaignOS($campaignId, $period)
            ];
            
        case 'traffic':
            return [
                'hourly' => $analytics->getCampaignHourlyTraffic($campaignId, $period),
                'daily' => $analytics->getCampaignDailyTraffic($campaignId, 30),
                'referrers' => $analytics->getCampaignReferrers($campaignId, 10, $period)
            ];
            
        case 'performance':
            $stats = $analytics->getCampaignStats($campaignId, $period);
            return [
                'visitors' => $stats['total'],
                'unique_visitors' => $stats['unique'],
                'safe_page_rate' => $stats['total'] > 0 ? round(($stats['safe'] / $stats['total']) * 100, 2) : 0,
                'money_page_rate' => $stats['total'] > 0 ? round(($stats['money'] / $stats['total']) * 100, 2) : 0,
                'bot_detection_rate' => $stats['total'] > 0 ? round(($stats['bots'] / $stats['total']) * 100, 2) : 0,
                'conversion_rate' => $stats['total'] > 0 ? round(($stats['conversions'] / $stats['total']) * 100, 2) : 0
            ];
            
        default:
            return getCampaignStats($analytics, $campaignId, $period, 'overview');
    }
}

/**
 * Obter estatísticas globais
 */
function getGlobalStats($analytics, $period, $metric) {
    switch ($metric) {
        case 'overview':
            return [
                'total_visitors' => $analytics->getTotalVisitors($period),
                'unique_visitors' => $analytics->getUniqueVisitors($period),
                'total_campaigns' => $analytics->getActiveCampaigns(),
                'safe_redirects' => $analytics->getRedirects('safe', $period),
                'money_redirects' => $analytics->getRedirects('money', $period),
                'bot_blocks' => $analytics->getBotBlocks($period),
                'average_conversion_rate' => $analytics->getConversionRate($period)
            ];
            
        case 'campaigns':
            return [
                'top_campaigns' => $analytics->getTopCampaigns(10, $period),
                'campaign_performance' => $analytics->getCampaignPerformance($period)
            ];
            
        case 'geographic':
            return [
                'top_countries' => $analytics->getTopCountries(20, $period),
                'top_cities' => $analytics->getTopCities(20, $period),
                'geographic_distribution' => $analytics->getGeographicDistribution($period)
            ];
            
        case 'devices':
            return [
                'device_types' => $analytics->getDeviceBreakdown($period),
                'browsers' => $analytics->getBrowserBreakdown($period),
                'operating_systems' => $analytics->getOSBreakdown($period),
                'mobile_vs_desktop' => $analytics->getMobileVsDesktop($period)
            ];
            
        case 'security':
            return [
                'bot_detections' => $analytics->getBotDetections($period),
                'vpn_detections' => $analytics->getVpnDetections($period),
                'suspicious_activity' => $analytics->getSuspiciousActivity($period),
                'blocked_ips' => $analytics->getBlockedIPs($period)
            ];
            
        case 'performance':
            return [
                'average_response_time' => $analytics->getAverageResponseTime($period),
                'cache_hit_rate' => $analytics->getCacheHitRate($period),
                'error_rate' => $analytics->getErrorRate($period),
                'system_uptime' => $analytics->getUptime($period)
            ];
            
        default:
            return getGlobalStats($analytics, $period, 'overview');
    }
}

/**
 * Exportar como CSV
 */
function outputCSV($data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="cloaker-stats-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Flatten nested arrays
    $flat = [];
    array_walk_recursive($data, function($value, $key) use (&$flat) {
        $flat[$key] = $value;
    });
    
    // Headers
    fputcsv($output, array_keys($flat));
    
    // Data
    fputcsv($output, array_values($flat));
    
    fclose($output);
    exit;
}