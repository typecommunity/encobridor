<?php
/**
 * Cloaker Pro - Create Campaign (COMPLETE VERSION)
 * Versão 2.2 - Multi-Tenancy Support + Todos os campos originais
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Campaign.php';
require_once '../core/License.php';

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
error_log("=== CAMPAIGN CREATE DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("User Role: " . ($user['role'] ?? 'NULL'));
error_log("User tenant_id: " . ($user['tenant_id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("============================");

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
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    $license = new License();

    $error = '';
    $success = '';

    // Verificar limite de campanhas do tenant
    if (!$isSuperAdmin && $currentTenant) {
        $currentCampaignsCount = $campaign->countCampaigns(['tenant_id' => $tenantId]);
        if ($currentCampaignsCount >= $currentTenant['max_campaigns']) {
            $error = "Limite de campanhas atingido! Seu plano permite {$currentTenant['max_campaigns']} campanhas. Entre em contato para upgrade.";
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
        error_log("POST Data: " . print_r($_POST, true));
        
        try {
            $name = trim($_POST['name'] ?? '');
            $mode = $_POST['mode'] ?? 'redirect';
            $safe_page = trim($_POST['safe_page'] ?? '');
            $money_page = trim($_POST['money_page'] ?? '');
            $status = $_POST['status'] ?? 'draft';
            
            if (empty($name)) {
                throw new Exception('Nome da campanha é obrigatório');
            }
            
            if (empty($safe_page)) {
                throw new Exception('Safe Page é obrigatória');
            }
            
            if (empty($money_page)) {
                throw new Exception('Money Page é obrigatória');
            }
            
            if (!filter_var($safe_page, FILTER_VALIDATE_URL)) {
                throw new Exception('Safe Page URL inválida');
            }
            
            if (!filter_var($money_page, FILTER_VALIDATE_URL)) {
                throw new Exception('Money Page URL inválida');
            }
            
            $rules = [
                'allowed_countries' => $_POST['rules']['allowed_countries'] ?? '',
                'blocked_countries' => $_POST['rules']['blocked_countries'] ?? '',
                'allowed_cities' => $_POST['rules']['allowed_cities'] ?? '',
                'blocked_cities' => $_POST['rules']['blocked_cities'] ?? '',
                'allowed_regions' => $_POST['rules']['allowed_regions'] ?? '',
                'allowed_timezones' => $_POST['rules']['allowed_timezones'] ?? '',
                'allowed_isps' => $_POST['rules']['allowed_isps'] ?? '',
                'blocked_isps' => $_POST['rules']['blocked_isps'] ?? '',
                'allowed_devices' => $_POST['rules']['allowed_devices'] ?? [],
                'allowed_os' => $_POST['rules']['allowed_os'] ?? [],
                'allowed_browsers' => $_POST['rules']['allowed_browsers'] ?? [],
                'blocked_browsers' => $_POST['rules']['blocked_browsers'] ?? [],
                'allowed_languages' => $_POST['rules']['allowed_languages'] ?? '',
                'required_referrer' => $_POST['rules']['required_referrer'] ?? '',
                'blocked_referrers' => $_POST['rules']['blocked_referrers'] ?? '',
                'schedule_enabled' => isset($_POST['schedule_enabled']),
                'schedule_start_time' => $_POST['schedule_start_time'] ?? '',
                'schedule_end_time' => $_POST['schedule_end_time'] ?? '',
                'schedule_days' => $_POST['schedule_days'] ?? [],
                'schedule_timezone' => $_POST['schedule_timezone'] ?? 'America/Sao_Paulo'
            ];
            
            $settings = [
                'detect_bots' => isset($_POST['detect_bots']),
                'detect_vpn' => isset($_POST['detect_vpn']),
                'detect_proxy' => isset($_POST['detect_proxy']),
                'detect_tor' => isset($_POST['detect_tor']),
                'detect_datacenter' => isset($_POST['detect_datacenter']),
                'detect_headless' => isset($_POST['detect_headless']),
                'enable_rate_limit' => isset($_POST['enable_rate_limit']),
                'enable_scraper_detection' => isset($_POST['enable_scraper_detection']),
                'enable_fingerprinting' => isset($_POST['enable_fingerprinting']),
                'enable_canvas_fingerprint' => isset($_POST['enable_canvas_fingerprint']),
                'enable_webgl_fingerprint' => isset($_POST['enable_webgl_fingerprint']),
                'track_analytics' => isset($_POST['track_analytics']),
                'enable_redirect_delay' => isset($_POST['enable_redirect_delay']),
                'redirect_delay' => (int)($_POST['redirect_delay'] ?? 0),
                'enable_cloaking_page' => isset($_POST['enable_cloaking_page']),
                'enable_ab_testing' => isset($_POST['enable_ab_testing']),
                'ab_test_percentage' => (int)($_POST['ab_test_percentage'] ?? 50),
                'require_javascript' => isset($_POST['require_javascript']),
                'require_cookies' => isset($_POST['require_cookies']),
                'check_browser_features' => isset($_POST['check_browser_features']),
                'facebook_pixel' => trim($_POST['facebook_pixel'] ?? ''),
                'google_analytics' => trim($_POST['google_analytics'] ?? ''),
                'tiktok_pixel' => trim($_POST['tiktok_pixel'] ?? ''),
                'custom_code' => trim($_POST['custom_code'] ?? '')
            ];
            
            $data = [
                'name' => $name,
                'mode' => $mode,
                'safe_page' => $safe_page,
                'money_page' => $money_page,
                'status' => $status,
                'rules' => json_encode($rules),
                'settings' => json_encode($settings)
            ];
            
            // CRITICAL: Adicionar tenant_id
            if (!$isSuperAdmin && $tenantId) {
                $data['tenant_id'] = $tenantId;
                error_log("Adding tenant_id to campaign: " . $tenantId);
            }
            
            error_log("Data to insert: " . print_r($data, true));
            
            $campaignId = $campaign->create($data);
            
            error_log("Campaign ID returned: " . var_export($campaignId, true));
            
            if ($campaignId) {
                $success = 'Campanha criada com sucesso!';
                header('Location: campaigns.php?message=' . urlencode($success));
                exit;
            } else {
                throw new Exception('Falha ao inserir no banco de dados - Verifique os logs');
            }
            
        } catch (Exception $e) {
            error_log("Campaign creation error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $error = $e->getMessage() . " (Linha: " . $e->getLine() . ")";
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
    <title>Nova Campanha - Cloaker Pro</title>
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
        
        .animate-shine::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent); animation: shine 3s infinite; }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
        
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
        
        /* Tooltips */
        .tooltip { position: relative; display: inline-block; cursor: help; }
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 280px;
            background-color: #1a1a1a;
            color: #c0c0c0;
            text-align: left;
            border-radius: 8px;
            border: 1px solid #c0c0c0;
            padding: 12px;
            position: absolute;
            z-index: 1000;
            bottom: 125%;
            left: 50%;
            margin-left: -140px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
            line-height: 1.5;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        }
        .tooltip .tooltiptext::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #c0c0c0 transparent transparent transparent;
        }
        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Nova Campanha';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']);
        } else {
            $pageSubtitle = 'Configure sua campanha com todos os filtros disponíveis';
        }
        $pageAction = '<a href="campaigns.php" class="text-silver-dark hover:text-accent-danger transition-colors">
                        <i class="fas fa-times text-2xl"></i>
                      </a>';
        require_once 'header.php'; 
        ?>

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
                                Campanhas: <span class="font-semibold text-blue-400">
                                    <?= $campaign->countCampaigns(['tenant_id' => $tenantId]) ?>/<?= $currentTenant['max_campaigns'] ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="bg-accent-danger bg-opacity-10 border border-accent-danger text-accent-danger px-6 py-4 rounded-lg mb-6 fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="bg-dark-card border border-accent-info border-opacity-30 rounded-xl p-6 mb-6 fade-in hover:border-accent-info hover:shadow-glow transition-all">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 bg-accent-info bg-opacity-10 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-shield-alt text-accent-info text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-silver mb-2">Link Seguro e Privado</h3>
                        <p class="text-sm text-silver-dark leading-relaxed">
                            Sua campanha receberá um <span class="text-accent-success font-semibold">link único e aleatório</span> 
                            impossível de adivinhar. Isso protege contra bots, moderadores e concorrentes.
                        </p>
                    </div>
                </div>
            </div>

            <form method="POST" class="max-w-4xl">
                <!-- Informações Básicas -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-info-circle text-accent-info"></i>
                        Informações Básicas
                    </h2>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                Nome da Campanha *
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        <strong>Nome identificador da campanha</strong><br><br>
                                        Use um nome descritivo para facilitar a gestão de múltiplas campanhas.<br><br>
                                        <em>Exemplo: "Black Friday 2024" ou "Produto X - Facebook"</em>
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="name" required
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="Ex: Campanha Black Friday 2024">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                Modo de Cloaking
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        <strong>Como o visitante será redirecionado:</strong><br><br>
                                        • <strong>Redirect:</strong> Redireciona navegador para outra URL (mais rápido)<br>
                                        • <strong>Proxy:</strong> Exibe conteúdo mantendo URL original<br>
                                        • <strong>iFrame:</strong> Incorpora página dentro de frame
                                    </span>
                                </div>
                            </label>
                            <select name="mode" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                                <option value="redirect">Redirect (Redirecionamento 302)</option>
                                <option value="proxy">Proxy (Exibir conteúdo no mesmo domínio)</option>
                                <option value="iframe">iFrame (Incorporar página)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Status Inicial
                            </label>
                            <select name="status" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                                <option value="draft">Rascunho (Não ativa)</option>
                                <option value="active">Ativa (Funcionando)</option>
                                <option value="paused">Pausada</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- URLs -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-link text-accent-success"></i>
                        Páginas de Destino
                    </h2>
                    
                    <div class="space-y-6">
                        <div class="p-5 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                            <label class="block text-sm font-semibold text-silver mb-3 flex items-center gap-2">
                                <i class="fas fa-shield-alt text-accent-warning text-lg"></i>
                                <span class="uppercase tracking-wide">Safe Page (Página Segura) *</span>
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        <strong>Página exibida para visitantes não qualificados</strong><br><br>
                                        Bots, moderadores, VPNs e visitantes que não passarem nos filtros verão esta página.<br><br>
                                        Use uma landing page segura e limpa (ex: página institucional, blog).
                                    </span>
                                </div>
                            </label>
                            <input type="url" name="safe_page" required
                                   class="w-full px-4 py-3 bg-dark-card border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-accent-warning text-silver placeholder-silver-dark transition-all"
                                   placeholder="https://exemplo.com/safe-page">
                        </div>

                        <div class="p-5 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                            <label class="block text-sm font-semibold text-silver mb-3 flex items-center gap-2">
                                <i class="fas fa-dollar-sign text-accent-success text-lg"></i>
                                <span class="uppercase tracking-wide">Money Page (Página de Conversão) *</span>
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        <strong>Página exibida para visitantes qualificados</strong><br><br>
                                        Apenas visitantes reais que passarem em TODOS os filtros verão esta página.<br><br>
                                        É sua página de conversão/oferta principal.
                                    </span>
                                </div>
                            </label>
                            <input type="url" name="money_page" required
                                   class="w-full px-4 py-3 bg-dark-card border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-accent-success text-silver placeholder-silver-dark transition-all"
                                   placeholder="https://exemplo.com/money-page">
                        </div>
                    </div>
                </div>

                <!-- Detecções de Segurança -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-shield-alt text-accent-danger"></i>
                        Detecções de Segurança
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_bots" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-robot text-accent-danger mr-2"></i>
                                    Detectar Bots
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Bloqueia Googlebot, Bingbot, Yandex, crawlers e scrapers.<br><br>
                                            Detecta mais de 20 tipos de bots conhecidos automaticamente.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Googlebot, Bing, crawlers, etc.
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_vpn" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-user-secret text-accent-warning mr-2"></i>
                                    Detectar VPN
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Identifica usuários conectados via VPN.<br><br>
                                            Detecta NordVPN, ExpressVPN, ProtonVPN e outros serviços VPN comerciais.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    NordVPN, ExpressVPN, etc.
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_proxy" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-network-wired text-accent-warning mr-2"></i>
                                    Detectar Proxy
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Detecta servidores proxy públicos.<br><br>
                                            Verifica headers HTTP suspeitos como Via, X-Forwarded-For, Proxy-Connection.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Servidores proxy públicos
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_tor" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-user-ninja text-accent-danger mr-2"></i>
                                    Detectar TOR
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Bloqueia acesso via rede TOR/Onion Router.<br><br>
                                            Usa lista atualizada de exit nodes TOR conhecidos.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Rede TOR / Onion Router
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_datacenter"
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-server text-accent-info mr-2"></i>
                                    Detectar Datacenter IPs
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Bloqueia IPs de datacenters e hospedagem.<br><br>
                                            Detecta AWS, Google Cloud, Azure, DigitalOcean, Vultr, Linode e outros.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    AWS, Google Cloud, Azure, etc.
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_headless"
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-code text-accent-danger mr-2"></i>
                                    Detectar Headless Browsers
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Detecta navegadores automáticos sem interface.<br><br>
                                            Identifica Puppeteer, Selenium, PhantomJS, Playwright e Chrome Headless.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Puppeteer, Selenium, PhantomJS
                                </span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Proteção Anti-Scraping -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-shield-virus text-accent-warning"></i>
                        Proteção Anti-Scraping
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_rate_limit" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-tachometer-alt text-accent-warning mr-2"></i>
                                    Rate Limiting
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Limita número de requisições por IP em período de tempo.<br><br>
                                            Previne scraping automatizado e ataques de força bruta.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Limitar requisições por IP
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_scraper_detection" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-spider text-accent-danger mr-2"></i>
                                    Detecção de Scrapers
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Detecta ferramentas de scraping conhecidas.<br><br>
                                            Identifica Scrapy, cURL, wget, HTTrack, Beautiful Soup e outros.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Scrapy, cURL, wget, HTTrack
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_fingerprinting" checked
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-fingerprint text-accent-success mr-2"></i>
                                    Browser Fingerprinting
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Análise comportamental via JavaScript.<br><br>
                                            Coleta características únicas do navegador, hardware e comportamento do usuário.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Análise comportamental JS
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_canvas_fingerprint"
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-paint-brush text-accent-info mr-2"></i>
                                    Canvas Fingerprinting
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Identificação única por Canvas API.<br><br>
                                            Cada navegador/GPU renderiza Canvas de forma ligeiramente diferente.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Identificação por Canvas API
                                </span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_webgl_fingerprint"
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                    <i class="fas fa-cube text-accent-info mr-2"></i>
                                    WebGL Fingerprinting
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                        <span class="tooltiptext">
                                            Identificação por GPU e drivers gráficos.<br><br>
                                            Cada placa de vídeo tem características únicas detectáveis via WebGL.
                                        </span>
                                    </div>
                                </span>
                                <span class="text-xs text-silver-dark">
                                    Identificação por GPU
                                </span>
                            </div>
                        </label>

                        <div class="md:col-span-2 p-4 bg-accent-info bg-opacity-5 border border-accent-info border-opacity-30 rounded-lg">
                            <p class="text-xs text-silver-dark flex items-start gap-2">
                                <i class="fas fa-info-circle text-accent-info mt-0.5"></i>
                                <span>
                                    Configurações globais são gerenciadas no 
                                    <a href="anti-scraping.php" class="text-accent-info hover:underline font-semibold">Dashboard Anti-Scraping</a>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filtros Geográficos -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-globe-americas text-accent-info"></i>
                        Filtros Geográficos
                    </h2>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-flag mr-2"></i>Países Permitidos
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Visitantes APENAS desses países verão Money Page.<br><br>
                                        Use códigos ISO separados por vírgula: BR,US,PT,ES<br><br>
                                        Deixe vazio para permitir todos os países.
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="rules[allowed_countries]"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="BR,US,PT,ES (códigos ISO separados por vírgula)">
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Deixe vazio para permitir todos. Outros países verão Safe Page
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-ban mr-2"></i>Países Bloqueados
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Visitantes desses países SEMPRE verão Safe Page.<br><br>
                                        Útil para bloquear países com alto índice de fraude ou moderação.<br><br>
                                        Exemplos comuns: CN, RU, IN, VN
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="rules[blocked_countries]"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="CN,RU,IN (códigos ISO separados por vírgula)">
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Países específicos que sempre verão Safe Page
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fas fa-city mr-2"></i>Cidades Permitidas
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Apenas visitantes dessas cidades verão Money Page.<br><br>
                                            Útil para campanhas regionais ou locais.
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="rules[allowed_cities]"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="São Paulo,Rio de Janeiro,Lisboa">
                                <p class="text-xs text-silver-dark mt-2">
                                    Separadas por vírgula
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fas fa-ban mr-2"></i>Cidades Bloqueadas
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Bloqueia cidades específicas conhecidas por datacenters.<br><br>
                                            Exemplos: Ashburn (AWS), Virginia, Fremont, Beijing
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="rules[blocked_cities]"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="Ashburn,Virginia,Beijing">
                                <p class="text-xs text-silver-dark mt-2">
                                    Cidades conhecidas por datacenters/bots
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fas fa-map-marker-alt mr-2"></i>Regiões/Estados
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Filtre por estados ou regiões administrativas.<br><br>
                                            Exemplo: SP,RJ,MG,PR (estados brasileiros)
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="rules[allowed_regions]"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="SP,RJ,MG,PR">
                                <p class="text-xs text-silver-dark mt-2">
                                    Códigos de estado
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fas fa-clock mr-2"></i>Timezones Permitidos
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Filtre por fuso horário do navegador.<br><br>
                                            Detecta timezone configurado no sistema do visitante.<br><br>
                                            Exemplo: America/Sao_Paulo, America/New_York
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="rules[allowed_timezones]"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="America/Sao_Paulo,America/New_York">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fas fa-wifi mr-2"></i>ISPs Permitidos
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Apenas visitantes desses provedores verão Money Page.<br><br>
                                            Exemplo: Vivo,Claro,Tim,NET
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="rules[allowed_isps]"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="Vivo,Claro,Tim,NET">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fas fa-ban mr-2"></i>ISPs Bloqueados
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Bloqueie provedores de hospedagem e datacenters.<br><br>
                                            Palavras-chave que aparecem no nome do ISP.<br><br>
                                            Exemplo: Hosting, Datacenter, Cloud, Amazon
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="rules[blocked_isps]"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="Hosting,Datacenter,Cloud">
                            </div>
                        </div>

                        <div>
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1 text-accent-info"></i>
                                Útil para bloquear provedores de hospedagem
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filtros de Dispositivo -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-mobile-alt text-accent-success"></i>
                        Filtros de Dispositivo
                    </h2>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-devices mr-2"></i>Tipos de Dispositivo Permitidos
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Selecione os tipos de dispositivo permitidos.<br><br>
                                        Deixe TODOS desmarcados para permitir qualquer dispositivo.<br><br>
                                        Útil para campanhas mobile-only ou desktop-only.
                                    </span>
                                </div>
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_devices][]" value="mobile"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fas fa-mobile-alt mr-2"></i>Mobile
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_devices][]" value="tablet"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fas fa-tablet-alt mr-2"></i>Tablet
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_devices][]" value="desktop"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fas fa-desktop mr-2"></i>Desktop
                                    </span>
                                </label>
                            </div>
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Deixe desmarcado para permitir todos os dispositivos
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-laptop-code mr-2"></i>Sistemas Operacionais Permitidos
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Filtre por sistema operacional.<br><br>
                                        Detecta Windows, macOS, Android, iOS, Linux automaticamente.
                                    </span>
                                </div>
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_os][]" value="windows"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-windows mr-2"></i>Windows
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_os][]" value="macos"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-apple mr-2"></i>macOS
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_os][]" value="android"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-android mr-2"></i>Android
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_os][]" value="ios"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-apple mr-2"></i>iOS
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_os][]" value="linux"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-linux mr-2"></i>Linux
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-chrome mr-2"></i>Navegadores Permitidos
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Apenas visitantes usando esses navegadores verão Money Page.<br><br>
                                        Detecta automaticamente: Chrome, Firefox, Safari, Edge, Opera.
                                    </span>
                                </div>
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_browsers][]" value="chrome"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-chrome mr-2"></i>Chrome
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_browsers][]" value="firefox"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-firefox mr-2"></i>Firefox
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_browsers][]" value="safari"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-safari mr-2"></i>Safari
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_browsers][]" value="edge"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-edge mr-2"></i>Edge
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_browsers][]" value="opera"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">
                                        <i class="fab fa-opera mr-2"></i>Opera
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-ban mr-2"></i>Navegadores Bloqueados
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Bloqueie navegadores específicos por nome.<br><br>
                                        Exemplo: UCBrowser, Opera Mini (navegadores móveis problemáticos)
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="rules[blocked_browsers]"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="UCBrowser,Opera Mini">
                            <p class="text-xs text-silver-dark mt-2">
                                Separados por vírgula
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filtros de Comportamento -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-user-check text-accent-info"></i>
                        Filtros de Comportamento
                    </h2>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-language mr-2"></i>Idiomas Permitidos
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Filtre por idioma do navegador (Accept-Language).<br><br>
                                        Exemplo: pt, pt-BR, en, es, fr<br><br>
                                        Detecta automaticamente o idioma configurado no navegador.
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="rules[allowed_languages]"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="pt,pt-BR,en,es (códigos de idioma separados por vírgula)">
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Baseado no Accept-Language do navegador
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-link mr-2"></i>Referrer Obrigatório
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Exige que visitante venha de sites específicos.<br><br>
                                        Exemplo: facebook.com, instagram.com, google.com<br><br>
                                        Visitantes sem referrer ou de outros sites verão Safe Page.
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="rules[required_referrer]"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="facebook.com,instagram.com,google.com">
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Visitantes sem referrer ou de outros sites verão Safe Page
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-ban mr-2"></i>Referrers Bloqueados
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Bloqueie visitantes vindos de sites específicos.<br><br>
                                        Útil para bloquear spam, concorrentes ou sites maliciosos.
                                    </span>
                                </div>
                            </label>
                            <input type="text" name="rules[blocked_referrers]"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="spam-site.com,malicious.com">
                        </div>
                    </div>
                </div>

                <!-- Agendamento de Horários -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-calendar-alt text-accent-warning"></i>
                        Agendamento de Horários
                    </h2>
                    
                    <div class="space-y-6">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="schedule_enabled"
                                   class="w-5 h-5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                            <span class="text-sm font-bold text-silver flex items-center gap-2">
                                <i class="fas fa-clock mr-2"></i>Ativar Agendamento
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Exiba Money Page apenas em horários específicos.<br><br>
                                        Fora do horário, TODOS verão Safe Page.<br><br>
                                        Útil para campanhas com horário comercial ou eventos programados.
                                    </span>
                                </div>
                            </span>
                        </label>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    Horário de Início
                                </label>
                                <input type="time" name="schedule_start_time"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    Horário de Término
                                </label>
                                <input type="time" name="schedule_end_time"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide">
                                Dias da Semana Ativos
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="0"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Domingo</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="1"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Segunda</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="2"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Terça</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="3"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Quarta</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="4"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Quinta</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="5"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Sexta</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="6"
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver">Sábado</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Timezone
                            </label>
                            <select name="schedule_timezone" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                                <option value="America/Sao_Paulo">America/Sao_Paulo (BRT/BRST)</option>
                                <option value="America/New_York">America/New_York (EST/EDT)</option>
                                <option value="Europe/London">Europe/London (GMT/BST)</option>
                                <option value="Europe/Lisbon">Europe/Lisbon (WET/WEST)</option>
                                <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
                            </select>
                        </div>

                        <div class="p-4 bg-accent-warning bg-opacity-5 border border-accent-warning border-opacity-30 rounded-lg">
                            <p class="text-xs text-silver-dark flex items-start gap-2">
                                <i class="fas fa-info-circle text-accent-warning mt-0.5"></i>
                                <span>
                                    Fora do horário agendado, todos os visitantes verão a Safe Page
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Configurações Avançadas -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-cogs text-accent-success"></i>
                        Configurações Avançadas
                    </h2>
                    
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="track_analytics" checked
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                        <i class="fas fa-chart-line text-accent-success mr-2"></i>
                                        Rastrear Analytics
                                        <div class="tooltip">
                                            <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                            <span class="tooltiptext">
                                                Registra estatísticas detalhadas de todos os visitantes.<br><br>
                                                País, cidade, dispositivo, decisões tomadas, etc.
                                            </span>
                                        </div>
                                    </span>
                                    <span class="text-xs text-silver-dark">
                                        Registrar estatísticas de visitantes
                                    </span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="enable_redirect_delay"
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                        <i class="fas fa-hourglass-half text-accent-warning mr-2"></i>
                                        Delay de Redirecionamento
                                        <div class="tooltip">
                                            <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                            <span class="tooltiptext">
                                                Aguarda alguns segundos antes de redirecionar.<br><br>
                                                Pode ajudar a parecer mais "humano" para alguns sistemas.
                                            </span>
                                        </div>
                                    </span>
                                    <span class="text-xs text-silver-dark">
                                        Aguardar antes de redirecionar
                                    </span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="require_javascript"
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                        <i class="fas fa-code text-accent-info mr-2"></i>
                                        Exigir JavaScript
                                        <div class="tooltip">
                                            <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                            <span class="tooltiptext">
                                                Bloqueia visitantes sem JavaScript ativo.<br><br>
                                                Útil contra bots simples que não executam JS.
                                            </span>
                                        </div>
                                    </span>
                                    <span class="text-xs text-silver-dark">
                                        Bloquear sem JS ativo
                                    </span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="require_cookies"
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                        <i class="fas fa-cookie text-accent-warning mr-2"></i>
                                        Exigir Cookies
                                        <div class="tooltip">
                                            <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                            <span class="tooltiptext">
                                                Bloqueia visitantes sem cookies habilitados.<br><br>
                                                Necessário para rastreamento de sessão.
                                            </span>
                                        </div>
                                    </span>
                                    <span class="text-xs text-silver-dark">
                                        Bloquear sem cookies
                                    </span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="check_browser_features"
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                        <i class="fas fa-puzzle-piece text-accent-success mr-2"></i>
                                        Verificar Features do Browser
                                        <div class="tooltip">
                                            <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                            <span class="tooltiptext">
                                                Verifica se navegador suporta Canvas, WebGL e Audio API.<br><br>
                                                Bots headless geralmente falham nesses testes.
                                            </span>
                                        </div>
                                    </span>
                                    <span class="text-xs text-silver-dark">
                                        Canvas, WebGL, Audio API
                                    </span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer group p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="enable_cloaking_page"
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver focus:ring-2 mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1 flex items-center gap-2">
                                        <i class="fas fa-mask text-accent-info mr-2"></i>
                                        Página de Cloaking Intermediária
                                        <div class="tooltip">
                                            <i class="fas fa-question-circle text-accent-info text-xs"></i>
                                            <span class="tooltiptext">
                                                Exibe página de carregamento antes do redirect.<br><br>
                                                Pode ajudar a contornar algumas detecções.
                                            </span>
                                        </div>
                                    </span>
                                    <span class="text-xs text-silver-dark">
                                        Exibir página de carregamento
                                    </span>
                                </div>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-clock mr-2"></i>Delay de Redirecionamento (segundos)
                            </label>
                            <input type="number" name="redirect_delay" min="0" max="10" value="0"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            <p class="text-xs text-silver-dark mt-2">
                                Tempo de espera antes de redirecionar (0-10 segundos)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- A/B Testing -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-flask text-accent-info"></i>
                        A/B Testing
                    </h2>
                    
                    <div class="space-y-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="enable_ab_testing"
                                   class="w-5 h-5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                            <span class="text-sm font-bold text-silver flex items-center gap-2">
                                <i class="fas fa-vial mr-2"></i>Ativar A/B Testing
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Divide visitantes qualificados entre Money e Safe Page.<br><br>
                                        Útil para testar se filtros estão muito rígidos ou flexíveis demais.
                                    </span>
                                </div>
                            </span>
                        </label>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Porcentagem para Money Page
                            </label>
                            <div class="flex items-center gap-4">
                                <input type="range" name="ab_test_percentage" min="0" max="100" value="50" 
                                       class="flex-1 h-2 bg-dark-tertiary rounded-lg appearance-none cursor-pointer"
                                       oninput="this.nextElementSibling.textContent = this.value + '%'">
                                <span class="text-silver font-bold text-lg w-16 text-right">50%</span>
                            </div>
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Visitantes qualificados serão divididos aleatoriamente
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Pixels e Tracking -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-chart-pie text-accent-success"></i>
                        Pixels e Código de Tracking
                    </h2>
                    
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fab fa-facebook text-blue-500 mr-2"></i>Facebook Pixel ID
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Seu ID do Facebook Pixel (15 dígitos).<br><br>
                                            Será injetado APENAS na Money Page para rastrear conversões.
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="facebook_pixel"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="123456789012345">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fab fa-google text-red-500 mr-2"></i>Google Analytics ID
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Seu ID do Google Analytics.<br><br>
                                            Formato: UA-XXXXXXXXX-X ou G-XXXXXXXXXX
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="google_analytics"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="UA-XXXXXXXXX-X ou G-XXXXXXXXXX">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                    <i class="fab fa-tiktok mr-2"></i>TikTok Pixel ID
                                    <div class="tooltip">
                                        <i class="fas fa-question-circle text-accent-info"></i>
                                        <span class="tooltiptext">
                                            Seu ID do TikTok Pixel.<br><br>
                                            Necessário para rastrear conversões de anúncios TikTok.
                                        </span>
                                    </div>
                                </label>
                                <input type="text" name="tiktok_pixel"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="CXXXXXXXXXXXXXXXXXX">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-code mr-2"></i>Código Personalizado (HTML/JS)
                                <div class="tooltip">
                                    <i class="fas fa-question-circle text-accent-info"></i>
                                    <span class="tooltiptext">
                                        Código HTML/JavaScript customizado.<br><br>
                                        Será injetado na Money Page antes do fechamento do &lt;/body&gt;.<br><br>
                                        Use para pixels adicionais, scripts de tracking, etc.
                                    </span>
                                </div>
                            </label>
                            <textarea name="custom_code" rows="6"
                                      class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all font-mono text-sm"
                                      placeholder="<!-- Seu código HTML/JavaScript aqui -->"></textarea>
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Será injetado no Money Page antes do &lt;/body&gt;
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Botões de Ação -->
                <div class="flex flex-wrap items-center gap-4 pt-4">
                    <button type="submit" 
                            class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-4 px-8 rounded-lg transition-all transform hover:scale-105"
                            <?php if (!empty($error) && strpos($error, 'Limite de campanhas') !== false): ?>disabled<?php endif; ?>>
                        <i class="fas fa-save mr-2"></i>Criar Campanha
                    </button>
                    
                    <a href="campaigns.php" 
                       class="bg-dark-card border border-[#2a2a2a] hover:border-accent-danger text-silver-dark hover:text-accent-danger font-semibold py-4 px-8 rounded-lg transition-all">
                        <i class="fas fa-times mr-2"></i>Cancelar
                    </a>
                </div>
            </form>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>
</body>
</html>