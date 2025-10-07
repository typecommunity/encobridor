<?php
/**
 * Cloaker Pro - Edit Campaign v2.2 MULTI-TENANCY
 * Editar campanha com TODOS os filtros + Multi-Tenancy
 */

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
error_log("=== CAMPAIGN EDIT DEBUG ===");
error_log("User ID: " . ($user['id'] ?? 'NULL'));
error_log("User Role: " . ($user['role'] ?? 'NULL'));
error_log("User tenant_id: " . ($user['tenant_id'] ?? 'NULL'));
error_log("Is Super Admin: " . ($isSuperAdmin ? 'YES' : 'NO'));
error_log("Tenant ID: " . ($tenantId ?? 'NULL'));
error_log("==========================");

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

$campaign = new Campaign();
$license = new License();

$error = '';
$success = '';

$campaignId = $_GET['id'] ?? '';
if (empty($campaignId)) {
    header('Location: campaigns.php');
    exit;
}

$camp = $campaign->get($campaignId);
if (!$camp) {
    header('Location: campaigns.php?error=' . urlencode('Campanha não encontrada'));
    exit;
}

// CRITICAL: Verificar se campanha pertence ao tenant
if (!$isSuperAdmin && $camp['tenant_id'] != $tenantId) {
    error_log("PERMISSION DENIED: User {$user['id']} tried to edit campaign {$campaignId} from tenant {$camp['tenant_id']}, but user is from tenant {$tenantId}");
    header('Location: campaigns.php?error=' . urlencode('Você não tem permissão para editar esta campanha'));
    exit;
}

$settings = json_decode($camp['settings'] ?? '{}', true) ?: [];
$rules = json_decode($camp['rules'] ?? '{}', true) ?: [];

// Verificar segurança do slug
$isSlugSecure = $campaign->isSlugSecure($camp['slug']);
$publicUrl = rtrim(BASE_URL, '/') . '/c/' . $camp['slug'];

