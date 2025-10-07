<?php
/**
 * Cloaker Pro - Campaign Management
 * Gerenciamento de campanhas com Multi-Tenancy e Domínios Personalizados
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Campaign.php';
require_once '../core/Analytics.php';
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
// DOMÍNIOS PERSONALIZADOS: CONFIGURAR BASE URL
// ==========================================
$tenantDomain = null;
$campaignBaseUrl = rtrim(BASE_URL, '/') . '/c/';

// Se o tenant tem domínio personalizado configurado, usar ele
if (!$isSuperAdmin && $currentTenant && !empty($currentTenant['domain'])) {
    $tenantDomain = $currentTenant['domain'];
    // Verificar se tem protocolo, se não, adicionar https
    if (!preg_match('/^https?:\/\//', $tenantDomain)) {
        $tenantDomain = 'https://' . $tenantDomain;
    }
    $campaignBaseUrl = rtrim($tenantDomain, '/') . '/c/';
}

// ==========================================
// DEBUG: LOG PARA IDENTIFICAR PROBLEMAS
// ==========================================
error_log("=== CAMPAIGNS DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("User Role: " . ($user['role'] ?? 'NULL'));
error_log("User tenant_id: " . ($user['tenant_id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("Tenant Domain: " . ($tenantDomain ?? 'NULL'));
error_log("Campaign Base URL: " . $campaignBaseUrl);
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

    // Processar ações
    $action = $_GET['action'] ?? '';
    $message = '';
    $error = '';

    // Deletar campanha
    if ($action === 'delete' && isset($_GET['id'])) {
        try {
            // Verificar se a campanha pertence ao tenant
            $camp = $campaign->get($_GET['id']);
            if ($camp) {
                // Verificar permissão
                if (!$isSuperAdmin && $camp['tenant_id'] != $tenantId) {
                    $error = 'Você não tem permissão para excluir esta campanha.';
                } else {
                    if ($campaign->delete($_GET['id'])) {
                        $message = 'Campanha excluída com sucesso!';
                    } else {
                        $error = 'Erro ao excluir campanha.';
                    }
                }
            } else {
                $error = 'Campanha não encontrada.';
            }
        } catch (Exception $e) {
            $error = 'Erro ao excluir: ' . $e->getMessage();
            error_log("Delete campaign error: " . $e->getMessage());
        }
    }

    // Ativar/Desativar campanha
    if ($action === 'toggle' && isset($_GET['id'])) {
        try {
            $camp = $campaign->get($_GET['id']);
            if ($camp) {
                // Verificar permissão
                if (!$isSuperAdmin && $camp['tenant_id'] != $tenantId) {
                    $error = 'Você não tem permissão para alterar esta campanha.';
                } else {
                    $newStatus = $camp['status'] === 'active' ? 'paused' : 'active';
                    if ($campaign->updateStatus($_GET['id'], $newStatus)) {
                        $message = 'Status da campanha atualizado!';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Erro ao alterar status: ' . $e->getMessage();
            error_log("Toggle campaign error: " . $e->getMessage());
        }
    }

    // Duplicar campanha
    if ($action === 'duplicate' && isset($_GET['id'])) {
        try {
            $camp = $campaign->get($_GET['id']);
            if ($camp) {
                // Verificar permissão
                if (!$isSuperAdmin && $camp['tenant_id'] != $tenantId) {
                    $error = 'Você não tem permissão para duplicar esta campanha.';
                } else {
                    $newId = $campaign->duplicate($_GET['id']);
                    if ($newId) {
                        $message = 'Campanha duplicada com sucesso!';
                        header('Location: campaign-edit.php?id=' . $newId);
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Erro ao duplicar: ' . $e->getMessage();
            error_log("Duplicate campaign error: " . $e->getMessage());
        }
    }

    // Regenerar slug
    if ($action === 'regenerate-slug' && isset($_GET['id'])) {
        try {
            $camp = $campaign->get($_GET['id']);
            if ($camp) {
                // Verificar permissão
                if (!$isSuperAdmin && $camp['tenant_id'] != $tenantId) {
                    $error = 'Você não tem permissão para alterar esta campanha.';
                } else {
                    if ($campaign->regenerateSlug($_GET['id'])) {
                        $message = 'Link da campanha regenerado com sucesso! Atualize os anúncios.';
                    } else {
                        $error = 'Erro ao regenerar link.';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Erro ao regenerar: ' . $e->getMessage();
            error_log("Regenerate slug error: " . $e->getMessage());
        }
    }

    // Obter lista de campanhas
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $perPage = 10;
    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? '';

    $filters = [];
    if ($search) {
        $filters['search'] = $search;
    }
    if ($filter) {
        $filters['status'] = $filter;
    }
    
    // IMPORTANTE: Adicionar filtro de tenant
    if (!$isSuperAdmin && $tenantId) {
        $filters['tenant_id'] = $tenantId;
    }

    $campaigns = ['data' => [], 'total' => 0];
    try {
        $campaigns = $campaign->listCampaigns($page, $perPage, $filters);
    } catch (Exception $e) {
        $error = 'Erro ao carregar campanhas.';
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
    <title>Campanhas - Cloaker Pro</title>
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
        $pageTitle = 'Campanhas';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']);
        }
        $pageAction = '<button onclick="window.location.href=\'campaign-create.php\'" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-2 px-6 rounded-lg transition-all duration-300 transform hover:scale-105"><i class="fas fa-plus mr-2"></i>Nova Campanha</button>';
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
                                Campanhas: <span class="font-semibold text-blue-400"><?= count($campaigns['data']) ?>/<?= $currentTenant['max_campaigns'] ?></span>
                                <?php if ($tenantDomain): ?>
                                • Domínio: <span class="font-semibold text-purple-400"><?= htmlspecialchars(str_replace(['http://', 'https://'], '', $tenantDomain)) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php if (!$tenantDomain): ?>
                    <a href="domains.php" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-semibold transition-all">
                        <i class="fas fa-globe mr-2"></i>Configurar Domínio
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Messages -->
            <?php if ($message): ?>
            <div class="bg-accent-success bg-opacity-10 border border-accent-success text-accent-success px-4 py-3 rounded-lg mb-4 fade-in">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="bg-accent-danger bg-opacity-10 border border-accent-danger text-accent-danger px-4 py-3 rounded-lg mb-4 fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in">
                <form method="GET" class="flex flex-wrap gap-4">
                    <!-- Search -->
                    <div class="flex-1 min-w-64">
                        <div class="relative">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Buscar campanhas..."
                                   class="w-full pl-12 pr-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all">
                            <i class="fas fa-search absolute left-4 top-4 text-silver-dark"></i>
                        </div>
                    </div>
                    
                    <!-- Status Filter -->
                    <select name="filter" onchange="this.form.submit()"
                            class="px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                        <option value="">Todos os Status</option>
                        <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Ativas</option>
                        <option value="paused" <?php echo $filter === 'paused' ? 'selected' : ''; ?>>Pausadas</option>
                        <option value="draft" <?php echo $filter === 'draft' ? 'selected' : ''; ?>>Rascunhos</option>
                    </select>
                    
                    <!-- Search Button -->
                    <button type="submit" class="bg-dark-tertiary hover:bg-dark-hover border border-[#2a2a2a] hover:border-silver text-silver px-6 py-3 rounded-lg transition-all">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                    
                    <?php if ($search || $filter): ?>
                    <a href="campaigns.php" class="bg-dark-tertiary hover:bg-dark-hover border border-[#2a2a2a] hover:border-accent-danger text-silver-dark hover:text-accent-danger px-6 py-3 rounded-lg transition-all">
                        <i class="fas fa-times mr-2"></i>Limpar
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Campaigns Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <?php foreach ($campaigns['data'] as $camp): ?>
                <?php 
                    $stats = ['total' => 0, 'safe' => 0, 'money' => 0];
                    try {
                        $stats = $analytics->getCampaignStats($camp['id'], 'all');
                    } catch (Exception $e) {
                        error_log("Campaign stats error: " . $e->getMessage());
                    }
                    
                    $safeRate = $stats['total'] > 0 ? round(($stats['safe'] / $stats['total']) * 100, 1) : 0;
                    $moneyRate = $stats['total'] > 0 ? round(($stats['money'] / $stats['total']) * 100, 1) : 0;
                    $isSlugSecure = $campaign->isSlugSecure($camp['slug']);
                    
                    // USAR DOMÍNIO PERSONALIZADO SE CONFIGURADO
                    $publicUrl = $campaignBaseUrl . $camp['slug'];
                ?>
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-silver hover:shadow-glow transition-all fade-in">
                    <!-- Header -->
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <h3 class="text-xl font-bold text-silver mb-2"><?php echo htmlspecialchars($camp['name']); ?></h3>
                            <div class="flex items-center gap-2 flex-wrap">
                                <?php if ($camp['status'] === 'active'): ?>
                                <span class="pulse-glow px-3 py-1 text-xs font-semibold rounded-full bg-accent-success bg-opacity-20 text-accent-success border border-accent-success inline-flex items-center gap-2">
                                    <i class="fas fa-circle text-xs"></i>Ativa
                                </span>
                                <?php elseif ($camp['status'] === 'paused'): ?>
                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-accent-warning bg-opacity-20 text-accent-warning border border-accent-warning inline-flex items-center gap-2">
                                    <i class="fas fa-pause-circle"></i>Pausada
                                </span>
                                <?php else: ?>
                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-silver-dark bg-opacity-20 text-silver-dark border border-silver-dark inline-flex items-center gap-2">
                                    <i class="fas fa-file"></i>Rascunho
                                </span>
                                <?php endif; ?>
                                
                                <span class="text-xs text-silver-dark">
                                    <?php echo ucfirst($camp['mode'] ?? 'redirect'); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Link da Campanha com Domínio Personalizado -->
                    <div class="bg-dark-tertiary border border-[#2a2a2a] rounded-lg p-4 mb-4">
                        <div class="flex items-center justify-between mb-3">
                            <label class="block text-xs font-semibold text-silver-dark uppercase tracking-wide">
                                <i class="fas fa-link mr-1"></i>Link da Campanha
                            </label>
                            <div class="flex items-center gap-2">
                                <?php if ($tenantDomain): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded bg-purple-600 bg-opacity-20 text-purple-400 border border-purple-500">
                                    <i class="fas fa-globe mr-1"></i>Domínio Personalizado
                                </span>
                                <?php endif; ?>
                                
                                <?php if ($isSlugSecure): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded bg-accent-success bg-opacity-20 text-accent-success border border-accent-success">
                                    <i class="fas fa-shield-alt mr-1"></i>Seguro
                                </span>
                                <?php else: ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded bg-accent-warning bg-opacity-20 text-accent-warning border border-accent-warning">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>Inseguro
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <input 
                                type="text" 
                                value="<?php echo htmlspecialchars($publicUrl); ?>" 
                                id="link-<?php echo htmlspecialchars($camp['id']); ?>"
                                readonly
                                class="flex-1 px-3 py-2 bg-dark-card border border-[#2a2a2a] rounded text-sm text-silver font-mono cursor-pointer hover:border-silver transition-all"
                                onclick="this.select()"
                            >
                            <button 
                                onclick="copyToClipboard('link-<?php echo htmlspecialchars($camp['id']); ?>', '<?php echo htmlspecialchars($publicUrl); ?>')"
                                class="px-4 py-2 bg-gradient-silver text-dark rounded-lg hover:shadow-glow transition-all flex items-center gap-2 whitespace-nowrap font-semibold"
                                title="Copiar link"
                            >
                                <i class="fas fa-copy"></i>
                                <span class="hidden sm:inline">Copiar</span>
                            </button>
                        </div>
                        
                        <!-- Informações sobre o domínio -->
                        <?php if ($tenantDomain): ?>
                        <div class="mt-3 flex items-start gap-2 text-xs text-purple-400">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            <div class="flex-1">
                                <span>Usando domínio personalizado: <strong><?php echo htmlspecialchars(str_replace(['http://', 'https://'], '', $tenantDomain)); ?></strong></span>
                                <a href="domains.php" class="ml-2 underline hover:text-purple-300">
                                    Gerenciar domínios
                                </a>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mt-3 flex items-start gap-2 text-xs text-silver-dark">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            <div class="flex-1">
                                <span>Usando domínio padrão. </span>
                                <a href="domains.php" class="underline hover:text-silver">
                                    Configure um domínio personalizado
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!$isSlugSecure): ?>
                        <div class="mt-3 flex items-start gap-2 text-xs text-accent-warning">
                            <i class="fas fa-exclamation-triangle mt-0.5"></i>
                            <div class="flex-1">
                                <span>Link inseguro detectado! </span>
                                <a href="?action=regenerate-slug&id=<?php echo $camp['id']; ?>" 
                                   onclick="return confirm('Isso irá gerar um novo link. Links antigos pararão de funcionar. Continuar?')"
                                   class="underline hover:text-accent-warning font-semibold">
                                    Clique para regenerar
                                </a>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mt-3 flex items-start gap-2 text-xs text-silver-dark">
                            <i class="fas fa-shield-alt text-accent-success mt-0.5"></i>
                            <span>Link criptograficamente seguro. Não compartilhe publicamente.</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Estatísticas -->
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div class="bg-dark-tertiary rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-silver"><?php echo number_format($stats['total']); ?></div>
                            <div class="text-xs text-silver-dark mt-1">Visitantes</div>
                        </div>
                        <div class="bg-dark-tertiary rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-accent-warning"><?php echo $safeRate; ?>%</div>
                            <div class="text-xs text-silver-dark mt-1">Safe</div>
                        </div>
                        <div class="bg-dark-tertiary rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-accent-success"><?php echo $moneyRate; ?>%</div>
                            <div class="text-xs text-silver-dark mt-1">Money</div>
                        </div>
                    </div>

                    <!-- Info Adicional -->
                    <div class="flex items-center justify-between mb-4 text-xs text-silver-dark">
                        <span>
                            <i class="fas fa-calendar mr-1"></i>
                            Criada em <?php echo date('d/m/Y', strtotime($camp['created_at'])); ?>
                        </span>
                    </div>

                    <!-- Ações -->
                    <div class="grid grid-cols-2 gap-2 pt-4 border-t border-[#2a2a2a]">
                        <a href="analytics.php?campaign_id=<?php echo $camp['id']; ?>"
                           class="px-3 py-2 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg hover:border-accent-info hover:text-accent-info transition-all text-center text-sm font-semibold flex items-center justify-center gap-2">
                            <i class="fas fa-chart-bar"></i>
                            <span>Stats</span>
                        </a>
                        
                        <a href="campaign-edit.php?id=<?php echo $camp['id']; ?>"
                           class="px-3 py-2 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg hover:border-silver transition-all text-center text-sm font-semibold flex items-center justify-center gap-2">
                            <i class="fas fa-edit"></i>
                            <span>Editar</span>
                        </a>
                        
                        <a href="?action=toggle&id=<?php echo $camp['id']; ?>"
                           class="px-3 py-2 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg hover:border-accent-<?php echo $camp['status'] === 'active' ? 'warning' : 'success'; ?> hover:text-accent-<?php echo $camp['status'] === 'active' ? 'warning' : 'success'; ?> transition-all text-center text-sm font-semibold flex items-center justify-center gap-2">
                            <i class="fas fa-<?php echo $camp['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                            <span><?php echo $camp['status'] === 'active' ? 'Pausar' : 'Ativar'; ?></span>
                        </a>
                        
                        <a href="?action=duplicate&id=<?php echo $camp['id']; ?>"
                           class="px-3 py-2 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg hover:border-accent-info hover:text-accent-info transition-all text-center text-sm font-semibold flex items-center justify-center gap-2">
                            <i class="fas fa-copy"></i>
                            <span>Duplicar</span>
                        </a>
                        
                        <?php if (!$isSlugSecure): ?>
                        <a href="?action=regenerate-slug&id=<?php echo $camp['id']; ?>"
                           onclick="return confirm('Isso irá gerar um novo link. Links antigos pararão de funcionar. Continuar?')"
                           class="px-3 py-2 bg-dark-tertiary border border-[#2a2a2a] text-accent-warning rounded-lg hover:border-accent-warning transition-all text-center text-sm font-semibold flex items-center justify-center gap-2">
                            <i class="fas fa-sync"></i>
                            <span>Regenerar</span>
                        </a>
                        <?php endif; ?>
                        
                        <a href="?action=delete&id=<?php echo $camp['id']; ?>"
                           onclick="return confirm('Tem certeza que deseja excluir esta campanha? Esta ação não pode ser desfeita.')"
                           class="<?php echo !$isSlugSecure ? '' : 'col-span-2'; ?> px-3 py-2 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg hover:border-accent-danger hover:text-accent-danger transition-all text-center text-sm font-semibold flex items-center justify-center gap-2">
                            <i class="fas fa-trash"></i>
                            <span>Excluir</span>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($campaigns['data'])): ?>
                <div class="col-span-2 bg-dark-card border border-[#2a2a2a] rounded-xl p-16 text-center">
                    <div class="text-silver-dark">
                        <i class="fas fa-inbox text-6xl mb-4 opacity-30"></i>
                        <p class="text-lg mb-2">Nenhuma campanha encontrada.</p>
                        <a href="campaign-create.php" class="inline-block mt-4 px-6 py-3 bg-gradient-silver text-dark font-bold rounded-lg hover:shadow-glow transition-all">
                            <i class="fas fa-plus mr-2"></i>Criar primeira campanha
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($campaigns['total'] > $perPage): ?>
            <div class="mt-6 bg-dark-card border border-[#2a2a2a] rounded-xl px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-sm text-silver-dark">
                    Mostrando <span class="font-bold text-silver"><?php echo (($page - 1) * $perPage) + 1; ?></span> 
                    até <span class="font-bold text-silver"><?php echo min($page * $perPage, $campaigns['total']); ?></span> 
                    de <span class="font-bold text-silver"><?php echo $campaigns['total']; ?></span> campanhas
                </div>
                <div class="flex gap-2 flex-wrap justify-center">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&filter=<?php echo urlencode($filter); ?>"
                       class="px-4 py-2 text-sm bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver text-silver transition-all">
                        Anterior
                    </a>
                    <?php endif; ?>
                    
                    <?php
                    $totalPages = ceil($campaigns['total'] / $perPage);
                    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++):
                    ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&filter=<?php echo urlencode($filter); ?>"
                       class="px-4 py-2 text-sm <?php echo $i === $page ? 'bg-gradient-silver text-dark font-bold' : 'bg-dark-tertiary border border-[#2a2a2a] hover:border-silver text-silver'; ?> rounded-lg transition-all">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&filter=<?php echo urlencode($filter); ?>"
                       class="px-4 py-2 text-sm bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver text-silver transition-all">
                        Próxima
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <script>
        function copyToClipboard(elementId, url) {
            const input = document.getElementById(elementId);
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function() {
                    showToast('Link copiado com sucesso!', 'success');
                    
                    const button = event.target.closest('button');
                    const originalHTML = button.innerHTML;
                    button.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                    button.classList.add('bg-accent-success');
                    
                    setTimeout(() => {
                        button.innerHTML = originalHTML;
                        button.classList.remove('bg-accent-success');
                    }, 2000);
                }).catch(function() {
                    fallbackCopyTextToClipboard(input, url);
                });
            } else {
                fallbackCopyTextToClipboard(input, url);
            }
        }
        
        function fallbackCopyTextToClipboard(input, url) {
            input.select();
            input.setSelectionRange(0, 99999);
            
            try {
                document.execCommand('copy');
                showToast('Link copiado: ' + url, 'success');
            } catch (err) {
                showToast('Erro ao copiar. Use Ctrl+C manualmente.', 'error');
            }
            
            window.getSelection().removeAllRanges();
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-accent-success' : 'bg-accent-danger';
            toast.className = `fixed bottom-4 right-4 ${bgColor} text-dark px-6 py-3 rounded-lg shadow-glow-lg z-50 font-semibold fade-in`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle mr-2"></i>${message}`;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>