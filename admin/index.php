<?php
/**
 * Cloaker Pro - Admin Dashboard
 * Painel principal de administração com Anti-Scraping e Fingerprinting
 * VERSÃO COMPLETA com Multi-Tenancy
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Campaign.php';
require_once __DIR__ . '/../core/Analytics.php';
require_once __DIR__ . '/../core/License.php';
require_once __DIR__ . '/../core/Utils.php';

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ==========================================
// MULTI-TENANCY: INICIALIZAR
// ==========================================
require_once '../core/TenantManager.php';
require_once '../core/TenantMiddleware.php';

$db = Database::getInstance();
$tenantManager = new TenantManager($db);
$tenantMiddleware = new TenantMiddleware($tenantManager, $auth);
$tenantMiddleware->handle();

// Tornar disponível globalmente
$GLOBALS['tenantMiddleware'] = $tenantMiddleware;

// Obter informações do tenant atual
$user = $auth->getCurrentUser();
$currentTenant = $tenantMiddleware->getCurrentTenant();
$isSuperAdmin = $tenantMiddleware->isSuperAdmin();
$tenantId = $tenantMiddleware->getTenantId();

// ==========================================
// DEBUG: LOG PARA IDENTIFICAR PROBLEMAS
// ==========================================
error_log("=== DASHBOARD DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("User Role: " . ($user['role'] ?? 'NULL'));
error_log("User tenant_id: " . ($user['tenant_id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("=====================");

// Validação crítica
if (!$isSuperAdmin && !$tenantId) {
    error_log("ERRO CRÍTICO: Usuário não-admin sem tenant_id!");
    die('
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro de Configuração</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-900 text-gray-100 flex items-center justify-center min-h-screen">
        <div class="max-w-md mx-auto p-8 bg-red-900/20 border border-red-600 rounded-xl">
            <div class="text-center mb-6">
                <i class="fas fa-exclamation-triangle text-6xl text-red-500 mb-4"></i>
                <h2 class="text-2xl font-bold text-white mb-2">Erro de Configuração</h2>
            </div>
            <div class="space-y-4 text-gray-300">
                <p>Seu usuário não está associado a nenhum cliente.</p>
                <p class="text-sm">Entre em contato com o administrador do sistema para resolver este problema.</p>
            </div>
            <div class="mt-6">
                <a href="logout.php" class="block w-full text-center px-4 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold transition">
                    <i class="fas fa-sign-out-alt mr-2"></i>Fazer Logout
                </a>
            </div>
        </div>
    </body>
    </html>
    ');
}

// ==========================================
// CARREGAR ANTI-SCRAPING (se existir)
// ==========================================
$antiScrapingAvailable = false;
$antiScrapingStats = ['blocked_ips' => 0, 'attempts_today' => 0, 'attempts_hour' => 0];

if (file_exists('../core/RateLimiter.php')) {
    require_once '../core/RateLimiter.php';
    $antiScrapingAvailable = true;
    
    try {
        $rateLimiter = new RateLimiter();
        
        // Preparar filtro de tenant
        $tenantFilter = "";
        $tenantParams = [];
        if (!$isSuperAdmin && $tenantId) {
            $tenantFilter = " AND tenant_id = ?";
            $tenantParams = [$tenantId];
        }
        
        // Verificar se a tabela blocked_ips existe
        $tableCheck = $db->query("SHOW TABLES LIKE 'blocked_ips'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $sql = "SELECT COUNT(*) as count FROM blocked_ips 
                    WHERE (expires_at IS NULL OR expires_at > NOW())" . $tenantFilter;
            $result = $db->raw($sql, $tenantParams);
            $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
            $antiScrapingStats['blocked_ips'] = $row ? intval($row['count']) : 0;
        }
        
        // Verificar se a tabela scraping_attempts existe
        $tableCheck = $db->query("SHOW TABLES LIKE 'scraping_attempts'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $sql = "SELECT COUNT(*) as count FROM scraping_attempts 
                    WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)" . $tenantFilter;
            $result = $db->raw($sql, $tenantParams);
            $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
            $antiScrapingStats['attempts_today'] = $row ? intval($row['count']) : 0;
            
            $sql = "SELECT COUNT(*) as count FROM scraping_attempts 
                    WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)" . $tenantFilter;
            $result = $db->raw($sql, $tenantParams);
            $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
            $antiScrapingStats['attempts_hour'] = $row ? intval($row['count']) : 0;
        }
        
    } catch (Exception $e) {
        error_log("Anti-Scraping stats error: " . $e->getMessage());
    }
}

try {
    $campaign = new Campaign();
    $analytics = new Analytics();
    $license = new License();

    // Obter estatísticas com tratamento de erro
    $stats = [
        'total_campaigns' => 0,
        'active_campaigns' => 0,
        'total_visitors' => 0,
        'unique_visitors' => 0,
        'safe_redirects' => 0,
        'money_redirects' => 0,
        'bot_blocks' => 0,
        'conversion_rate' => 0
    ];

    // Preparar filtro WHERE para campanhas
    $campaignWhere = [];
    if (!$isSuperAdmin && $tenantId) {
        $campaignWhere['tenant_id'] = $tenantId;
    }

    // Preparar JOIN filter para visitors
    $visitorJoinFilter = "";
    $visitorJoinParams = [];
    if (!$isSuperAdmin && $tenantId) {
        $visitorJoinFilter = " AND c.tenant_id = ?";
        $visitorJoinParams = [$tenantId];
    }

    try {
        $stats['total_campaigns'] = (int)$db->count('campaigns', $campaignWhere);
        
        $activeWhere = array_merge($campaignWhere, ['status' => 'active']);
        $stats['active_campaigns'] = (int)$db->count('campaigns', $activeWhere);
    } catch (Exception $e) {
        error_log("Campaign count error: " . $e->getMessage());
    }

    try {
        // Total de visitantes hoje
        $sql = "SELECT COUNT(*) as total 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE DATE(v.created_at) = CURDATE()" . $visitorJoinFilter;
        
        $result = $db->raw($sql, $visitorJoinParams);
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
        $stats['total_visitors'] = $row ? (int)$row['total'] : 0;
        
        // Visitantes únicos
        $sql = "SELECT COUNT(DISTINCT v.visitor_id) as total 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE DATE(v.created_at) = CURDATE()" . $visitorJoinFilter;
        
        $result = $db->raw($sql, $visitorJoinParams);
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
        $stats['unique_visitors'] = $row ? (int)$row['total'] : 0;
        
        // Safe redirects
        $sql = "SELECT COUNT(*) as total 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE DATE(v.created_at) = CURDATE() 
                AND v.decision = 'safe'" . $visitorJoinFilter;
        
        $result = $db->raw($sql, $visitorJoinParams);
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
        $stats['safe_redirects'] = $row ? (int)$row['total'] : 0;
        
        // Money redirects
        $sql = "SELECT COUNT(*) as total 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE DATE(v.created_at) = CURDATE() 
                AND v.decision = 'money'" . $visitorJoinFilter;
        
        $result = $db->raw($sql, $visitorJoinParams);
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
        $stats['money_redirects'] = $row ? (int)$row['total'] : 0;
        
        // Bots bloqueados
        $sql = "SELECT COUNT(*) as total 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE DATE(v.created_at) = CURDATE() 
                AND v.is_bot = 1" . $visitorJoinFilter;
        
        $result = $db->raw($sql, $visitorJoinParams);
        $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
        $stats['bot_blocks'] = $row ? (int)$row['total'] : 0;
        
        // Taxa de conversão
        if ($stats['total_visitors'] > 0) {
            $stats['conversion_rate'] = ($stats['money_redirects'] / $stats['total_visitors']) * 100;
        }
        
    } catch (Exception $e) {
        error_log("Analytics error: " . $e->getMessage());
    }

    // ==========================================
    // STATS DE FINGERPRINTING (VERIFICAÇÃO SEGURA)
    // ==========================================
    $fingerprintStats = [
        'total_fingerprints' => 0,
        'unique_today' => 0,
        'suspicious_count' => 0,
        'high_risk_count' => 0,
        'avg_risk_score' => 0,
        'bot_detection_rate' => 0
    ];
    
    $fingerprintingAvailable = false;

    try {
        // Verificar se a tabela fingerprints existe
        $tableCheck = $db->query("SHOW TABLES LIKE 'fingerprints'");
        $fingerprintingAvailable = $tableCheck && $tableCheck->rowCount() > 0;
        
        if ($fingerprintingAvailable) {
            // Verificar estrutura da tabela
            $columnsResult = $db->query("SHOW COLUMNS FROM fingerprints");
            $columns = [];
            while ($col = $columnsResult->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $col['Field'];
            }
            
            // Preparar filtro de tenant para fingerprints
            // fingerprints não tem tenant_id direto, filtrar via visitors
            $fingerprintJoin = "";
            if (!$isSuperAdmin && $tenantId) {
                $fingerprintJoin = " WHERE f.id IN (
                    SELECT DISTINCT fp.id 
                    FROM fingerprints fp
                    INNER JOIN visitors v ON v.fingerprint = fp.fingerprint_hash
                    INNER JOIN campaigns c ON v.campaign_id = c.id 
                    WHERE c.tenant_id = " . intval($tenantId) . "
                )";
            }
            
            // Total de fingerprints únicos
            if (in_array('fingerprint_hash', $columns)) {
                try {
                    $sql = "SELECT COUNT(DISTINCT f.fingerprint_hash) as count 
                            FROM fingerprints f" . $fingerprintJoin;
                    $result = $db->raw($sql);
                    $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
                    $fingerprintStats['total_fingerprints'] = $row ? intval($row['count']) : 0;
                } catch (Exception $e) {
                    error_log("Error counting fingerprints: " . $e->getMessage());
                }
            }
            
            // Fingerprints únicos hoje
            if (in_array('fingerprint_hash', $columns) && in_array('created_at', $columns)) {
                try {
                    $whereClause = $fingerprintJoin ? str_replace("WHERE", "AND", $fingerprintJoin) : "";
                    $sql = "SELECT COUNT(DISTINCT f.fingerprint_hash) as count 
                            FROM fingerprints f 
                            WHERE DATE(f.created_at) = CURDATE()" . $whereClause;
                    $result = $db->raw($sql);
                    $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
                    $fingerprintStats['unique_today'] = $row ? intval($row['count']) : 0;
                } catch (Exception $e) {
                    error_log("Error counting today fingerprints: " . $e->getMessage());
                }
            }
            
            // Suspeitos
            if (in_array('is_suspicious', $columns)) {
                try {
                    $whereClause = $fingerprintJoin ? str_replace("WHERE", "AND", $fingerprintJoin) : "";
                    $sql = "SELECT COUNT(*) as count 
                            FROM fingerprints f 
                            WHERE f.is_suspicious = 1" . $whereClause;
                    $result = $db->raw($sql);
                    $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
                    $fingerprintStats['suspicious_count'] = $row ? intval($row['count']) : 0;
                } catch (Exception $e) {
                    error_log("Error counting suspicious: " . $e->getMessage());
                }
            }
            
            // Alto risco
            if (in_array('trust_score', $columns)) {
                try {
                    $whereClause = $fingerprintJoin ? str_replace("WHERE", "AND", $fingerprintJoin) : "";
                    $sql = "SELECT COUNT(*) as count 
                            FROM fingerprints f 
                            WHERE f.trust_score <= 30" . $whereClause;
                    $result = $db->raw($sql);
                    $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
                    $fingerprintStats['high_risk_count'] = $row ? intval($row['count']) : 0;
                    
                    // Score médio
                    $sql = "SELECT AVG(f.trust_score) as avg 
                            FROM fingerprints f 
                            WHERE f.trust_score > 0" . $whereClause;
                    $result = $db->raw($sql);
                    $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
                    $fingerprintStats['avg_risk_score'] = $row && $row['avg'] ? round($row['avg'], 1) : 0;
                } catch (Exception $e) {
                    error_log("Error calculating risk scores: " . $e->getMessage());
                }
            }
            
            // Taxa de detecção de bots
            try {
                $whereClause = $fingerprintJoin ? str_replace("WHERE", "AND", $fingerprintJoin) : "";
                $sql = "SELECT COUNT(*) as total, 
                        SUM(CASE WHEN f.is_suspicious = 1 THEN 1 ELSE 0 END) as bots 
                        FROM fingerprints f 
                        WHERE 1=1" . $whereClause;
                $result = $db->raw($sql);
                $row = $result ? $result->fetch(PDO::FETCH_ASSOC) : null;
                if ($row && $row['total'] > 0) {
                    $fingerprintStats['bot_detection_rate'] = round(($row['bots'] / $row['total']) * 100, 1);
                }
            } catch (Exception $e) {
                error_log("Error calculating bot rate: " . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        error_log("Fingerprint stats error: " . $e->getMessage());
        $fingerprintingAvailable = false;
    }

    // Obter campanhas recentes
    $recentCampaigns = ['data' => []];
    try {
        $sql = "SELECT * FROM campaigns WHERE 1=1";
        $params = [];
        
        if (!$isSuperAdmin && $tenantId) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 5";
        
        $result = $db->raw($sql, $params);
        if ($result) {
            $recentCampaigns['data'] = $result->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Recent campaigns error: " . $e->getMessage());
    }

    // Obter gráficos
    $hourlyTraffic = [];
    $topCountries = [];
    $deviceTypes = [];

    try {
        // Tráfego por hora
        $sql = "SELECT HOUR(v.created_at) as hour, COUNT(*) as visitors 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE DATE(v.created_at) = CURDATE()";
        
        $params = [];
        if (!$isSuperAdmin && $tenantId) {
            $sql .= " AND c.tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $sql .= " GROUP BY HOUR(v.created_at) ORDER BY hour";
        
        $result = $db->raw($sql, $params);
        $hourlyData = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
        
        // Preencher todas as 24 horas
        $hourlyMap = [];
        foreach ($hourlyData as $item) {
            $hourlyMap[(int)$item['hour']] = (int)$item['visitors'];
        }
        
        for ($i = 0; $i < 24; $i++) {
            $hourlyTraffic[] = [
                'hour' => sprintf('%02d:00', $i),
                'visitors' => isset($hourlyMap[$i]) ? $hourlyMap[$i] : 0
            ];
        }
    } catch (Exception $e) {
        error_log("Hourly traffic error: " . $e->getMessage());
        for ($i = 0; $i < 24; $i++) {
            $hourlyTraffic[] = ['hour' => sprintf('%02d:00', $i), 'visitors' => 0];
        }
    }

    try {
        // Top países
        $sql = "SELECT v.country_name as country, COUNT(*) as count 
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE v.country_name IS NOT NULL";
        
        $params = [];
        if (!$isSuperAdmin && $tenantId) {
            $sql .= " AND c.tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $sql .= " GROUP BY v.country_name ORDER BY count DESC LIMIT 10";
        
        $result = $db->raw($sql, $params);
        $topCountries = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        error_log("Top countries error: " . $e->getMessage());
        $topCountries = [];
    }

    try {
        // Dispositivos
        $sql = "SELECT 
                    CASE 
                        WHEN v.device_type = 'desktop' THEN 'Desktop'
                        WHEN v.device_type = 'mobile' THEN 'Mobile'
                        WHEN v.device_type = 'tablet' THEN 'Tablet'
                        ELSE 'Unknown'
                    END as type,
                    COUNT(*) as count
                FROM visitors v
                INNER JOIN campaigns c ON v.campaign_id = c.id
                WHERE 1=1";
        
        $params = [];
        if (!$isSuperAdmin && $tenantId) {
            $sql .= " AND c.tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $sql .= " GROUP BY v.device_type";
        
        $result = $db->raw($sql, $params);
        $deviceTypes = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
        
        if (empty($deviceTypes)) {
            $deviceTypes = [
                ['type' => 'Desktop', 'count' => 0],
                ['type' => 'Mobile', 'count' => 0],
                ['type' => 'Tablet', 'count' => 0]
            ];
        }
    } catch (Exception $e) {
        error_log("Device types error: " . $e->getMessage());
        $deviceTypes = [
            ['type' => 'Desktop', 'count' => 0],
            ['type' => 'Mobile', 'count' => 0],
            ['type' => 'Tablet', 'count' => 0]
        ];
    }

    // Verificar licença
    $licenseInfo = ['plan' => 'FREE'];
    try {
        $licenseInfo = $license->getInfo();
    } catch (Exception $e) {
        error_log("License error: " . $e->getMessage());
    }

} catch (Exception $e) {
    error_log("Critical dashboard error: " . $e->getMessage());
    die("Erro crítico ao carregar dashboard: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cloaker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: { DEFAULT: '#000000', secondary: '#0a0a0a', tertiary: '#141414', card: '#1a1a1a', hover: '#242424' },
                        silver: { DEFAULT: '#c0c0c0', light: '#e8e8e8', dark: '#808080', muted: '#a8a8a8' },
                        accent: { success: '#4ade80', danger: '#f87171', warning: '#fbbf24', info: '#60a5fa' }
                    },
                    backgroundImage: { 'gradient-silver': 'linear-gradient(135deg, #c0c0c0 0%, #808080 100%)' },
                    boxShadow: { 'glow': '0 0 20px rgba(192, 192, 192, 0.15)', 'glow-lg': '0 0 30px rgba(192, 192, 192, 0.25)' }
                }
            }
        }
    </script>
    
    <style>
        @keyframes shine { 0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); } 100% { transform: translateX(100%) translateY(100%) rotate(45deg); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .animate-shine::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent); animation: shine 3s infinite; }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        .pulse-glow { animation: pulse 2s ease-in-out infinite; }
        
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
        
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
        
        .chart-container { position: relative; height: 300px; }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Dashboard';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']);
        }
        require_once 'header.php'; 
        ?>

        <!-- Content -->
       <main class="p-8 flex-1">
            <?php if (!$isSuperAdmin && $currentTenant): ?>
            <!-- Informações do Tenant -->
            <div class="bg-gradient-to-r from-blue-900/20 to-purple-900/20 border border-blue-500/30 rounded-xl p-4 mb-6 fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-purple-600 rounded-xl flex items-center justify-center text-white text-xl font-bold">
                            <?= strtoupper(substr($currentTenant['name'], 0, 2)) ?>
                        </div>
                        <div>
                            <p class="font-bold text-silver text-lg"><?= htmlspecialchars($currentTenant['name']) ?></p>
                            <p class="text-sm text-silver-muted">
                                Plano: <span class="font-semibold text-blue-400"><?= strtoupper($currentTenant['plan']) ?></span>
                                • Status: <span class="font-semibold <?= $currentTenant['status'] === 'active' ? 'text-green-400' : 'text-yellow-400' ?>">
                                    <?= ucfirst($currentTenant['status']) ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-silver-dark uppercase mb-1">Limites</p>
                        <div class="flex gap-4 text-sm">
                            <div>
                                <span class="text-silver-muted">Campanhas:</span>
                                <span class="font-bold text-silver"><?= $stats['total_campaigns'] ?>/<?= $currentTenant['max_campaigns'] ?></span>
                            </div>
                            <div>
                                <span class="text-silver-muted">Visitantes:</span>
                                <span class="font-bold text-silver"><?= number_format($stats['total_visitors']) ?>/<?= number_format($currentTenant['max_visitors_month']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Visitors -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-silver hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-silver opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Visitantes Hoje</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['total_visitors']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">Únicos: <span class="text-accent-success"><?php echo number_format($stats['unique_visitors']); ?></span></p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-silver to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <!-- Active Campaigns -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-success hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-success opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Campanhas Ativas</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo $stats['active_campaigns']; ?></p>
                        <p class="text-silver-muted text-sm mt-2">Total: <span class="text-silver"><?php echo $stats['total_campaigns']; ?></span></p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-success to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <!-- Safe Redirects -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-warning hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-warning opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Safe Pages</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['safe_redirects']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">
                            <?php echo $stats['total_visitors'] > 0 ? round(($stats['safe_redirects'] / $stats['total_visitors']) * 100) : 0; ?>% do tráfego
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-warning to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <!-- Money Pages -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-info hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-info opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Money Pages</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['money_redirects']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">
                            <span class="text-accent-success"><?php echo number_format($stats['conversion_rate'], 1); ?>%</span> conversão
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-info to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>
            </div>

            <?php if ($antiScrapingAvailable && $antiScrapingStats['attempts_hour'] > 5): ?>
            <!-- Anti-Scraping Alert -->
            <div class="bg-gradient-to-r from-accent-danger/20 to-accent-warning/20 border-l-4 border-accent-danger rounded-lg p-4 mb-6 fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-triangle text-2xl text-accent-danger pulse-glow"></i>
                        <div>
                            <p class="font-bold text-silver">Atividade Suspeita Detectada!</p>
                            <p class="text-sm text-silver-muted"><?php echo $antiScrapingStats['attempts_hour']; ?> tentativas de scraping na última hora</p>
                        </div>
                    </div>
                    <a href="anti-scraping.php" class="px-4 py-2 bg-accent-danger hover:bg-red-600 rounded-lg font-semibold transition-all">
                        Ver Detalhes <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($antiScrapingAvailable): ?>
            <!-- Anti-Scraping Stats Mini -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="bg-gradient-to-br from-accent-danger/10 to-accent-danger/5 border border-accent-danger/30 rounded-xl p-4 hover:shadow-glow transition-all">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-silver-dark text-xs uppercase">IPs Bloqueados</p>
                            <p class="text-2xl font-bold text-accent-danger mt-1"><?php echo $antiScrapingStats['blocked_ips']; ?></p>
                        </div>
                        <i class="fas fa-ban text-3xl text-accent-danger opacity-20"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-accent-warning/10 to-accent-warning/5 border border-accent-warning/30 rounded-xl p-4 hover:shadow-glow transition-all">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-silver-dark text-xs uppercase">Tentativas Hoje</p>
                            <p class="text-2xl font-bold text-accent-warning mt-1"><?php echo $antiScrapingStats['attempts_today']; ?></p>
                        </div>
                        <i class="fas fa-exclamation-triangle text-3xl text-accent-warning opacity-20"></i>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-accent-info/10 to-accent-info/5 border border-accent-info/30 rounded-xl p-4 hover:shadow-glow transition-all">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-silver-dark text-xs uppercase">Última Hora</p>
                            <p class="text-2xl font-bold text-accent-info mt-1"><?php echo $antiScrapingStats['attempts_hour']; ?></p>
                        </div>
                        <i class="fas fa-clock text-3xl text-accent-info opacity-20"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($fingerprintingAvailable): ?>
            <!-- Fingerprinting Stats -->
            <div class="bg-gradient-to-br from-purple-900/20 to-indigo-900/20 border border-purple-500/30 rounded-xl p-6 mb-8 fade-in">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-fingerprint text-2xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-silver">Sistema de Fingerprinting</h3>
                            <p class="text-sm text-silver-muted">Análise avançada de impressões digitais</p>
                        </div>
                    </div>
                    <a href="fingerprints.php" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded-lg font-semibold text-white transition-all flex items-center gap-2">
                        <i class="fas fa-chart-bar"></i>
                        Ver Detalhes
                    </a>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <!-- Stats Cards -->
                    <div class="bg-dark-card/50 backdrop-blur-sm border border-purple-500/20 rounded-lg p-4 hover:border-purple-500/40 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <i class="fas fa-database text-purple-400 text-lg"></i>
                            <div class="w-2 h-2 bg-purple-400 rounded-full shadow-glow"></div>
                        </div>
                        <p class="text-2xl font-bold text-silver mb-1"><?php echo number_format($fingerprintStats['total_fingerprints']); ?></p>
                        <p class="text-xs text-silver-dark uppercase tracking-wide">Total Únicos</p>
                    </div>
                    
                    <div class="bg-dark-card/50 backdrop-blur-sm border border-indigo-500/20 rounded-lg p-4 hover:border-indigo-500/40 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <i class="fas fa-calendar-day text-indigo-400 text-lg"></i>
                            <div class="w-2 h-2 bg-indigo-400 rounded-full shadow-glow"></div>
                        </div>
                        <p class="text-2xl font-bold text-silver mb-1"><?php echo number_format($fingerprintStats['unique_today']); ?></p>
                        <p class="text-xs text-silver-dark uppercase tracking-wide">Hoje</p>
                    </div>
                    
                    <div class="bg-dark-card/50 backdrop-blur-sm border border-yellow-500/20 rounded-lg p-4 hover:border-yellow-500/40 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <i class="fas fa-exclamation-triangle text-yellow-400 text-lg"></i>
                            <?php if ($fingerprintStats['suspicious_count'] > 0): ?>
                            <div class="w-2 h-2 bg-yellow-400 rounded-full pulse-glow"></div>
                            <?php endif; ?>
                        </div>
                        <p class="text-2xl font-bold text-yellow-400 mb-1"><?php echo number_format($fingerprintStats['suspicious_count']); ?></p>
                        <p class="text-xs text-silver-dark uppercase tracking-wide">Suspeitos</p>
                    </div>
                    
                    <div class="bg-dark-card/50 backdrop-blur-sm border border-red-500/20 rounded-lg p-4 hover:border-red-500/40 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <i class="fas fa-shield-alt text-red-400 text-lg"></i>
                            <?php if ($fingerprintStats['high_risk_count'] > 0): ?>
                            <div class="w-2 h-2 bg-red-400 rounded-full pulse-glow"></div>
                            <?php endif; ?>
                        </div>
                        <p class="text-2xl font-bold text-red-400 mb-1"><?php echo number_format($fingerprintStats['high_risk_count']); ?></p>
                        <p class="text-xs text-silver-dark uppercase tracking-wide">Alto Risco</p>
                    </div>
                    
                    <div class="bg-dark-card/50 backdrop-blur-sm border border-cyan-500/20 rounded-lg p-4 hover:border-cyan-500/40 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <i class="fas fa-chart-line text-cyan-400 text-lg"></i>
                        </div>
                        <p class="text-2xl font-bold text-cyan-400 mb-1"><?php echo $fingerprintStats['avg_risk_score']; ?></p>
                        <p class="text-xs text-silver-dark uppercase tracking-wide">Score Médio</p>
                    </div>
                    
                    <div class="bg-dark-card/50 backdrop-blur-sm border border-orange-500/20 rounded-lg p-4 hover:border-orange-500/40 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <i class="fas fa-robot text-orange-400 text-lg"></i>
                        </div>
                        <p class="text-2xl font-bold text-orange-400 mb-1"><?php echo $fingerprintStats['bot_detection_rate']; ?>%</p>
                        <p class="text-xs text-silver-dark uppercase tracking-wide">Taxa Bots</p>
                    </div>
                </div>
                
                <!-- Barra de Progresso de Risco -->
                <div class="mt-6 p-4 bg-dark-card/30 rounded-lg border border-purple-500/10">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm text-silver-muted">Nível de Risco Geral</span>
                        <span class="text-sm font-bold text-silver"><?php echo $fingerprintStats['avg_risk_score']; ?>/100</span>
                    </div>
                    <div class="w-full bg-dark-tertiary rounded-full h-3 overflow-hidden">
                        <div class="h-full <?php 
                            $score = $fingerprintStats['avg_risk_score'];
                            if ($score >= 70) echo 'bg-gradient-to-r from-red-600 to-red-500';
                            elseif ($score >= 40) echo 'bg-gradient-to-r from-yellow-600 to-yellow-500';
                            else echo 'bg-gradient-to-r from-green-600 to-green-500';
                        ?> transition-all duration-500 shadow-glow" style="width: <?php echo min($fingerprintStats['avg_risk_score'], 100); ?>%"></div>
                    </div>
                    <div class="flex justify-between mt-2 text-xs text-silver-dark">
                        <span>Baixo</span>
                        <span>Médio</span>
                        <span>Alto</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Traffic Chart -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 fade-in">
                    <h3 class="text-lg font-bold text-silver mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-area text-silver"></i>
                        Tráfego por Hora (Hoje)
                    </h3>
                    <div class="chart-container">
                        <canvas id="trafficChart"></canvas>
                    </div>
                </div>

                <!-- Device Chart -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 fade-in">
                    <h3 class="text-lg font-bold text-silver mb-4 flex items-center gap-2">
                        <i class="fas fa-mobile-alt text-silver"></i>
                        Dispositivos
                    </h3>
                    <div class="chart-container">
                        <canvas id="deviceChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tables Row -->
             <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Campaigns -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                    <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-silver flex items-center gap-2">
                                <i class="fas fa-rocket text-silver"></i>
                                Campanhas Recentes
                            </h3>
                            <a href="campaigns.php" class="text-silver-dark hover:text-silver text-sm transition-colors">
                                Ver todas <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (empty($recentCampaigns['data'])): ?>
                        <div class="text-center py-12 text-silver-dark">
                            <i class="fas fa-inbox text-5xl mb-4 opacity-30"></i>
                            <p class="mb-3">Nenhuma campanha criada ainda</p>
                            <a href="campaign-create.php" class="inline-block px-4 py-2 bg-gradient-silver text-dark rounded-lg font-semibold hover:shadow-glow transition-all">
                                <i class="fas fa-plus mr-2"></i>Criar primeira campanha
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recentCampaigns['data'] as $camp): ?>
                            <div class="flex items-center justify-between p-4 hover:bg-dark-hover rounded-lg transition-all group">
                                <div class="flex items-center gap-3">
                                    <div class="w-3 h-3 rounded-full <?php echo $camp['status'] === 'active' ? 'bg-accent-success shadow-glow' : 'bg-silver-dark'; ?>"></div>
                                    <div>
                                        <p class="font-semibold text-silver group-hover:text-silver-light transition-colors"><?php echo htmlspecialchars($camp['name']); ?></p>
                                        <p class="text-sm text-silver-dark"><?php echo date('d/m/Y', strtotime($camp['created_at'])); ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-bold text-silver"><?php echo number_format($camp['visitors_count'] ?? 0); ?></p>
                                    <p class="text-xs text-silver-dark">visitantes</p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                    <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary">
                        <h3 class="text-lg font-bold text-silver flex items-center gap-2">
                            <i class="fas fa-bolt text-silver"></i>
                            Ações Rápidas
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3">
                            <a href="campaign-create.php" class="group flex items-center gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-xl hover:border-silver hover:shadow-glow transition-all duration-300 hover:scale-105">
                                <div class="w-12 h-12 bg-gradient-silver rounded-lg flex items-center justify-center text-dark text-xl group-hover:scale-110 transition-transform">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-silver text-base">Nova Campanha</p>
                                    <p class="text-sm text-silver-dark">Criar campanha de cloaking</p>
                                </div>
                            </a>
                            
                            <?php if ($fingerprintingAvailable): ?>
                            <a href="fingerprints.php" class="group flex items-center gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-xl hover:border-purple-500 hover:shadow-glow transition-all duration-300 hover:scale-105">
                                <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-lg flex items-center justify-center text-white text-xl group-hover:scale-110 transition-transform">
                                    <i class="fas fa-fingerprint"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-silver text-base">Fingerprints</p>
                                    <p class="text-sm text-silver-dark">Análise de impressões digitais</p>
                                </div>
                                <?php if ($fingerprintStats['suspicious_count'] > 0): ?>
                                <span class="ml-auto w-2 h-2 bg-accent-warning rounded-full pulse-glow"></span>
                                <?php endif; ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($antiScrapingAvailable): ?>
                            <a href="anti-scraping.php" class="group flex items-center gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-xl hover:border-accent-danger hover:shadow-glow transition-all duration-300 hover:scale-105">
                                <div class="w-12 h-12 bg-gradient-to-br from-accent-danger to-accent-warning rounded-lg flex items-center justify-center text-dark text-xl group-hover:scale-110 transition-transform">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-silver text-base">Anti-Scraping</p>
                                    <p class="text-sm text-silver-dark">Proteção contra bots</p>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <a href="campaigns.php" class="group flex items-center gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-xl hover:border-accent-success hover:shadow-glow transition-all duration-300 hover:scale-105">
                                <div class="w-12 h-12 bg-gradient-to-br from-accent-success to-accent-info rounded-lg flex items-center justify-center text-dark text-xl group-hover:scale-110 transition-transform">
                                    <i class="fas fa-list"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-silver text-base">Ver Campanhas</p>
                                    <p class="text-sm text-silver-dark">Gerenciar existentes</p>
                                </div>
                            </a>
                            
                            <a href="analytics.php" class="group flex items-center gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-xl hover:border-accent-info hover:shadow-glow transition-all duration-300 hover:scale-105">
                                <div class="w-12 h-12 bg-gradient-to-br from-accent-info to-accent-warning rounded-lg flex items-center justify-center text-dark text-xl group-hover:scale-110 transition-transform">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div>
                                    <p class="font-bold text-silver text-base">Analytics</p>
                                    <p class="text-sm text-silver-dark">Relatórios detalhados</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <!-- Charts JavaScript -->
    <script>
        Chart.defaults.color = '#c0c0c0';
        Chart.defaults.borderColor = '#2a2a2a';
        
        const trafficCtx = document.getElementById('trafficChart').getContext('2d');
        new Chart(trafficCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($hourlyTraffic, 'hour')); ?>,
                datasets: [{
                    label: 'Visitantes',
                    data: <?php echo json_encode(array_column($hourlyTraffic, 'visitors')); ?>,
                    borderColor: '#c0c0c0',
                    backgroundColor: 'rgba(192, 192, 192, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#c0c0c0',
                    pointBorderColor: '#1a1a1a',
                    pointHoverBackgroundColor: '#e8e8e8',
                    pointHoverBorderColor: '#c0c0c0'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0, color: '#808080' }, grid: { color: '#2a2a2a' } },
                    x: { ticks: { color: '#808080' }, grid: { color: '#2a2a2a' } }
                }
            }
        });

        const deviceCtx = document.getElementById('deviceChart').getContext('2d');
        new Chart(deviceCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($deviceTypes, 'type')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($deviceTypes, 'count')); ?>,
                    backgroundColor: ['#c0c0c0', '#60a5fa', '#4ade80', '#fbbf24'],
                    borderColor: '#1a1a1a',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { color: '#c0c0c0', padding: 15, font: { size: 12 } } } }
            }
        });
    </script>
</body>
</html>