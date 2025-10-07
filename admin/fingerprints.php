<?php
/**
 * Cloaker Pro - Fingerprints Management
 * Página de gerenciamento de fingerprints com Multi-Tenancy
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Utils.php';

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
// DEBUG
// ==========================================
error_log("=== FINGERPRINTS DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("========================");

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
                <p class="text-sm">Entre em contato com o administrador do sistema.</p>
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

try {
    // Verificar se a tabela fingerprints existe
    $tableExists = false;
    $columns = [];

    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'fingerprints'");
        $tableExists = $tableCheck && $tableCheck->rowCount() > 0;
        
        if ($tableExists) {
            $columnsResult = $db->query("SHOW COLUMNS FROM fingerprints");
            while ($col = $columnsResult->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $col['Field'];
            }
        }
    } catch (Exception $e) {
        error_log("Error checking fingerprints table: " . $e->getMessage());
    }

    // Se a tabela não existir, criar
    if (!$tableExists) {
        try {
            $createTableSql = "
                CREATE TABLE IF NOT EXISTS fingerprints (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    fingerprint_hash VARCHAR(64) NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    fingerprint_data LONGTEXT,
                    trust_score INT DEFAULT 50,
                    visit_count INT DEFAULT 1,
                    is_suspicious TINYINT DEFAULT 0,
                    is_verified TINYINT DEFAULT 0,
                    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    platform VARCHAR(50),
                    language VARCHAR(50),
                    timezone_name VARCHAR(100),
                    hardware_concurrency INT,
                    device_memory INT,
                    screen_width INT,
                    screen_height INT,
                    max_touch_points INT,
                    risk_score INT DEFAULT 0,
                    mouse_movements INT DEFAULT 0,
                    clicks INT DEFAULT 0,
                    key_presses INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_fingerprint_hash (fingerprint_hash),
                    INDEX idx_ip_address (ip_address),
                    INDEX idx_risk_score (risk_score),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            
            $pdo = $db->getConnection();
            $pdo->exec($createTableSql);
            
            $columnsResult = $db->query("SHOW COLUMNS FROM fingerprints");
            $columns = [];
            while ($col = $columnsResult->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $col['Field'];
            }
            $tableExists = true;
        } catch (Exception $e) {
            error_log("Error creating fingerprints table: " . $e->getMessage());
        }
    }

    // Inicializar variáveis
    $fingerprints = [];
    $totalRecords = 0;
    $totalPages = 1;
    $stats = [
        'total' => 0,
        'today' => 0,
        'suspicious' => 0,
        'high_risk' => 0,
        'avg_risk' => 0
    ];

    // Parâmetros de paginação e filtro
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';

    if ($tableExists) {
        // ==========================================
        // MULTI-TENANCY: CONSTRUIR QUERY COM FILTRO
        // ==========================================
        
        // Filtrar por tenant através de JOIN com visitors e campaigns
        $tenantJoin = "";
        $tenantWhere = [];
        
        if (!$isSuperAdmin && $tenantId) {
            // Cliente: JOIN com visitors e campaigns para filtrar por tenant
            $tenantJoin = "
                INNER JOIN (
                    SELECT DISTINCT v.fingerprint
                    FROM visitors v
                    INNER JOIN campaigns c ON v.campaign_id = c.id
                    WHERE c.tenant_id = {$tenantId}
                ) AS tenant_fps ON f.fingerprint_hash = tenant_fps.fingerprint
            ";
        }
        
        // Construir WHERE clause
        $where = [];
        $params = [];

        if ($filter === 'suspicious' && in_array('is_suspicious', $columns)) {
            $where[] = "f.is_suspicious = 1";
        } elseif ($filter === 'high_risk' && in_array('risk_score', $columns)) {
            $where[] = "f.risk_score >= 70";
        } elseif ($filter === 'today' && in_array('created_at', $columns)) {
            $where[] = "DATE(f.created_at) = CURDATE()";
        }

        if (!empty($search)) {
            $searchConditions = [];
            if (in_array('ip_address', $columns)) {
                $searchConditions[] = "f.ip_address LIKE ?";
                $params[] = "%{$search}%";
            }
            if (in_array('user_agent', $columns)) {
                $searchConditions[] = "f.user_agent LIKE ?";
                $params[] = "%{$search}%";
            }
            if (in_array('fingerprint_hash', $columns)) {
                $searchConditions[] = "f.fingerprint_hash LIKE ?";
                $params[] = "%{$search}%";
            }
            if (!empty($searchConditions)) {
                $where[] = "(" . implode(" OR ", $searchConditions) . ")";
            }
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        // Contar total
        try {
            $countSql = "SELECT COUNT(DISTINCT f.id) as total FROM fingerprints f {$tenantJoin} {$whereClause}";
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalRecords = $row ? intval($row['total']) : 0;
            $totalPages = ceil($totalRecords / $perPage);
        } catch (Exception $e) {
            error_log("Error counting fingerprints: " . $e->getMessage());
        }

        // Buscar fingerprints
        try {
            $orderBy = in_array('created_at', $columns) ? "ORDER BY f.created_at DESC" : "ORDER BY f.id DESC";
            $sql = "SELECT f.* FROM fingerprints f {$tenantJoin} {$whereClause} {$orderBy} LIMIT {$perPage} OFFSET {$offset}";
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $fingerprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching fingerprints: " . $e->getMessage());
        }

        // Stats gerais COM FILTRO DE TENANT
        try {
            // Total
            $sql = "SELECT COUNT(DISTINCT f.id) as count FROM fingerprints f {$tenantJoin}";
            $result = $db->query($sql);
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $stats['total'] = $row ? intval($row['count']) : 0;
            
            // Hoje
            if (in_array('created_at', $columns)) {
                $sql = "SELECT COUNT(DISTINCT f.id) as count FROM fingerprints f {$tenantJoin} WHERE DATE(f.created_at) = CURDATE()";
                $result = $db->query($sql);
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $stats['today'] = $row ? intval($row['count']) : 0;
            }
            
            // Suspeitos
            if (in_array('is_suspicious', $columns)) {
                $sql = "SELECT COUNT(DISTINCT f.id) as count FROM fingerprints f {$tenantJoin} WHERE f.is_suspicious = 1";
                $result = $db->query($sql);
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $stats['suspicious'] = $row ? intval($row['count']) : 0;
            }
            
            // Alto risco
            if (in_array('risk_score', $columns)) {
                $sql = "SELECT COUNT(DISTINCT f.id) as count FROM fingerprints f {$tenantJoin} WHERE f.risk_score >= 70";
                $result = $db->query($sql);
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $stats['high_risk'] = $row ? intval($row['count']) : 0;
                
                // Média de risco
                $sql = "SELECT AVG(f.risk_score) as avg FROM fingerprints f {$tenantJoin} WHERE f.risk_score > 0";
                $result = $db->query($sql);
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $stats['avg_risk'] = $row && $row['avg'] ? round($row['avg'], 1) : 0;
            }
        } catch (Exception $e) {
            error_log("Stats error: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    die("Erro crítico: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fingerprints - Cloaker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Fingerprints';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']) . ' - Impressões digitais dos visitantes';
        } else {
            $pageSubtitle = 'Gerenciamento de impressões digitais dos visitantes';
        }
        require_once 'header.php'; 
        ?>

        <!-- Content -->
        <main class="p-8 flex-1">
            <?php if (!$isSuperAdmin && $currentTenant): ?>
            <!-- Informações do Tenant -->
            <div class="bg-gradient-to-r from-purple-900/20 to-indigo-900/20 border border-purple-500/30 rounded-xl p-4 mb-6 fade-in">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-purple-600 to-indigo-600 rounded-xl flex items-center justify-center text-white text-xl font-bold">
                        <?= strtoupper(substr($currentTenant['name'], 0, 2)) ?>
                    </div>
                    <div>
                        <p class="font-bold text-silver text-lg"><?= htmlspecialchars($currentTenant['name']) ?></p>
                        <p class="text-sm text-silver-muted">Fingerprints das suas campanhas</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!$tableExists): ?>
            <!-- Mensagem de tabela não configurada -->
            <div class="bg-gradient-to-br from-purple-900/20 to-indigo-900/20 border border-purple-500/30 rounded-xl p-12 text-center fade-in">
                <i class="fas fa-fingerprint text-6xl text-purple-400 mb-4"></i>
                <h2 class="text-2xl font-bold text-silver mb-2">Sistema de Fingerprinting</h2>
                <p class="text-silver-dark mb-6">A tabela de fingerprints foi criada. Comece a coletar dados de impressões digitais!</p>
                <a href="index.php" class="inline-block px-6 py-3 bg-purple-600 hover:bg-purple-700 rounded-lg font-semibold transition-all">
                    <i class="fas fa-arrow-left mr-2"></i>Voltar ao Dashboard
                </a>
            </div>
            <?php else: ?>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
                <div class="bg-dark-card border border-purple-500/30 rounded-xl p-4 hover:border-purple-500/50 hover:shadow-glow transition-all fade-in">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-database text-purple-400 text-xl"></i>
                        <span class="text-xs text-silver-dark uppercase">Total</span>
                    </div>
                    <p class="text-3xl font-bold text-silver"><?php echo number_format($stats['total']); ?></p>
                </div>
                
                <div class="bg-dark-card border border-indigo-500/30 rounded-xl p-4 hover:border-indigo-500/50 hover:shadow-glow transition-all fade-in">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-calendar-day text-indigo-400 text-xl"></i>
                        <span class="text-xs text-silver-dark uppercase">Hoje</span>
                    </div>
                    <p class="text-3xl font-bold text-silver"><?php echo number_format($stats['today']); ?></p>
                </div>
                
                <div class="bg-dark-card border border-yellow-500/30 rounded-xl p-4 hover:border-yellow-500/50 hover:shadow-glow transition-all fade-in">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-exclamation-triangle text-yellow-400 text-xl"></i>
                        <span class="text-xs text-silver-dark uppercase">Suspeitos</span>
                    </div>
                    <p class="text-3xl font-bold text-yellow-400"><?php echo number_format($stats['suspicious']); ?></p>
                </div>
                
                <div class="bg-dark-card border border-red-500/30 rounded-xl p-4 hover:border-red-500/50 hover:shadow-glow transition-all fade-in">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-shield-alt text-red-400 text-xl"></i>
                        <span class="text-xs text-silver-dark uppercase">Alto Risco</span>
                    </div>
                    <p class="text-3xl font-bold text-red-400"><?php echo number_format($stats['high_risk']); ?></p>
                </div>
                
                <div class="bg-dark-card border border-cyan-500/30 rounded-xl p-4 hover:border-cyan-500/50 hover:shadow-glow transition-all fade-in">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-chart-line text-cyan-400 text-xl"></i>
                        <span class="text-xs text-silver-dark uppercase">Score Médio</span>
                    </div>
                    <p class="text-3xl font-bold text-cyan-400"><?php echo $stats['avg_risk']; ?></p>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-64">
                        <div class="relative">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-silver-dark"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Buscar por IP, User Agent ou Hash..." 
                                   class="w-full pl-11 pr-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg text-silver focus:border-purple-500 focus:outline-none transition-all">
                        </div>
                    </div>
                    
                    <select name="filter" class="px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg text-silver focus:border-purple-500 focus:outline-none transition-all">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Todos</option>
                        <option value="today" <?php echo $filter === 'today' ? 'selected' : ''; ?>>Hoje</option>
                        <option value="suspicious" <?php echo $filter === 'suspicious' ? 'selected' : ''; ?>>Suspeitos</option>
                        <option value="high_risk" <?php echo $filter === 'high_risk' ? 'selected' : ''; ?>>Alto Risco</option>
                    </select>
                    
                    <button type="submit" class="px-6 py-3 bg-purple-600 hover:bg-purple-700 hover:shadow-glow rounded-lg font-semibold transition-all">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                    <?php if ($search || $filter !== 'all'): ?>
                    <a href="fingerprints.php" class="px-6 py-3 bg-dark-tertiary hover:bg-dark-hover border border-[#2a2a2a] hover:border-accent-danger text-silver-dark hover:text-accent-danger rounded-lg font-semibold transition-all">
                        <i class="fas fa-times mr-2"></i>Limpar
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Fingerprints Table -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-dark-tertiary border-b border-[#2a2a2a]">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-silver-dark uppercase tracking-wider">Fingerprint</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-silver-dark uppercase tracking-wider">IP / Device</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-silver-dark uppercase tracking-wider">Localização</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-silver-dark uppercase tracking-wider">Risco</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-silver-dark uppercase tracking-wider">Comportamento</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-silver-dark uppercase tracking-wider">Visitas</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-silver-dark uppercase tracking-wider">Data</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold text-silver-dark uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#2a2a2a]">
                            <?php if (empty($fingerprints)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-16 text-center">
                                    <i class="fas fa-inbox text-6xl text-silver-dark opacity-30 mb-4"></i>
                                    <p class="text-silver-dark text-lg">Nenhum fingerprint encontrado</p>
                                    <p class="text-sm text-silver-muted mt-2">Os fingerprints aparecerão aqui quando os visitantes acessarem suas campanhas</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($fingerprints as $fp): ?>
                                <tr class="hover:bg-dark-hover transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <?php if (isset($fp['is_suspicious']) && $fp['is_suspicious']): ?>
                                            <i class="fas fa-exclamation-triangle text-yellow-400 pulse-glow"></i>
                                            <?php endif; ?>
                                            <code class="text-sm text-purple-400 font-mono">
                                                <?php echo isset($fp['fingerprint_hash']) ? substr($fp['fingerprint_hash'], 0, 16) . '...' : 'N/A'; ?>
                                            </code>
                                        </div>
                                    </td>
                                    
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-silver"><?php echo htmlspecialchars($fp['ip_address'] ?? 'N/A'); ?></div>
                                        <div class="text-xs text-silver-dark truncate max-w-[200px]" title="<?php echo htmlspecialchars($fp['user_agent'] ?? ''); ?>">
                                            <?php 
                                            $ua = $fp['user_agent'] ?? 'N/A';
                                            echo htmlspecialchars(strlen($ua) > 50 ? substr($ua, 0, 50) . '...' : $ua);
                                            ?>
                                        </div>
                                    </td>
                                    
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-silver"><?php echo htmlspecialchars($fp['language'] ?? 'N/A'); ?></div>
                                        <div class="text-xs text-silver-dark"><?php echo htmlspecialchars($fp['timezone_name'] ?? 'N/A'); ?></div>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-center">
                                        <?php if (isset($fp['risk_score'])): ?>
                                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full <?php 
                                            $score = $fp['risk_score'] ?? 0;
                                            if ($score >= 70) echo 'bg-red-500/20 text-red-400';
                                            elseif ($score >= 40) echo 'bg-yellow-500/20 text-yellow-400';
                                            else echo 'bg-green-500/20 text-green-400';
                                        ?>">
                                            <span class="text-sm font-bold"><?php echo $score; ?></span>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-silver-dark">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-3 text-xs text-silver-dark">
                                            <span title="Movimentos do mouse">
                                                <i class="fas fa-mouse"></i> <?php echo $fp['mouse_movements'] ?? 0; ?>
                                            </span>
                                            <span title="Cliques">
                                                <i class="fas fa-hand-pointer"></i> <?php echo $fp['clicks'] ?? 0; ?>
                                            </span>
                                            <span title="Teclas">
                                                <i class="fas fa-keyboard"></i> <?php echo $fp['key_presses'] ?? 0; ?>
                                            </span>
                                        </div>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-center">
                                        <span class="text-sm font-bold text-silver"><?php echo $fp['visit_count'] ?? 1; ?></span>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-center">
                                        <?php if (isset($fp['created_at'])): ?>
                                        <div class="text-xs text-silver-dark">
                                            <?php echo date('d/m/Y', strtotime($fp['created_at'])); ?><br>
                                            <?php echo date('H:i', strtotime($fp['created_at'])); ?>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-silver-dark">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-6 py-4 text-center">
                                        <button onclick="viewDetails(<?php echo $fp['id']; ?>)" 
                                                class="text-purple-400 hover:text-purple-300 transition-colors" 
                                                title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="bg-dark-tertiary border-t border-[#2a2a2a] px-6 py-4">
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="text-sm text-silver-dark">
                            Mostrando <?php echo $offset + 1; ?> a <?php echo min($offset + $perPage, $totalRecords); ?> de <?php echo number_format($totalRecords); ?> registros
                        </div>
                        <div class="flex gap-2 flex-wrap justify-center">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" 
                               class="px-4 py-2 bg-dark-card border border-[#2a2a2a] hover:border-purple-500 rounded-lg transition-all">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" 
                               class="px-4 py-2 <?php echo $i === $page ? 'bg-purple-600 text-white font-bold' : 'bg-dark-card border border-[#2a2a2a] hover:border-purple-500 text-silver'; ?> rounded-lg transition-all">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>" 
                               class="px-4 py-2 bg-dark-card border border-[#2a2a2a] hover:border-purple-500 rounded-lg transition-all">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <!-- Modal de Detalhes -->
    <div id="detailsModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-dark-card border border-purple-500/30 rounded-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-dark-tertiary border-b border-[#2a2a2a] px-6 py-4 flex items-center justify-between z-10">
                <h3 class="text-xl font-bold text-silver">Detalhes do Fingerprint</h3>
                <button onclick="closeModal()" class="text-silver-dark hover:text-silver transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="modalContent" class="p-6">
                <div class="flex items-center justify-center py-12">
                    <i class="fas fa-spinner fa-spin text-4xl text-purple-400"></i>
                </div>
            </div>
        </div>
    </div>

    <script>
        function viewDetails(id) {
            document.getElementById('detailsModal').classList.remove('hidden');
            document.getElementById('modalContent').innerHTML = '<div class="flex items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-4xl text-purple-400"></i></div>';
            
            fetch(`fingerprint-details.php?id=${id}`)
                .then(response => response.ok ? response.json() : Promise.reject('Erro na requisição'))
                .then(data => {
                    if (data.error) {
                        document.getElementById('modalContent').innerHTML = `
                            <div class="text-center py-8 text-red-400">
                                <i class="fas fa-exclamation-circle text-4xl mb-4"></i>
                                <p>${data.error}</p>
                            </div>`;
                        return;
                    }
                    
                    const fp = data.fingerprint;
                    document.getElementById('modalContent').innerHTML = `
                        <div class="space-y-4">
                            <div class="bg-dark-tertiary p-4 rounded-lg">
                                <h4 class="text-sm font-semibold text-silver-dark uppercase mb-2">Fingerprint Hash</h4>
                                <code class="text-purple-400 break-all">${fp.fingerprint_hash || 'N/A'}</code>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div><span class="text-silver-dark">IP:</span> <span class="text-silver">${fp.ip_address || 'N/A'}</span></div>
                                <div><span class="text-silver-dark">Visitas:</span> <span class="text-silver">${fp.visit_count || 1}</span></div>
                                <div><span class="text-silver-dark">Risk Score:</span> <span class="text-silver">${fp.risk_score || 0}</span></div>
                                <div><span class="text-silver-dark">Suspeito:</span> <span class="${fp.is_suspicious ? 'text-yellow-400' : 'text-green-400'}">${fp.is_suspicious ? 'Sim' : 'Não'}</span></div>
                            </div>
                            ${fp.user_agent ? `<div class="bg-dark-tertiary p-3 rounded-lg text-xs text-silver break-all">${fp.user_agent}</div>` : ''}
                        </div>`;
                })
                .catch(error => {
                    document.getElementById('modalContent').innerHTML = `
                        <div class="text-center py-8 text-red-400">
                            <i class="fas fa-exclamation-circle text-4xl mb-4"></i>
                            <p>Erro ao carregar detalhes</p>
                        </div>`;
                });
        }
        
        function closeModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }
        
        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>