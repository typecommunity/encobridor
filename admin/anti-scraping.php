<?php
/**
 * Cloaker Pro - Anti-Scraping Dashboard
 * Painel de controle e monitoramento com Multi-Tenancy
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/RateLimiter.php';
require_once '../core/ScraperDetector.php';
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
// DEBUG: LOG PARA IDENTIFICAR PROBLEMAS
// ==========================================
error_log("=== ANTI-SCRAPING DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("User Role: " . ($user['role'] ?? 'NULL'));
error_log("User tenant_id: " . ($user['tenant_id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("===========================");

// Validação crítica
if (!$isSuperAdmin && !$tenantId) {
    error_log("ERRO CRÍTICO: Usuário não-admin sem tenant_id no anti-scraping!");
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
    $rateLimiter = new RateLimiter();
    
    $message = '';
    $error = '';

    // ==========================================
    // PROCESSAR AÇÕES
    // ==========================================

    if (Utils::isPost()) {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'update_config':
                    // Super admin pode atualizar configs globais
                    // Tenant admin pode atualizar apenas suas configs
                    foreach ($_POST['config'] as $key => $value) {
                        $configFilter = ['config_key' => $key];
                        
                        // Se não for super admin, só atualiza configs do seu tenant
                        if (!$isSuperAdmin) {
                            $configFilter['tenant_id'] = $tenantId;
                        }
                        
                        $db->update('antiscraping_config', 
                            ['config_value' => $value], 
                            $configFilter
                        );
                    }
                    $message = 'Configurações atualizadas com sucesso!';
                    break;
                    
                case 'unblock_ip':
                    $ip = $_POST['ip'] ?? '';
                    // RateLimiter já verifica permissão por tenant internamente
                    if ($rateLimiter->unblockIP($ip)) {
                        $message = "IP {$ip} desbloqueado!";
                    } else {
                        $error = "Erro ao desbloquear IP ou permissão negada";
                    }
                    break;
                    
                case 'add_whitelist':
                    $ip = $_POST['ip'] ?? '';
                    $description = $_POST['description'] ?? '';
                    // RateLimiter agora pega tenant_id do contexto automaticamente
                    if ($rateLimiter->addToWhitelist($ip, $description)) {
                        $message = "IP {$ip} adicionado à whitelist!";
                    } else {
                        $error = "Erro ao adicionar à whitelist (IP já existe)";
                    }
                    break;
                    
                case 'remove_whitelist':
                    $ip = $_POST['ip'] ?? '';
                    // RateLimiter já verifica permissão por tenant internamente
                    if ($rateLimiter->removeFromWhitelist($ip)) {
                        $message = "IP {$ip} removido da whitelist!";
                    } else {
                        $error = "Erro ao remover da whitelist ou permissão negada";
                    }
                    break;
                    
                case 'cleanup':
                    $rateLimiter->cleanup($tenantId);
                    $message = 'Limpeza executada com sucesso!';
                    break;
            }
        } catch (Exception $e) {
            $error = 'Erro ao processar ação: ' . $e->getMessage();
            error_log("Anti-scraping action error: " . $e->getMessage());
        }
    }

    // ==========================================
    // OBTER DADOS COM FILTRO DE TENANT
    // ==========================================

    $stats = [
        'blocked_ips' => 0,
        'whitelist_count' => 0,
        'attempts_today' => 0,
        'attempts_hour' => 0
    ];

    // Filtro base para queries
    $tenantFilter = $isSuperAdmin ? [] : ['tenant_id' => $tenantId];

    try {
        $stats['blocked_ips'] = $db->count('blocked_ips', array_merge(
            ['expires_at >' => date('Y-m-d H:i:s')],
            $tenantFilter
        ));
        
        $stats['whitelist_count'] = $db->count('ip_whitelist', $tenantFilter);
        
        $stats['attempts_today'] = $db->count('scraping_attempts', array_merge(
            ['created_at >' => date('Y-m-d 00:00:00')],
            $tenantFilter
        ));
        
        $stats['attempts_hour'] = $db->count('scraping_attempts', array_merge(
            ['created_at >' => date('Y-m-d H:i:s', strtotime('-1 hour'))],
            $tenantFilter
        ));
    } catch (Exception $e) {
        error_log("Stats error: " . $e->getMessage());
    }

    $blockedIPs = [];
    $whitelist = [];
    $topOffenders = [];
    $recentAttempts = [];
    $configs = [];

    try {
        $blockedIPs = $db->select('blocked_ips', array_merge(
            ['expires_at >' => date('Y-m-d H:i:s')],
            $tenantFilter
        ), '*', 'blocked_at DESC', '20');
    } catch (Exception $e) {
        error_log("Blocked IPs error: " . $e->getMessage());
    }

    try {
        $whitelist = $db->select('ip_whitelist', $tenantFilter, '*', 'created_at DESC', '20');
    } catch (Exception $e) {
        error_log("Whitelist error: " . $e->getMessage());
    }

    try {
        $topOffenders = $rateLimiter->getTopOffenders(10, $tenantId);
    } catch (Exception $e) {
        error_log("Top offenders error: " . $e->getMessage());
    }

    try {
        $recentAttempts = $db->select('scraping_attempts', $tenantFilter, '*', 'created_at DESC', '50');
    } catch (Exception $e) {
        error_log("Recent attempts error: " . $e->getMessage());
    }

    try {
        // Buscar configs: globais (tenant_id NULL) ou específicas do tenant
        $configQuery = "SELECT * FROM antiscraping_config WHERE tenant_id IS NULL";
        if (!$isSuperAdmin && $tenantId) {
            $configQuery .= " OR tenant_id = " . intval($tenantId);
        }
        $configQuery .= " ORDER BY category, config_key";
        
        $configResult = $db->query($configQuery);
        while ($row = $configResult->fetch()) {
            $configs[$row['category']][] = $row;
        }
    } catch (Exception $e) {
        error_log("Config error: " . $e->getMessage());
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
    <title>Anti-Scraping - Cloaker Pro</title>
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
        $pageTitle = 'Anti-Scraping Protection';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']) . ' - Sistema de proteção contra bots e scrapers';
        } else {
            $pageSubtitle = 'Sistema de proteção contra bots e scrapers';
        }
        require_once 'header.php'; 
        ?>

        <!-- Content -->
        <main class="p-8 flex-1">
            
            <?php if (!$isSuperAdmin && $currentTenant): ?>
            <!-- Informações do Tenant -->
            <div class="bg-gradient-to-r from-red-900/20 to-orange-900/20 border border-red-500/30 rounded-xl p-4 mb-6 fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gradient-to-br from-red-600 to-orange-600 rounded-xl flex items-center justify-center text-white text-xl font-bold">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <p class="font-bold text-silver text-lg"><?= htmlspecialchars($currentTenant['name']) ?></p>
                            <p class="text-sm text-silver-muted">
                                Proteção ativa para suas campanhas
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Mensagens -->
            <?php if ($message): ?>
            <div class="bg-accent-success/10 border border-accent-success/30 rounded-xl p-4 mb-6 fade-in">
                <i class="fas fa-check-circle text-accent-success mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="bg-accent-danger/10 border border-accent-danger/30 rounded-xl p-4 mb-6 fade-in">
                <i class="fas fa-exclamation-circle text-accent-danger mr-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-danger hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-danger opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">IPs Bloqueados</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['blocked_ips']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">Ativos no momento</p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-danger to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-success hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-success opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Whitelist</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['whitelist_count']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">IPs confiáveis</p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-success to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-warning hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-warning opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Tentativas Hoje</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['attempts_today']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">Detectadas e bloqueadas</p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-warning to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>

                <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-info hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                    <div class="absolute top-4 right-4 text-5xl text-accent-info opacity-10 group-hover:opacity-20 transition-opacity">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="relative z-10">
                        <p class="text-silver-dark text-sm uppercase tracking-wide font-semibold">Última Hora</p>
                        <p class="text-4xl font-bold text-silver mt-2"><?php echo number_format($stats['attempts_hour']); ?></p>
                        <p class="text-silver-muted text-sm mt-2">
                            <?php 
                            $level = $stats['attempts_hour'] > 10 ? 'Crítico' : ($stats['attempts_hour'] > 5 ? 'Médio' : 'Normal');
                            $levelColor = $stats['attempts_hour'] > 10 ? 'text-accent-danger' : ($stats['attempts_hour'] > 5 ? 'text-accent-warning' : 'text-accent-success');
                            ?>
                            Nível: <span class="<?php echo $levelColor; ?>"><?php echo $level; ?></span>
                        </p>
                    </div>
                    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-accent-info to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl shadow-glow mb-6 fade-in">
                <div class="border-b border-[#2a2a2a] bg-dark-tertiary">
                    <nav class="flex flex-wrap gap-2 p-4">
                        <button onclick="showTab('blocked')" id="tab-blocked" class="tab-btn px-6 py-3 rounded-lg bg-gradient-to-r from-accent-danger to-accent-warning text-white font-semibold shadow-glow">
                            <i class="fas fa-ban mr-2"></i> IPs Bloqueados
                        </button>
                        <button onclick="showTab('whitelist')" id="tab-whitelist" class="tab-btn px-6 py-3 rounded-lg text-silver-dark hover:text-silver hover:bg-dark-hover transition-all">
                            <i class="fas fa-check-circle mr-2"></i> Whitelist
                        </button>
                        <button onclick="showTab('attempts')" id="tab-attempts" class="tab-btn px-6 py-3 rounded-lg text-silver-dark hover:text-silver hover:bg-dark-hover transition-all">
                            <i class="fas fa-list mr-2"></i> Tentativas
                        </button>
                        <button onclick="showTab('config')" id="tab-config" class="tab-btn px-6 py-3 rounded-lg text-silver-dark hover:text-silver hover:bg-dark-hover transition-all">
                            <i class="fas fa-cog mr-2"></i> Configurações
                        </button>
                    </nav>
                </div>
                
                <!-- Tab: IPs Bloqueados -->
                <div id="content-blocked" class="tab-content p-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                        <h3 class="text-xl font-bold text-silver">IPs Bloqueados Ativos</h3>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="cleanup">
                            <button type="submit" class="px-4 py-2 bg-accent-info hover:bg-blue-600 hover:shadow-glow rounded-lg font-semibold transition-all">
                                <i class="fas fa-broom mr-2"></i> Limpar Expirados
                            </button>
                        </form>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-dark-tertiary">
                                <tr>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">IP</th>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">Razão</th>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">Severidade</th>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">Expira em</th>
                                    <th class="px-4 py-3 text-center text-silver-dark uppercase text-xs tracking-wide">Tentativas</th>
                                    <th class="px-4 py-3 text-center text-silver-dark uppercase text-xs tracking-wide">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#2a2a2a]">
                                <?php foreach ($blockedIPs as $blocked): ?>
                                <tr class="hover:bg-dark-hover transition-all">
                                    <td class="px-4 py-3 font-mono text-silver"><?php echo htmlspecialchars($blocked['ip_address']); ?></td>
                                    <td class="px-4 py-3 text-sm text-silver-muted"><?php echo htmlspecialchars($blocked['reason']); ?></td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $severityColors = [
                                            'low' => 'bg-accent-warning/20 text-accent-warning border-accent-warning/30',
                                            'medium' => 'bg-orange-500/20 text-orange-400 border-orange-500/30',
                                            'high' => 'bg-accent-danger/20 text-accent-danger border-accent-danger/30',
                                            'critical' => 'bg-purple-500/20 text-purple-400 border-purple-500/30'
                                        ];
                                        $color = $severityColors[$blocked['severity']] ?? 'bg-dark-tertiary';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold border <?php echo $color; ?>">
                                            <?php echo strtoupper($blocked['severity']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-silver-muted">
                                        <?php 
                                        if ($blocked['expires_at']) {
                                            echo Utils::timeAgo($blocked['expires_at']);
                                        } else {
                                            echo '<span class="text-accent-danger font-semibold">Permanente</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold text-silver"><?php echo $blocked['attempts']; ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <form method="POST" class="inline" onsubmit="return confirm('Desbloquear este IP?')">
                                            <input type="hidden" name="action" value="unblock_ip">
                                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($blocked['ip_address']); ?>">
                                            <button type="submit" class="px-3 py-1 bg-accent-success hover:bg-green-600 rounded-lg text-sm font-semibold transition-all">
                                                <i class="fas fa-unlock"></i> Desbloquear
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($blockedIPs)): ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-12 text-center">
                                        <i class="fas fa-check-circle text-6xl text-accent-success/30 mb-4"></i>
                                        <p class="text-silver">Nenhum IP bloqueado no momento</p>
                                        <p class="text-silver-dark text-sm mt-2">Sistema funcionando perfeitamente!</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Tab: Whitelist -->
                <div id="content-whitelist" class="tab-content p-6 hidden">
                    <div class="mb-6 bg-dark-tertiary border border-[#2a2a2a] rounded-xl p-6">
                        <h3 class="text-xl font-bold mb-4 text-silver">Adicionar à Whitelist</h3>
                        <form method="POST" class="flex flex-col sm:flex-row gap-4">
                            <input type="hidden" name="action" value="add_whitelist">
                            <input type="text" name="ip" placeholder="Ex: 192.168.1.1" required
                                   class="flex-1 px-4 py-3 bg-dark-card border border-[#2a2a2a] rounded-lg focus:border-accent-success focus:outline-none text-silver">
                            <input type="text" name="description" placeholder="Descrição (opcional)"
                                   class="flex-1 px-4 py-3 bg-dark-card border border-[#2a2a2a] rounded-lg focus:border-accent-success focus:outline-none text-silver">
                            <button type="submit" class="px-6 py-3 bg-gradient-to-r from-accent-success to-green-600 hover:shadow-glow rounded-lg font-semibold transition-all whitespace-nowrap">
                                <i class="fas fa-plus mr-2"></i> Adicionar
                            </button>
                        </form>
                    </div>
                    
                    <h3 class="text-xl font-bold mb-4 text-silver">IPs na Whitelist</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-dark-tertiary">
                                <tr>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">IP</th>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">Descrição</th>
                                    <th class="px-4 py-3 text-left text-silver-dark uppercase text-xs tracking-wide">Adicionado em</th>
                                    <th class="px-4 py-3 text-center text-silver-dark uppercase text-xs tracking-wide">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#2a2a2a]">
                                <?php foreach ($whitelist as $white): ?>
                                <tr class="hover:bg-dark-hover transition-all">
                                    <td class="px-4 py-3 font-mono text-silver"><?php echo htmlspecialchars($white['ip_address']); ?></td>
                                    <td class="px-4 py-3 text-silver-muted"><?php echo htmlspecialchars($white['description'] ?: '-'); ?></td>
                                    <td class="px-4 py-3 text-silver-muted"><?php echo Utils::timeAgo($white['created_at']); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <form method="POST" class="inline" onsubmit="return confirm('Remover da whitelist?')">
                                            <input type="hidden" name="action" value="remove_whitelist">
                                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($white['ip_address']); ?>">
                                            <button type="submit" class="px-3 py-1 bg-accent-danger hover:bg-red-600 rounded-lg text-sm font-semibold transition-all">
                                                <i class="fas fa-trash"></i> Remover
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($whitelist)): ?>
                                <tr>
                                    <td colspan="4" class="px-4 py-12 text-center">
                                        <i class="fas fa-inbox text-6xl text-silver-dark/30 mb-4"></i>
                                        <p class="text-silver-muted">Nenhum IP na whitelist</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Tab: Tentativas -->
                <div id="content-attempts" class="tab-content p-6 hidden">
                    <h3 class="text-xl font-bold mb-4 text-silver">Últimas Tentativas de Scraping</h3>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-dark-tertiary">
                                <tr>
                                    <th class="px-3 py-2 text-left text-silver-dark uppercase text-xs">Data/Hora</th>
                                    <th class="px-3 py-2 text-left text-silver-dark uppercase text-xs">IP</th>
                                    <th class="px-3 py-2 text-left text-silver-dark uppercase text-xs">Tipo</th>
                                    <th class="px-3 py-2 text-left text-silver-dark uppercase text-xs">Score</th>
                                    <th class="px-3 py-2 text-left text-silver-dark uppercase text-xs">Ação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#2a2a2a]">
                                <?php foreach ($recentAttempts as $attempt): ?>
                                <tr class="hover:bg-dark-hover transition-all">
                                    <td class="px-3 py-2 text-silver-muted"><?php echo date('d/m H:i', strtotime($attempt['created_at'])); ?></td>
                                    <td class="px-3 py-2 font-mono text-silver"><?php echo htmlspecialchars(substr($attempt['ip_address'], 0, 15)); ?></td>
                                    <td class="px-3 py-2">
                                        <span class="px-2 py-1 bg-dark-tertiary border border-[#2a2a2a] rounded text-xs text-silver">
                                            <?php echo htmlspecialchars($attempt['detection_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <?php
                                        $scoreColor = $attempt['score'] >= 70 ? 'text-accent-danger' : ($attempt['score'] >= 40 ? 'text-accent-warning' : 'text-accent-success');
                                        ?>
                                        <span class="font-bold <?php echo $scoreColor; ?>"><?php echo $attempt['score']; ?>/100</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <?php
                                        $actionColors = [
                                            'blocked' => 'bg-accent-danger/20 text-accent-danger border-accent-danger/30',
                                            'logged' => 'bg-accent-info/20 text-accent-info border-accent-info/30',
                                            'captcha' => 'bg-accent-warning/20 text-accent-warning border-accent-warning/30'
                                        ];
                                        $color = $actionColors[$attempt['action_taken']] ?? 'bg-dark-tertiary';
                                        ?>
                                        <span class="px-2 py-1 rounded border text-xs font-semibold <?php echo $color; ?>">
                                            <?php echo htmlspecialchars($attempt['action_taken']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentAttempts)): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-12 text-center">
                                        <i class="fas fa-shield-alt text-6xl text-accent-success/30 mb-4"></i>
                                        <p class="text-silver-muted">Nenhuma tentativa registrada</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Tab: Configurações -->
                <div id="content-config" class="tab-content p-6 hidden">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_config">
                        
                        <?php foreach ($configs as $category => $categoryConfigs): ?>
                        <div class="mb-8">
                            <h3 class="text-xl font-bold mb-4 capitalize text-silver border-b border-[#2a2a2a] pb-3">
                                <i class="fas fa-cog mr-2"></i>
                                <?php echo str_replace('_', ' ', $category); ?>
                            </h3>
                            
                            <div class="space-y-3">
                                <?php foreach ($categoryConfigs as $config): ?>
                                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-xl hover:border-silver-dark transition-all">
                                    <div class="flex-1">
                                        <label class="font-semibold text-silver block mb-1">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $config['config_key']))); ?>
                                            <?php if ($config['tenant_id']): ?>
                                            <span class="ml-2 px-2 py-1 text-xs bg-blue-500/20 text-blue-400 border border-blue-500/30 rounded">PERSONALIZADO</span>
                                            <?php endif; ?>
                                        </label>
                                        <?php if ($config['description']): ?>
                                        <p class="text-sm text-silver-dark"><?php echo htmlspecialchars($config['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="w-full sm:w-auto sm:ml-4">
                                        <?php if ($config['config_type'] === 'bool'): ?>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="config[<?php echo $config['config_key']; ?>]" value="1"
                                                   <?php echo filter_var($config['config_value'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : ''; ?>
                                                   class="sr-only peer">
                                            <div class="w-11 h-6 bg-dark-card peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-silver after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-accent-success"></div>
                                        </label>
                                        <?php elseif ($config['config_type'] === 'int'): ?>
                                        <input type="number" name="config[<?php echo $config['config_key']; ?>]" 
                                               value="<?php echo htmlspecialchars($config['config_value']); ?>"
                                               class="w-full sm:w-24 px-3 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg text-center text-silver focus:border-accent-info focus:outline-none">
                                        <?php else: ?>
                                        <input type="text" name="config[<?php echo $config['config_key']; ?>]" 
                                               value="<?php echo htmlspecialchars($config['config_value']); ?>"
                                               class="w-full sm:w-64 px-3 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg text-silver focus:border-accent-info focus:outline-none">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="px-8 py-3 bg-gradient-to-r from-accent-info to-blue-600 hover:shadow-glow rounded-lg font-semibold transition-all">
                                <i class="fas fa-save mr-2"></i> Salvar Configurações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </main>

        <?php require_once 'footer.php'; ?>
    </div>
    
    <script>
    function showTab(tab) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('bg-gradient-to-r', 'from-accent-danger', 'to-accent-warning', 'text-white', 'shadow-glow');
            el.classList.add('text-silver-dark');
        });
        
        document.getElementById('content-' + tab).classList.remove('hidden');
        const btn = document.getElementById('tab-' + tab);
        btn.classList.add('bg-gradient-to-r', 'from-accent-danger', 'to-accent-warning', 'text-white', 'shadow-glow');
        btn.classList.remove('text-silver-dark');
    }
    
    // Auto-refresh a cada 30 segundos
    setInterval(() => location.reload(), 30000);
    </script>
</body>
</html>