// Processar ação de regenerar slug
if (isset($_GET['action']) && $_GET['action'] === 'regenerate-slug') {
    if ($campaign->regenerateSlug($campaignId)) {
        $success = 'Link regenerado com sucesso! Não esqueça de atualizar seus anúncios.';
        $camp = $campaign->get($campaignId);
        $isSlugSecure = true;
        $publicUrl = rtrim(BASE_URL, '/') . '/c/' . $camp['slug'];
    } else {
        $error = 'Erro ao regenerar link.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '');
        $mode = $_POST['mode'] ?? 'redirect';
        $safe_page = trim($_POST['safe_page'] ?? '');
        $money_page = trim($_POST['money_page'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        
        if (empty($name)) throw new Exception('Nome da campanha é obrigatório');
        if (empty($safe_page)) throw new Exception('Safe Page é obrigatória');
        if (empty($money_page)) throw new Exception('Money Page é obrigatória');
        
        // Processar regras
        $processedRules = [
            // Geográficos
            'allowed_countries' => $_POST['rules']['allowed_countries'] ?? '',
            'blocked_countries' => $_POST['rules']['blocked_countries'] ?? '',
            'allowed_cities' => $_POST['rules']['allowed_cities'] ?? '',
            'blocked_cities' => $_POST['rules']['blocked_cities'] ?? '',
            'allowed_regions' => $_POST['rules']['allowed_regions'] ?? '',
            'allowed_timezones' => $_POST['rules']['allowed_timezones'] ?? '',
            'allowed_isps' => $_POST['rules']['allowed_isps'] ?? '',
            'blocked_isps' => $_POST['rules']['blocked_isps'] ?? '',
            
            // Dispositivos
            'allowed_devices' => $_POST['rules']['allowed_devices'] ?? [],
            'allowed_os' => $_POST['rules']['allowed_os'] ?? [],
            'allowed_browsers' => $_POST['rules']['allowed_browsers'] ?? [],
            'blocked_browsers' => $_POST['rules']['blocked_browsers'] ?? '',
            
            // Comportamento
            'allowed_languages' => $_POST['rules']['allowed_languages'] ?? '',
            'required_referrer' => $_POST['rules']['required_referrer'] ?? '',
            'blocked_referrers' => $_POST['rules']['blocked_referrers'] ?? '',
            
            // Horários
            'schedule_enabled' => isset($_POST['schedule_enabled']),
            'schedule_start_time' => $_POST['schedule_start_time'] ?? '',
            'schedule_end_time' => $_POST['schedule_end_time'] ?? '',
            'schedule_days' => $_POST['schedule_days'] ?? [],
            'schedule_timezone' => $_POST['schedule_timezone'] ?? 'America/Sao_Paulo'
        ];
        
        // Processar settings
        $processedSettings = [
            // Detecções básicas
            'detect_bots' => isset($_POST['detect_bots']),
            'detect_vpn' => isset($_POST['detect_vpn']),
            'detect_proxy' => isset($_POST['detect_proxy']),
            'detect_tor' => isset($_POST['detect_tor']),
            'detect_datacenter' => isset($_POST['detect_datacenter']),
            'detect_headless' => isset($_POST['detect_headless']),
            
            // Anti-Scraping
            'enable_rate_limit' => isset($_POST['enable_rate_limit']),
            'rate_limit_max' => (int)($_POST['rate_limit_max'] ?? 10),
            'rate_limit_window' => (int)($_POST['rate_limit_window'] ?? 60),
            'enable_scraper_detection' => isset($_POST['enable_scraper_detection']),
            'enable_fingerprinting' => isset($_POST['enable_fingerprinting']),
            'enable_canvas_fingerprint' => isset($_POST['enable_canvas_fingerprint']),
            'enable_webgl_fingerprint' => isset($_POST['enable_webgl_fingerprint']),
            
            // Comportamento
            'track_analytics' => isset($_POST['track_analytics']),
            'enable_redirect_delay' => isset($_POST['enable_redirect_delay']),
            'redirect_delay' => (int)($_POST['redirect_delay'] ?? 0),
            'enable_cloaking_page' => isset($_POST['enable_cloaking_page']),
            
            // A/B Testing
            'enable_ab_testing' => isset($_POST['enable_ab_testing']),
            'ab_test_percentage' => (int)($_POST['ab_test_percentage'] ?? 50),
            
            // Verificações avançadas
            'require_javascript' => isset($_POST['require_javascript']),
            'require_cookies' => isset($_POST['require_cookies']),
            'check_browser_features' => isset($_POST['check_browser_features']),
            
            // Pixels e tracking
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
            'rules' => json_encode($processedRules),
            'settings' => json_encode($processedSettings)
        ];
        
        $result = $campaign->update($campaignId, $data);
        
        if ($result !== false) {
            $success = 'Campanha atualizada com sucesso!';
            $camp = $campaign->get($campaignId);
            $settings = json_decode($camp['settings'] ?? '{}', true) ?: [];
            $rules = json_decode($camp['rules'] ?? '{}', true) ?: [];
        } else {
            throw new Exception('Erro ao atualizar campanha');
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Helper function para verificar arrays
function isChecked($array, $value) {
    return is_array($array) && in_array($value, $array);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Campanha - Cloaker Pro</title>
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
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Editar Campanha';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']) . ' • ' . htmlspecialchars($camp['name']);
        } else {
            $pageSubtitle = htmlspecialchars($camp['name']);
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
                                Editando campanha: <span class="font-semibold text-blue-400"><?= htmlspecialchars($camp['name']) ?></span>
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

            <?php if ($success): ?>
            <div class="bg-accent-success bg-opacity-10 border border-accent-success text-accent-success px-6 py-4 rounded-lg mb-6 fade-in">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <!-- Link da Campanha -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-<?php echo $isSlugSecure ? 'accent-success' : 'accent-warning'; ?> transition-all">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-silver flex items-center gap-2">
                        <i class="fas fa-link text-<?php echo $isSlugSecure ? 'accent-success' : 'accent-warning'; ?>"></i>
                        Link da Campanha
                    </h3>
                    <?php if ($isSlugSecure): ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-accent-success bg-opacity-20 text-accent-success border border-accent-success">
                        <i class="fas fa-shield-alt mr-1"></i>Seguro
                    </span>
                    <?php else: ?>
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-accent-warning bg-opacity-20 text-accent-warning border border-accent-warning">
                        <i class="fas fa-exclamation-triangle mr-1"></i>Inseguro
                    </span>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-3 mb-4">
                    <input 
                        type="text" 
                        value="<?php echo htmlspecialchars($publicUrl); ?>" 
                        id="campaignLink"
                        readonly
                        class="flex-1 px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg text-silver font-mono text-sm cursor-pointer hover:border-silver transition-all"
                        onclick="this.select()"
                    >
                    <button 
                        onclick="copyLink()"
                        class="bg-gradient-silver hover:shadow-glow text-dark font-bold px-6 py-3 rounded-lg transition-all transform hover:scale-105 whitespace-nowrap">
                        <i class="fas fa-copy mr-2"></i>Copiar Link
                    </button>
                </div>

                <?php if (!$isSlugSecure): ?>
                <div class="bg-accent-warning bg-opacity-10 border border-accent-warning rounded-lg p-4">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-exclamation-triangle text-accent-warning text-xl mt-0.5"></i>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-accent-warning mb-2">
                                Link Inseguro Detectado!
                            </p>
                            <p class="text-xs text-silver-dark mb-3">
                                Este link usa um formato antigo que pode ser facilmente adivinhado. 
                                Recomendamos regenerar para um link criptograficamente seguro.
                            </p>
                            <a 
                                href="?id=<?php echo $campaignId; ?>&action=regenerate-slug" 
                                onclick="return confirm('⚠️ ATENÇÃO:\n\n• O link atual PARARÁ de funcionar\n• Um novo link aleatório será gerado\n• Você precisará ATUALIZAR todos os anúncios\n\nDeseja continuar?')"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-accent-warning text-dark font-bold rounded-lg hover:shadow-glow transition-all">
                                <i class="fas fa-sync"></i>
                                Regenerar Link Seguro
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Nome da Campanha *
                            </label>
                            <input type="text" name="name" required value="<?php echo htmlspecialchars($camp['name']); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Slug (Identificador Único)
                            </label>
                            <div class="relative">
                                <input 
                                    type="text" 
                                    readonly 
                                    value="<?php echo htmlspecialchars($camp['slug']); ?>"
                                    class="w-full px-4 py-3 pr-12 bg-dark-tertiary border border-[#2a2a2a] rounded-lg text-silver-dark cursor-not-allowed">
                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                    <i class="fas fa-lock text-silver-dark"></i>
                                </div>
                            </div>
                            <p class="text-xs text-silver-dark mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                O slug não pode ser alterado. Use "Regenerar Link" acima se necessário.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Modo de Cloaking
                            </label>
                            <select name="mode" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                                <option value="redirect" <?php echo $camp['mode'] === 'redirect' ? 'selected' : ''; ?>>Redirect (Redirecionamento 302)</option>
                                <option value="proxy" <?php echo $camp['mode'] === 'proxy' ? 'selected' : ''; ?>>Proxy (Exibir conteúdo no mesmo domínio)</option>
                                <option value="iframe" <?php echo $camp['mode'] === 'iframe' ? 'selected' : ''; ?>>iFrame (Incorporar página)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Status da Campanha
                            </label>
                            <select name="status" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                                <option value="draft" <?php echo $camp['status'] === 'draft' ? 'selected' : ''; ?>>Rascunho (Não ativa)</option>
                                <option value="active" <?php echo $camp['status'] === 'active' ? 'selected' : ''; ?>>Ativa (Funcionando)</option>
                                <option value="paused" <?php echo $camp['status'] === 'paused' ? 'selected' : ''; ?>>Pausada</option>
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
                                <span class="uppercase tracking-wide">Safe Page *</span>
                            </label>
                            <input type="url" name="safe_page" required value="<?php echo htmlspecialchars($camp['safe_page']); ?>"
                                   class="w-full px-4 py-3 bg-dark-card border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-accent-warning text-silver placeholder-silver-dark transition-all"
                                   placeholder="https://exemplo.com/safe-page">
                        </div>

                        <div class="p-5 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                            <label class="block text-sm font-semibold text-silver mb-3 flex items-center gap-2">
                                <i class="fas fa-dollar-sign text-accent-success text-lg"></i>
                                <span class="uppercase tracking-wide">Money Page *</span>
                            </label>
                            <input type="url" name="money_page" required value="<?php echo htmlspecialchars($camp['money_page']); ?>"
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
                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_bots" <?php echo ($settings['detect_bots'] ?? true) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-robot text-accent-danger mr-2"></i>Detectar Bots
                                </span>
                                <span class="text-xs text-silver-dark">Googlebot, Bing, crawlers</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_vpn" <?php echo ($settings['detect_vpn'] ?? true) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-user-secret text-accent-warning mr-2"></i>Detectar VPN
                                </span>
                                <span class="text-xs text-silver-dark">NordVPN, ExpressVPN</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_proxy" <?php echo ($settings['detect_proxy'] ?? true) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-network-wired text-accent-warning mr-2"></i>Detectar Proxy
                                </span>
                                <span class="text-xs text-silver-dark">Servidores proxy</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_tor" <?php echo ($settings['detect_tor'] ?? true) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-user-ninja text-accent-danger mr-2"></i>Detectar TOR
                                </span>
                                <span class="text-xs text-silver-dark">Rede TOR</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_datacenter" <?php echo ($settings['detect_datacenter'] ?? false) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-server text-accent-info mr-2"></i>Datacenter IPs
                                </span>
                                <span class="text-xs text-silver-dark">AWS, Azure, GCP</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="detect_headless" <?php echo ($settings['detect_headless'] ?? false) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-code text-accent-danger mr-2"></i>Headless Browsers
                                </span>
                                <span class="text-xs text-silver-dark">Puppeteer, Selenium</span>
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
                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_rate_limit" <?php echo ($settings['enable_rate_limit'] ?? true) ? 'checked' : ''; ?>
                                   id="rate_limit_toggle"
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-tachometer-alt text-accent-warning mr-2"></i>Rate Limiting
                                </span>
                                <span class="text-xs text-silver-dark">Limitar requisições</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_scraper_detection" <?php echo ($settings['enable_scraper_detection'] ?? true) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-spider text-accent-danger mr-2"></i>Detecção Scrapers
                                </span>
                                <span class="text-xs text-silver-dark">Scrapy, cURL, wget</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_fingerprinting" <?php echo ($settings['enable_fingerprinting'] ?? true) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-fingerprint text-accent-success mr-2"></i>Fingerprinting
                                </span>
                                <span class="text-xs text-silver-dark">Análise JS</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_canvas_fingerprint" <?php echo ($settings['enable_canvas_fingerprint'] ?? false) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-paint-brush text-accent-info mr-2"></i>Canvas
                                </span>
                                <span class="text-xs text-silver-dark">Canvas API</span>
                            </div>
                        </label>

                        <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <input type="checkbox" name="enable_webgl_fingerprint" <?php echo ($settings['enable_webgl_fingerprint'] ?? false) ? 'checked' : ''; ?>
                                   class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                            <div class="flex-1">
                                <span class="text-sm font-bold text-silver block mb-1">
                                    <i class="fas fa-cube text-accent-info mr-2"></i>WebGL
                                </span>
                                <span class="text-xs text-silver-dark">GPU fingerprint</span>
                            </div>
                        </label>
                    </div>

                    <!-- Configurações de Rate Limiting -->
                    <div id="rate-limit-config" style="display: <?php echo ($settings['enable_rate_limit'] ?? true) ? 'block' : 'none'; ?>;" class="mt-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    Máximo de Requisições
                                </label>
                                <input type="number" name="rate_limit_max" value="<?php echo ($settings['rate_limit_max'] ?? 10); ?>" min="1" max="100"
                                       class="w-full px-3 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver text-sm">
                                <p class="text-xs text-silver-dark mt-1">Por janela de tempo</p>
                            </div>
                            
                            <div>
                                <label class="block text-xs font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    Janela de Tempo (segundos)
                                </label>
                                <input type="number" name="rate_limit_window" value="<?php echo ($settings['rate_limit_window'] ?? 60); ?>" min="10" max="3600"
                                       class="w-full px-3 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver text-sm">
                                <p class="text-xs text-silver-dark mt-1">60 = 1 minuto</p>
                            </div>
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
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-flag mr-2"></i>Países Permitidos
                            </label>
                            <input type="text" name="rules[allowed_countries]" value="<?php echo htmlspecialchars($rules['allowed_countries'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="BR,US,PT,ES">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-ban mr-2"></i>Países Bloqueados
                            </label>
                            <input type="text" name="rules[blocked_countries]" value="<?php echo htmlspecialchars($rules['blocked_countries'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="CN,RU,IN">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fas fa-city mr-2"></i>Cidades Permitidas
                                </label>
                                <input type="text" name="rules[allowed_cities]" value="<?php echo htmlspecialchars($rules['allowed_cities'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="São Paulo,Rio de Janeiro">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fas fa-ban mr-2"></i>Cidades Bloqueadas
                                </label>
                                <input type="text" name="rules[blocked_cities]" value="<?php echo htmlspecialchars($rules['blocked_cities'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="Ashburn,Virginia,Beijing">
                                <p class="text-xs text-silver-dark mt-2">
                                    Cidades com datacenters
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fas fa-map-marker-alt mr-2"></i>Regiões
                                </label>
                                <input type="text" name="rules[allowed_regions]" value="<?php echo htmlspecialchars($rules['allowed_regions'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="SP,RJ,MG">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fas fa-clock mr-2"></i>Timezones
                                </label>
                                <input type="text" name="rules[allowed_timezones]" value="<?php echo htmlspecialchars($rules['allowed_timezones'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="America/Sao_Paulo">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fas fa-wifi mr-2"></i>ISPs Permitidos
                                </label>
                                <input type="text" name="rules[allowed_isps]" value="<?php echo htmlspecialchars($rules['allowed_isps'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="Vivo,Claro,Tim">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fas fa-ban mr-2"></i>ISPs Bloqueados
                                </label>
                                <input type="text" name="rules[blocked_isps]" value="<?php echo htmlspecialchars($rules['blocked_isps'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="Hosting,Datacenter">
                            </div>
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
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide">
                                Dispositivos Permitidos
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_devices][]" value="mobile" 
                                           <?php echo isChecked($rules['allowed_devices'] ?? [], 'mobile') ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver"><i class="fas fa-mobile-alt mr-2"></i>Mobile</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_devices][]" value="tablet"
                                           <?php echo isChecked($rules['allowed_devices'] ?? [], 'tablet') ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver"><i class="fas fa-tablet-alt mr-2"></i>Tablet</span>
                                </label>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_devices][]" value="desktop"
                                           <?php echo isChecked($rules['allowed_devices'] ?? [], 'desktop') ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver"><i class="fas fa-desktop mr-2"></i>Desktop</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide">
                                Sistemas Operacionais
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <?php 
                                $os_list = ['windows' => 'Windows', 'macos' => 'macOS', 'android' => 'Android', 'ios' => 'iOS', 'linux' => 'Linux'];
                                foreach ($os_list as $value => $label): 
                                ?>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_os][]" value="<?php echo $value; ?>"
                                           <?php echo isChecked($rules['allowed_os'] ?? [], $value) ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver"><?php echo $label; ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide">
                                Navegadores Permitidos
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                                <?php 
                                $browsers = ['chrome' => 'Chrome', 'firefox' => 'Firefox', 'safari' => 'Safari', 'edge' => 'Edge', 'opera' => 'Opera'];
                                foreach ($browsers as $value => $label): 
                                ?>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="rules[allowed_browsers][]" value="<?php echo $value; ?>"
                                           <?php echo isChecked($rules['allowed_browsers'] ?? [], $value) ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver"><?php echo $label; ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-ban mr-2"></i>Navegadores Bloqueados
                            </label>
                            <input type="text" name="rules[blocked_browsers]" value="<?php echo htmlspecialchars($rules['blocked_browsers'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="UCBrowser,Opera Mini">
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
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-language mr-2"></i>Idiomas Permitidos
                            </label>
                            <input type="text" name="rules[allowed_languages]" value="<?php echo htmlspecialchars($rules['allowed_languages'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="pt,pt-BR,en,es">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-link mr-2"></i>Referrer Obrigatório
                            </label>
                            <input type="text" name="rules[required_referrer]" value="<?php echo htmlspecialchars($rules['required_referrer'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="facebook.com,instagram.com">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-ban mr-2"></i>Referrers Bloqueados
                            </label>
                            <input type="text" name="rules[blocked_referrers]" value="<?php echo htmlspecialchars($rules['blocked_referrers'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                   placeholder="spam-site.com">
                        </div>
                    </div>
                </div>

                <!-- Agendamento -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver transition-all">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-calendar-alt text-accent-warning"></i>
                        Agendamento de Horários
                    </h2>
                    
                    <div class="space-y-6">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="schedule_enabled" <?php echo ($rules['schedule_enabled'] ?? false) ? 'checked' : ''; ?>
                                   class="w-5 h-5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                            <span class="text-sm font-bold text-silver">
                                <i class="fas fa-clock mr-2"></i>Ativar Agendamento
                            </span>
                        </label>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    Horário Início
                                </label>
                                <input type="time" name="schedule_start_time" value="<?php echo htmlspecialchars($rules['schedule_start_time'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    Horário Término
                                </label>
                                <input type="time" name="schedule_end_time" value="<?php echo htmlspecialchars($rules['schedule_end_time'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-3 uppercase tracking-wide">
                                Dias Ativos
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <?php 
                                $days = ['Domingo' => '0', 'Segunda' => '1', 'Terça' => '2', 'Quarta' => '3', 'Quinta' => '4', 'Sexta' => '5', 'Sábado' => '6'];
                                foreach ($days as $label => $value): 
                                ?>
                                <label class="flex items-center cursor-pointer p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                    <input type="checkbox" name="schedule_days[]" value="<?php echo $value; ?>"
                                           <?php echo isChecked($rules['schedule_days'] ?? [], $value) ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-3">
                                    <span class="text-sm text-silver"><?php echo $label; ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
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
                            <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="track_analytics" <?php echo ($settings['track_analytics'] ?? true) ? 'checked' : ''; ?>
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1">
                                        <i class="fas fa-chart-line text-accent-success mr-2"></i>Analytics
                                    </span>
                                    <span class="text-xs text-silver-dark">Rastrear estatísticas</span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="require_javascript" <?php echo ($settings['require_javascript'] ?? false) ? 'checked' : ''; ?>
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1">
                                        <i class="fas fa-code text-accent-info mr-2"></i>Exigir JS
                                    </span>
                                    <span class="text-xs text-silver-dark">Bloquear sem JavaScript</span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="require_cookies" <?php echo ($settings['require_cookies'] ?? false) ? 'checked' : ''; ?>
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1">
                                        <i class="fas fa-cookie text-accent-warning mr-2"></i>Exigir Cookies
                                    </span>
                                    <span class="text-xs text-silver-dark">Bloquear sem cookies</span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="check_browser_features" <?php echo ($settings['check_browser_features'] ?? false) ? 'checked' : ''; ?>
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1">
                                        <i class="fas fa-puzzle-piece text-accent-success mr-2"></i>Verificar Features
                                    </span>
                                    <span class="text-xs text-silver-dark">Canvas, WebGL, Audio</span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="enable_redirect_delay" <?php echo ($settings['enable_redirect_delay'] ?? false) ? 'checked' : ''; ?>
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1">
                                        <i class="fas fa-hourglass-half text-accent-warning mr-2"></i>Delay Redirect
                                    </span>
                                    <span class="text-xs text-silver-dark">Aguardar antes</span>
                                </div>
                            </label>

                            <label class="flex items-start cursor-pointer p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                                <input type="checkbox" name="enable_ab_testing" <?php echo ($settings['enable_ab_testing'] ?? false) ? 'checked' : ''; ?>
                                       class="w-5 h-5 mt-0.5 text-silver bg-dark-card border-[#2a2a2a] rounded focus:ring-silver mr-4">
                                <div class="flex-1">
                                    <span class="text-sm font-bold text-silver block mb-1">
                                        <i class="fas fa-flask text-accent-info mr-2"></i>A/B Testing
                                    </span>
                                    <span class="text-xs text-silver-dark">Dividir tráfego</span>
                                </div>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Delay de Redirecionamento (segundos)
                            </label>
                            <input type="number" name="redirect_delay" min="0" max="10" value="<?php echo ($settings['redirect_delay'] ?? 0); ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                % para Money Page (A/B Testing)
                            </label>
                            <div class="flex items-center gap-4">
                                <input type="range" name="ab_test_percentage" min="0" max="100" value="<?php echo ($settings['ab_test_percentage'] ?? 50); ?>" 
                                       class="flex-1 h-2 bg-dark-tertiary rounded-lg appearance-none cursor-pointer"
                                       oninput="this.nextElementSibling.textContent = this.value + '%'">
                                <span class="text-silver font-bold text-lg w-16 text-right"><?php echo ($settings['ab_test_percentage'] ?? 50); ?>%</span>
                            </div>
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
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fab fa-facebook text-blue-500 mr-2"></i>Facebook Pixel
                                </label>
                                <input type="text" name="facebook_pixel" value="<?php echo htmlspecialchars($settings['facebook_pixel'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="123456789012345">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fab fa-google text-red-500 mr-2"></i>Google Analytics
                                </label>
                                <input type="text" name="google_analytics" value="<?php echo htmlspecialchars($settings['google_analytics'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="G-XXXXXXXXXX">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                    <i class="fab fa-tiktok mr-2"></i>TikTok Pixel
                                </label>
                                <input type="text" name="tiktok_pixel" value="<?php echo htmlspecialchars($settings['tiktok_pixel'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all"
                                       placeholder="CXXXXXXXXXXXXXXXXXX">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                <i class="fas fa-code mr-2"></i>Código Personalizado
                            </label>
                            <textarea name="custom_code" rows="6"
                                      class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark transition-all font-mono text-sm"
                                      placeholder="<!-- Seu código HTML/JavaScript -->"><?php echo htmlspecialchars($settings['custom_code'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Estatísticas -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in">
                    <h2 class="text-xl font-bold text-silver mb-6 flex items-center gap-3">
                        <i class="fas fa-chart-bar text-accent-success"></i>
                        Estatísticas da Campanha
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="text-center p-6 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <i class="fas fa-users text-3xl text-silver mb-3"></i>
                            <p class="text-3xl font-bold text-silver mb-2"><?php echo number_format($camp['visitors_count'] ?? 0); ?></p>
                            <p class="text-sm text-silver-dark uppercase tracking-wide">Total Visitantes</p>
                        </div>
                        <div class="text-center p-6 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <i class="fas fa-circle text-3xl text-<?php 
                                echo $camp['status'] === 'active' ? 'accent-success' : 
                                    ($camp['status'] === 'paused' ? 'accent-warning' : 'silver-dark'); 
                            ?> mb-3"></i>
                            <p class="text-3xl font-bold text-silver mb-2"><?php echo ucfirst($camp['status']); ?></p>
                            <p class="text-sm text-silver-dark uppercase tracking-wide">Status</p>
                        </div>
                        <div class="text-center p-6 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver transition-all">
                            <i class="fas fa-calendar text-3xl text-silver mb-3"></i>
                            <p class="text-3xl font-bold text-silver mb-2"><?php echo date('d/m/Y', strtotime($camp['created_at'])); ?></p>
                            <p class="text-sm text-silver-dark uppercase tracking-wide">Criação</p>
                        </div>
                    </div>
                </div>

                <!-- Botões de Ação -->
                <div class="flex flex-wrap items-center gap-4 pt-4">
                    <button type="submit" 
                            class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-4 px-8 rounded-lg transition-all transform hover:scale-105">
                        <i class="fas fa-save mr-2"></i>Salvar Alterações
                    </button>
                    
                    <a href="campaigns.php" 
                       class="bg-dark-card border border-[#2a2a2a] hover:border-silver text-silver font-semibold py-4 px-8 rounded-lg transition-all">
                        <i class="fas fa-times mr-2"></i>Cancelar
                    </a>
                    
                    <a href="analytics.php?campaign_id=<?php echo $camp['id']; ?>" 
                       class="bg-dark-card border border-[#2a2a2a] hover:border-accent-info text-silver font-semibold py-4 px-8 rounded-lg transition-all">
                        <i class="fas fa-chart-line mr-2"></i>Ver Analytics
                    </a>
                    
                    <a href="campaigns.php?action=delete&id=<?php echo $camp['id']; ?>" 
                       onclick="return confirm('⚠️ ATENÇÃO:\n\nExcluir permanentemente esta campanha?\n\n• Estatísticas serão perdidas\n• Link parará de funcionar\n• Ação IRREVERSÍVEL\n\nContinuar?');"
                       class="ml-auto bg-accent-danger hover:shadow-glow text-white font-bold py-4 px-8 rounded-lg transition-all transform hover:scale-105">
                        <i class="fas fa-trash mr-2"></i>Excluir
                    </a>
                </div>
            </form>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <script>
        // Toggle Rate Limit Config
        document.getElementById('rate_limit_toggle').addEventListener('change', function() {
            document.getElementById('rate-limit-config').style.display = this.checked ? 'block' : 'none';
        });

        function copyLink() {
            const link = document.getElementById('campaignLink').value;
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(() => {
                    showToast('Link copiado com sucesso!', 'success');
                }).catch(() => {
                    fallbackCopy(link);
                });
            } else {
                fallbackCopy(link);
            }
        }
        
        function fallbackCopy(text) {
            const input = document.getElementById('campaignLink');
            input.select();
            input.setSelectionRange(0, 99999);
            
            try {
                document.execCommand('copy');
                showToast('Link copiado!', 'success');
            } catch (err) {
                showToast('Erro ao copiar', 'error');
            }
            
            window.getSelection().removeAllRanges();
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-accent-success' : 'bg-accent-danger';
            const icon = type === 'success' ? 'check' : 'exclamation';
            
            toast.className = `fixed bottom-4 right-4 ${bgColor} text-dark px-6 py-3 rounded-lg shadow-glow-lg z-50 font-bold fade-in`;
            toast.innerHTML = `<i class="fas fa-${icon}-circle mr-2"></i>${message}`;
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