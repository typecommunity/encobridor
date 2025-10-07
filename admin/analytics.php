<?php
/**
 * Cloaker Pro - Analytics
 * Página de análise e relatórios com Multi-Tenancy
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Analytics.php';
require_once '../core/Campaign.php';

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
error_log("=== ANALYTICS DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("User Role: " . ($user['role'] ?? 'NULL'));
error_log("User tenant_id: " . ($user['tenant_id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("======================");

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

try {
    $campaign = new Campaign();
    $analytics = new Analytics();

    // Parâmetros de filtro
    $campaignId = $_GET['campaign_id'] ?? '';
    $period = $_GET['period'] ?? 'today';

    // IMPORTANTE: Validar permissão se campanha específica foi selecionada
    if (!empty($campaignId)) {
        $selectedCampaign = $campaign->get($campaignId);
        
        if (!$selectedCampaign) {
            header('Location: analytics.php?error=campaign_not_found');
            exit;
        }
        
        // Verificar se usuário tem permissão para ver esta campanha
        if (!$isSuperAdmin && $selectedCampaign['tenant_id'] != $tenantId) {
            error_log("ERRO: Tentativa de acesso não autorizado à campanha {$campaignId} por usuário {$user['id']}");
            header('Location: analytics.php?error=unauthorized');
            exit;
        }
    }

    // Obter dados com tratamento de erro
    $stats = ['total' => 0, 'unique' => 0, 'safe' => 0, 'money' => 0, 'bots' => 0];
    $hourlyTraffic = [];
    $topCountries = [];
    $deviceTypes = [];
    
    try {
        if (!empty($campaignId)) {
            $stats = $analytics->getCampaignStats($campaignId, $period);
        } else {
            // Passar tenant_id para os métodos de analytics
            $analyticsFilters = $isSuperAdmin ? [] : ['tenant_id' => $tenantId];
            
            $stats = [
                'total' => $analytics->getTotalVisitors($period, $analyticsFilters),
                'unique' => $analytics->getUniqueVisitors($period, $analyticsFilters),
                'safe' => $analytics->getRedirects('safe', $period, $analyticsFilters),
                'money' => $analytics->getRedirects('money', $period, $analyticsFilters),
                'bots' => $analytics->getBotBlocks($period, $analyticsFilters)
            ];
        }
    } catch (Exception $e) {
        error_log("Analytics stats error: " . $e->getMessage());
    }

    try {
        $analyticsFilters = $isSuperAdmin ? [] : ['tenant_id' => $tenantId];
        $hourlyTraffic = $analytics->getHourlyTraffic($campaignId, $analyticsFilters);
    } catch (Exception $e) {
        error_log("Hourly traffic error: " . $e->getMessage());
        for ($i = 0; $i < 24; $i++) {
            $hourlyTraffic[] = ['hour' => sprintf('%02d:00', $i), 'visitors' => 0];
        }
    }
    
    try {
        $analyticsFilters = $isSuperAdmin ? [] : ['tenant_id' => $tenantId];
        $topCountries = $analytics->getTopCountries(10, $campaignId, $analyticsFilters);
    } catch (Exception $e) {
        error_log("Top countries error: " . $e->getMessage());
    }
    
    try {
        $analyticsFilters = $isSuperAdmin ? [] : ['tenant_id' => $tenantId];
        $deviceTypes = $analytics->getDeviceBreakdown($campaignId, $analyticsFilters);
    } catch (Exception $e) {
        error_log("Device types error: " . $e->getMessage());
        $deviceTypes = [
            ['type' => 'Desktop', 'count' => 0],
            ['type' => 'Mobile', 'count' => 0],
            ['type' => 'Tablet', 'count' => 0]
        ];
    }

    // Lista de campanhas para o filtro - FILTRAR POR TENANT
    $campaignFilters = [];
    if (!$isSuperAdmin && $tenantId) {
        $campaignFilters['tenant_id'] = $tenantId;
    }
    
    $allCampaigns = ['data' => []];
    try {
        $allCampaigns = $campaign->listCampaigns(1, 100, $campaignFilters);
    } catch (Exception $e) {
        error_log("List campaigns error: " . $e->getMessage());
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
    <title>Analytics - Cloaker Pro</title>
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
        @keyframes countUp { from { opacity: 0; transform: scale(0.8); } to { opacity: 1; transform: scale(1); } }
        
        .animate-shine::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent); animation: shine 3s infinite; }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        .count-up { animation: countUp 0.6s ease-out; }
        
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
        $pageTitle = 'Analytics';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']);
        }
        $pageInfo = '<div class="flex items-center gap-3">
                        <div class="px-4 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg">
                            <i class="fas fa-calendar-alt text-silver-dark mr-2"></i>
                            <span class="text-silver text-sm font-medium">' . 
                            ($period === 'today' ? 'Hoje' : 
                             ($period === 'yesterday' ? 'Ontem' : 
                              ($period === 'week' ? '7 dias' : '30 dias'))) . 
                            '</span>
                        </div>
                    </div>';
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
                            <p class="text-sm text-silver-muted">Visualizando analytics do cliente</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
            <div class="bg-accent-danger bg-opacity-10 border border-accent-danger text-accent-danger px-4 py-3 rounded-lg mb-4 fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?php 
                if ($_GET['error'] === 'campaign_not_found') {
                    echo 'Campanha não encontrada.';
                } elseif ($_GET['error'] === 'unauthorized') {
                    echo 'Você não tem permissão para visualizar esta campanha.';
                }
                ?>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in">
                <form method="GET" class="flex flex-wrap items-end gap-4">
                    <!-- Campaign Filter -->
                    <div class="flex-1 min-w-64">
                        <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">Campanha</label>
                        <select name="campaign_id" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            <option value="">Todas as Campanhas</option>
                            <?php foreach ($allCampaigns['data'] as $camp): ?>
                            <option value="<?php echo $camp['id']; ?>" <?php echo $campaignId == $camp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($camp['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Period Filter -->
                    <div class="min-w-48">
                        <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">Período</label>
                        <select name="period" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Hoje</option>
                            <option value="yesterday" <?php echo $period === 'yesterday' ? 'selected' : ''; ?>>Ontem</option>
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Últimos 7 dias</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Últimos 30 dias</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="bg-gradient-silver hover:shadow-glow text-dark font-bold px-6 py-3 rounded-lg transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                    
                    <?php if ($campaignId || $period !== 'today'): ?>
                    <a href="analytics.php" class="bg-dark-tertiary hover:bg-dark-hover border border-[#2a2a2a] hover:border-accent-danger text-silver-dark hover:text-accent-danger px-6 py-3 rounded-lg transition-all">
                        <i class="fas fa-times mr-2"></i>Limpar
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Visitors -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-info hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-info opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Total de Visitantes</p>
                        <p class="text-4xl font-bold text-silver mt-2 count-up"><?php echo number_format($stats['total']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">
                            <span class="text-accent-success font-semibold"><?php echo number_format($stats['unique'] ?? 0); ?></span> únicos
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-info to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <!-- Safe Page -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-warning hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-warning opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Safe Page</p>
                        <p class="text-4xl font-bold text-silver mt-2 count-up">
                            <?php echo $stats['total'] > 0 ? round(($stats['safe'] / $stats['total']) * 100, 1) : 0; ?>%
                        </p>
                        <p class="text-silver-muted text-sm mt-2">
                            <span class="text-silver"><?php echo number_format($stats['safe']); ?></span> redirecionamentos
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-warning to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <!-- Money Page -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-success hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-success opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Money Page</p>
                        <p class="text-4xl font-bold text-silver mt-2 count-up">
                            <?php echo $stats['total'] > 0 ? round(($stats['money'] / $stats['total']) * 100, 1) : 0; ?>%
                        </p>
                        <p class="text-silver-muted text-sm mt-2">
                            <span class="text-accent-success font-semibold"><?php echo number_format($stats['money']); ?></span> redirecionamentos
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-success to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <!-- Bots Blocked -->
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-danger hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-danger opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Bots Bloqueados</p>
                        <p class="text-4xl font-bold text-silver mt-2 count-up"><?php echo number_format($stats['bots']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">
                            <span class="text-accent-danger font-semibold"><?php echo $stats['total'] > 0 ? round(($stats['bots'] / $stats['total']) * 100, 1) : 0; ?>%</span> do tráfego
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-danger to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Hourly Traffic -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 fade-in">
                    <h3 class="text-lg font-bold text-silver mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-silver"></i>
                        Tráfego por Hora
                    </h3>
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>

                <!-- Device Breakdown -->
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

            <!-- Top Countries -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary">
                    <h3 class="text-lg font-bold text-silver flex items-center gap-2">
                        <i class="fas fa-globe text-silver"></i>
                        Top Países
                    </h3>
                </div>
                <div class="p-6">
                    <?php if (empty($topCountries)): ?>
                    <div class="text-center py-12 text-silver-dark">
                        <i class="fas fa-globe text-5xl mb-4 opacity-30"></i>
                        <p>Nenhum dado disponível ainda</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($topCountries as $country): ?>
                        <div class="flex items-center justify-between p-4 hover:bg-dark-hover rounded-lg transition-all group">
                            <div class="flex items-center gap-4">
                                <span class="text-3xl"><?php echo $country['flag'] ?? '🌍'; ?></span>
                                <div>
                                    <p class="text-sm font-semibold text-silver group-hover:text-silver-light transition-colors"><?php echo htmlspecialchars($country['name']); ?></p>
                                    <p class="text-xs text-silver-dark"><?php echo number_format($country['count']); ?> visitantes</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-24 bg-dark-tertiary rounded-full h-2">
                                    <div class="bg-gradient-silver h-2 rounded-full transition-all" style="width: <?php echo min($country['percentage'], 100); ?>%"></div>
                                </div>
                                <span class="text-sm font-bold text-silver min-w-12 text-right"><?php echo number_format($country['percentage'], 1); ?>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <script>
        Chart.defaults.color = '#c0c0c0';
        Chart.defaults.borderColor = '#2a2a2a';
        
        // Hourly Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($hourlyTraffic, 'hour')); ?>,
                datasets: [{
                    label: 'Visitantes',
                    data: <?php echo json_encode(array_column($hourlyTraffic, 'visitors')); ?>,
                    backgroundColor: 'rgba(192, 192, 192, 0.7)',
                    borderColor: '#c0c0c0',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        ticks: { color: '#808080', precision: 0 },
                        grid: { color: '#2a2a2a' }
                    },
                    x: {
                        ticks: { color: '#808080' },
                        grid: { color: '#2a2a2a' }
                    }
                }
            }
        });

        // Device Chart
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
                plugins: { 
                    legend: { 
                        position: 'bottom',
                        labels: {
                            color: '#c0c0c0',
                            padding: 15,
                            font: { size: 12 }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>