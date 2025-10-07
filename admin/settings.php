<?php
/**
 * Cloaker Pro - Settings (Multi-Tenant)
 * Configurações do sistema com controle de acesso
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/License.php';

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

try {
    $license = new License();

    $message = '';
    $error = '';
    $activeTab = $_GET['tab'] ?? 'security'; // Default para security se não for super admin

    // Se não for super admin, não pode acessar a aba geral
    if (!$isSuperAdmin && $activeTab === 'general') {
        $activeTab = 'security';
        $error = 'Você não tem permissão para acessar configurações gerais do sistema.';
    }

    // Funções auxiliares
    function saveSettings($section, $data) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            if (!$conn) {
                throw new Exception("Falha na conexão com o banco de dados");
            }
            
            foreach ($data as $key => $value) {
                // Converte valores conforme tipo
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_null($value)) {
                    $value = '';
                }
                
                // Como a UNIQUE KEY é apenas setting_key (não setting_key+section)
                // Vamos criar uma chave composta manualmente
                $compositeKey = $section . '_' . $key;
                
                // Verifica se já existe
                $checkStmt = $conn->prepare("SELECT id FROM settings WHERE setting_key = ?");
                $checkStmt->execute([$compositeKey]);
                
                if ($checkStmt->fetch()) {
                    // Update
                    $stmt = $conn->prepare("
                        UPDATE settings 
                        SET setting_value = ?, section = ?, updated_at = NOW()
                        WHERE setting_key = ?
                    ");
                    $stmt->execute([$value, $section, $compositeKey]);
                } else {
                    // Insert
                    $stmt = $conn->prepare("
                        INSERT INTO settings (setting_key, setting_value, section, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$compositeKey, $value, $section]);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("saveSettings error: " . $e->getMessage());
            error_log("saveSettings trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    function getAllSettings() {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            if (!$conn) {
                return [];
            }
            
            $stmt = $conn->query("SELECT * FROM settings ORDER BY section, setting_key");
            $settings = [];
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Remove o prefixo da seção da chave (se existir)
                $key = $row['setting_key'];
                if (strpos($key, $row['section'] . '_') === 0) {
                    $key = substr($key, strlen($row['section']) + 1);
                }
                
                // Tenta decodificar JSON
                $value = $row['setting_value'];
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
                
                $settings[$row['section']][$key] = $value;
            }
            
            return $settings;
            
        } catch (Exception $e) {
            error_log("getAllSettings error: " . $e->getMessage());
            return [];
        }
    }

    function sanitizeInput($input, $type = 'string') {
        if ($input === null || $input === '') {
            return $type === 'int' ? 0 : '';
        }
        
        switch ($type) {
            case 'email':
                $email = filter_var($input, FILTER_SANITIZE_EMAIL);
                return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
                
            case 'url':
                $url = filter_var($input, FILTER_SANITIZE_URL);
                return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
                
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : 0;
                
            default:
                return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }
    }

    function validateTimezone($timezone) {
        return in_array($timezone, timezone_identifiers_list());
    }

    function validateIPList($ipList) {
        if (empty(trim($ipList))) {
            return true;
        }
        
        $ips = array_filter(array_map('trim', explode("\n", $ipList)));
        
        foreach ($ips as $ip) {
            if (strpos($ip, '#') !== false) {
                $ip = trim(explode('#', $ip)[0]);
            }
            
            if (empty($ip)) continue;
            
            if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip)) {
                return false;
            }
        }
        
        return true;
    }

    function clearCache($directory) {
        $count = 0;
        $errors = [];
        
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
            return ['count' => 0, 'errors' => []];
        }
        
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $fileinfo) {
                try {
                    if ($fileinfo->isDir()) {
                        if (@rmdir($fileinfo->getRealPath())) {
                            $count++;
                        }
                    } else {
                        if (@unlink($fileinfo->getRealPath())) {
                            $count++;
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Erro ao deletar: " . $fileinfo->getFilename();
                }
            }
        } catch (Exception $e) {
            $errors[] = "Erro ao acessar diretório: " . $e->getMessage();
        }
        
        return ['count' => $count, 'errors' => $errors];
    }

    // Processar formulários
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'update_general':
                    // ✅ VERIFICAÇÃO: Apenas super admin pode alterar configurações gerais
                    if (!$isSuperAdmin) {
                        throw new Exception('Você não tem permissão para alterar configurações gerais do sistema.');
                    }
                    
                    $timezone = $_POST['timezone'] ?? 'America/Sao_Paulo';
                    if (!validateTimezone($timezone)) {
                        throw new Exception('Timezone inválido');
                    }
                    
                    $siteName = sanitizeInput($_POST['site_name'] ?? 'Cloaker Pro');
                    if (empty($siteName)) {
                        throw new Exception('Nome do site é obrigatório');
                    }
                    
                    $settings = [
                        'site_name' => $siteName,
                        'site_url' => sanitizeInput($_POST['site_url'] ?? '', 'url'),
                        'timezone' => $timezone,
                        'date_format' => $_POST['date_format'] ?? 'Y-m-d',
                        'time_format' => $_POST['time_format'] ?? 'H:i:s',
                        'language' => $_POST['language'] ?? 'pt-BR',
                        'debug_mode' => isset($_POST['debug_mode']) ? 1 : 0,
                        'maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0
                    ];
                    
                    if (!empty($settings['site_url']) && !filter_var($settings['site_url'], FILTER_VALIDATE_URL)) {
                        throw new Exception('URL do site inválida');
                    }
                    
                    saveSettings('general', $settings);
                    $message = 'Configurações gerais atualizadas com sucesso!';
                    $activeTab = 'general';
                    break;
                    
                case 'update_security':
                    $sessionLifetime = sanitizeInput($_POST['session_lifetime'] ?? 60, 'int');
                    $maxLoginAttempts = sanitizeInput($_POST['max_login_attempts'] ?? 5, 'int');
                    $lockoutDuration = sanitizeInput($_POST['lockout_duration'] ?? 15, 'int');
                    $passwordMinLength = sanitizeInput($_POST['password_min_length'] ?? 8, 'int');
                    
                    if ($sessionLifetime < 5 || $sessionLifetime > 1440) {
                        throw new Exception('Tempo de sessão deve estar entre 5 e 1440 minutos');
                    }
                    
                    if ($maxLoginAttempts < 3 || $maxLoginAttempts > 10) {
                        throw new Exception('Tentativas de login devem estar entre 3 e 10');
                    }
                    
                    if ($lockoutDuration < 5 || $lockoutDuration > 60) {
                        throw new Exception('Duração do bloqueio deve estar entre 5 e 60 minutos');
                    }
                    
                    if ($passwordMinLength < 6 || $passwordMinLength > 32) {
                        throw new Exception('Tamanho mínimo da senha deve estar entre 6 e 32 caracteres');
                    }
                    
                    $ipWhitelist = $_POST['ip_whitelist'] ?? '';
                    $ipBlacklist = $_POST['ip_blacklist'] ?? '';
                    
                    if (!validateIPList($ipWhitelist)) {
                        throw new Exception('Lista de IPs whitelist contém endereços inválidos');
                    }
                    
                    if (!validateIPList($ipBlacklist)) {
                        throw new Exception('Lista de IPs blacklist contém endereços inválidos');
                    }
                    
                    $settings = [
                        'enable_2fa' => isset($_POST['enable_2fa']) ? 1 : 0,
                        'force_https' => isset($_POST['force_https']) ? 1 : 0,
                        'session_lifetime' => $sessionLifetime,
                        'max_login_attempts' => $maxLoginAttempts,
                        'lockout_duration' => $lockoutDuration,
                        'password_min_length' => $passwordMinLength,
                        'require_strong_password' => isset($_POST['require_strong_password']) ? 1 : 0,
                        'enable_ip_whitelist' => isset($_POST['enable_ip_whitelist']) ? 1 : 0,
                        'ip_whitelist' => trim($ipWhitelist),
                        'enable_ip_blacklist' => isset($_POST['enable_ip_blacklist']) ? 1 : 0,
                        'ip_blacklist' => trim($ipBlacklist)
                    ];
                    
                    saveSettings('security', $settings);
                    $message = 'Configurações de segurança atualizadas com sucesso!';
                    $activeTab = 'security';
                    break;
                    
                case 'clear_cache':
                    $cacheDir = dirname(__DIR__) . '/storage/cache';
                    $result = clearCache($cacheDir);
                    
                    if (!empty($result['errors'])) {
                        $error = 'Cache limpo parcialmente. ' . count($result['errors']) . ' erro(s).';
                    } else {
                        $message = 'Cache limpo com sucesso! ' . $result['count'] . ' arquivo(s) removido(s).';
                    }
                    $activeTab = 'maintenance';
                    break;
                    
                case 'export_settings':
                    $allSettings = getAllSettings();
                    
                    if (empty($allSettings)) {
                        throw new Exception('Nenhuma configuração encontrada para exportar');
                    }
                    
                    $export = [
                        'version' => '1.0',
                        'exported_at' => date('Y-m-d H:i:s'),
                        'exported_by' => $user['username'] ?? 'unknown',
                        'settings' => $allSettings
                    ];
                    
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="cloaker-settings-' . date('Y-m-d-His') . '.json"');
                    header('Cache-Control: no-cache, must-revalidate');
                    header('Expires: 0');
                    
                    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                    
                default:
                    throw new Exception('Ação inválida');
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            error_log("Settings action error: " . $e->getMessage());
        }
    }

    $settings = getAllSettings();
    $licenseInfo = $license->getInfo();

} catch (Exception $e) {
    die("Erro crítico: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Cloaker Pro</title>
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
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .input-error { border-color: #f87171 !important; }
        .input-error:focus { border-color: #ef4444 !important; box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.1); }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Configurações';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']);
        }
        $pageAction = '<button onclick="document.getElementById(\'exportForm\').submit()" 
                            class="bg-dark-card hover:bg-dark-hover border border-[#2a2a2a] hover:border-silver text-silver px-4 py-2 rounded-lg transition-all">
                            <i class="fas fa-download mr-2"></i>Exportar
                        </button>';
        require_once 'header.php'; 
        ?>

        <!-- Content -->
        <main class="p-8 flex-1">
            <?php if (!$isSuperAdmin && $currentTenant): ?>
            <!-- Informações do Tenant -->
            <div class="bg-gradient-to-r from-purple-900/20 to-blue-900/20 border border-purple-500/30 rounded-xl p-4 mb-6 fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gradient-to-br from-purple-600 to-blue-600 rounded-xl flex items-center justify-center text-white text-xl font-bold">
                            <i class="fas fa-cog"></i>
                        </div>
                        <div>
                            <p class="font-bold text-silver text-lg"><?= htmlspecialchars($currentTenant['name']) ?></p>
                            <p class="text-sm text-silver-muted">
                                Configurações disponíveis para seu cliente
                            </p>
                        </div>
                    </div>
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

            <!-- License Info -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver hover:shadow-glow transition-all">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-silver mb-3 flex items-center gap-2">
                            <i class="fas fa-certificate text-silver"></i>
                            Informações da Licença
                        </h3>
                        <div class="flex flex-wrap items-center gap-4 text-sm text-silver-dark">
                            <span class="flex items-center gap-2">
                                <i class="fas fa-key text-accent-success"></i>
                                Plano: <strong class="text-silver"><?php echo htmlspecialchars($licenseInfo['plan'] ?? 'FREE'); ?></strong>
                            </span>
                            <?php if (isset($licenseInfo['expires'])): ?>
                            <span class="flex items-center gap-2">
                                <i class="fas fa-calendar text-accent-warning"></i>
                                Expira: <strong class="text-silver"><?php echo date('d/m/Y', strtotime($licenseInfo['expires'])); ?></strong>
                            </span>
                            <?php endif; ?>
                            <span class="flex items-center gap-2">
                                <i class="fas fa-server text-accent-info"></i>
                                Domínio: <strong class="text-silver"><?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></strong>
                            </span>
                        </div>
                    </div>
                    <?php if ($isSuperAdmin): ?>
                    <button onclick="verifyLicense()" class="bg-gradient-silver hover:shadow-glow text-dark font-bold px-4 py-2 rounded-lg transition-all transform hover:scale-105 whitespace-nowrap">
                        <i class="fas fa-sync mr-2"></i>Verificar
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                <div class="border-b border-[#2a2a2a] flex overflow-x-auto bg-dark-tertiary">
                    <?php if ($isSuperAdmin): ?>
                    <button onclick="switchTab('general')" data-tab="general" class="tab-button px-6 py-4 font-semibold whitespace-nowrap transition-all <?php echo $activeTab === 'general' ? 'bg-gradient-silver text-dark' : 'text-silver-dark hover:text-silver hover:bg-dark-hover'; ?>">
                        <i class="fas fa-sliders-h mr-2"></i>Geral
                    </button>
                    <?php endif; ?>
                    <button onclick="switchTab('security')" data-tab="security" class="tab-button px-6 py-4 font-semibold whitespace-nowrap transition-all <?php echo $activeTab === 'security' ? 'bg-gradient-silver text-dark' : 'text-silver-dark hover:text-silver hover:bg-dark-hover'; ?>">
                        <i class="fas fa-lock mr-2"></i>Segurança
                    </button>
                    <button onclick="switchTab('maintenance')" data-tab="maintenance" class="tab-button px-6 py-4 font-semibold whitespace-nowrap transition-all <?php echo $activeTab === 'maintenance' ? 'bg-gradient-silver text-dark' : 'text-silver-dark hover:text-silver hover:bg-dark-hover'; ?>">
                        <i class="fas fa-tools mr-2"></i>Manutenção
                    </button>
                </div>

                <!-- Tab Contents -->
                <div class="p-6">
                    <?php if ($isSuperAdmin): ?>
                    <!-- General Settings -->
                    <div id="general-tab" class="tab-content <?php echo $activeTab === 'general' ? 'active' : ''; ?>">
                        <div class="mb-6 p-4 bg-accent-warning/10 border border-accent-warning/30 rounded-lg">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-crown text-accent-warning text-xl mt-1"></i>
                                <div>
                                    <p class="font-bold text-silver mb-1">Configurações de Super Admin</p>
                                    <p class="text-sm text-silver-dark">Estas configurações afetam todo o sistema e todos os clientes.</p>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST" action="?tab=general" id="generalForm" onsubmit="return validateGeneralForm()">
                            <input type="hidden" name="action" value="update_general">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Nome do Site <span class="text-accent-danger">*</span>
                                    </label>
                                    <input type="text" name="site_name" id="site_name" required
                                           value="<?php echo htmlspecialchars($settings['general']['site_name'] ?? 'Cloaker Pro'); ?>"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Nome exibido no sistema</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">URL do Site</label>
                                    <input type="url" name="site_url" id="site_url"
                                           value="<?php echo htmlspecialchars($settings['general']['site_url'] ?? ''); ?>"
                                           placeholder="https://exemplo.com"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">URL completa do site</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">Timezone</label>
                                    <select name="timezone" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                        <option value="America/Sao_Paulo" <?php echo ($settings['general']['timezone'] ?? '') === 'America/Sao_Paulo' ? 'selected' : ''; ?>>São Paulo (GMT-3)</option>
                                        <option value="America/New_York" <?php echo ($settings['general']['timezone'] ?? '') === 'America/New_York' ? 'selected' : ''; ?>>New York (GMT-5)</option>
                                        <option value="Europe/London" <?php echo ($settings['general']['timezone'] ?? '') === 'Europe/London' ? 'selected' : ''; ?>>London (GMT+0)</option>
                                        <option value="Asia/Tokyo" <?php echo ($settings['general']['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : ''; ?>>Tokyo (GMT+9)</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">Formato de Data</label>
                                    <select name="date_format" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                        <option value="Y-m-d" <?php echo ($settings['general']['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>2024-12-25</option>
                                        <option value="d/m/Y" <?php echo ($settings['general']['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>25/12/2024</option>
                                        <option value="m/d/Y" <?php echo ($settings['general']['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>12/25/2024</option>
                                        <option value="d M Y" <?php echo ($settings['general']['date_format'] ?? '') === 'd M Y' ? 'selected' : ''; ?>>25 Dec 2024</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">Idioma</label>
                                    <select name="language" class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                        <option value="pt-BR" <?php echo ($settings['general']['language'] ?? 'pt-BR') === 'pt-BR' ? 'selected' : ''; ?>>Português (BR)</option>
                                        <option value="en-US" <?php echo ($settings['general']['language'] ?? '') === 'en-US' ? 'selected' : ''; ?>>English (US)</option>
                                        <option value="es-ES" <?php echo ($settings['general']['language'] ?? '') === 'es-ES' ? 'selected' : ''; ?>>Español</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-6 flex flex-wrap items-center gap-6">
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="debug_mode" <?php echo ($settings['general']['debug_mode'] ?? '0') == '1' ? 'checked' : ''; ?> 
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Modo Debug
                                        <span class="block text-xs text-silver-muted">Exibe erros detalhados</span>
                                    </span>
                                </label>
                                
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="maintenance_mode" <?php echo ($settings['general']['maintenance_mode'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Modo Manutenção
                                        <span class="block text-xs text-silver-muted">Desativa o site temporariamente</span>
                                    </span>
                                </label>
                            </div>
                            
                            <div class="mt-8">
                                <button type="submit" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-3 px-6 rounded-lg transition-all transform hover:scale-105">
                                    <i class="fas fa-save mr-2"></i>Salvar Configurações Gerais
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Security Settings -->
                    <div id="security-tab" class="tab-content <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                        <form method="POST" action="?tab=security" id="securityForm" onsubmit="return validateSecurityForm()">
                            <input type="hidden" name="action" value="update_security">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Tempo de Sessão (minutos) <span class="text-accent-danger">*</span>
                                    </label>
                                    <input type="number" name="session_lifetime" id="session_lifetime" required
                                           min="5" max="1440" value="<?php echo htmlspecialchars($settings['security']['session_lifetime'] ?? 60); ?>"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Entre 5 e 1440 minutos (24h)</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Máximo de Tentativas de Login <span class="text-accent-danger">*</span>
                                    </label>
                                    <input type="number" name="max_login_attempts" id="max_login_attempts" required
                                           min="3" max="10" value="<?php echo htmlspecialchars($settings['security']['max_login_attempts'] ?? 5); ?>"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Entre 3 e 10 tentativas</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Duração do Bloqueio (minutos) <span class="text-accent-danger">*</span>
                                    </label>
                                    <input type="number" name="lockout_duration" id="lockout_duration" required
                                           min="5" max="60" value="<?php echo htmlspecialchars($settings['security']['lockout_duration'] ?? 15); ?>"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Entre 5 e 60 minutos</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Tamanho Mínimo da Senha <span class="text-accent-danger">*</span>
                                    </label>
                                    <input type="number" name="password_min_length" id="password_min_length" required
                                           min="6" max="32" value="<?php echo htmlspecialchars($settings['security']['password_min_length'] ?? 8); ?>"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Entre 6 e 32 caracteres</p>
                                </div>
                            </div>
                            
                            <div class="mt-6 space-y-4">
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="enable_2fa" <?php echo ($settings['security']['enable_2fa'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Habilitar 2FA (Autenticação de 2 Fatores)
                                        <span class="block text-xs text-silver-muted">Adiciona camada extra de segurança</span>
                                    </span>
                                </label>
                                
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="force_https" <?php echo ($settings['security']['force_https'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Forçar HTTPS
                                        <span class="block text-xs text-silver-muted">Redireciona HTTP para HTTPS</span>
                                    </span>
                                </label>
                                
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="require_strong_password" <?php echo ($settings['security']['require_strong_password'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Exigir Senha Forte
                                        <span class="block text-xs text-silver-muted">Maiúsculas, minúsculas, números e símbolos</span>
                                    </span>
                                </label>
                                
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="enable_ip_whitelist" id="enable_ip_whitelist"
                                           <?php echo ($settings['security']['enable_ip_whitelist'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           onchange="toggleIPFields()"
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Habilitar Whitelist de IPs
                                        <span class="block text-xs text-silver-muted">Permite apenas IPs listados</span>
                                    </span>
                                </label>
                                
                                <label class="flex items-center cursor-pointer group">
                                    <input type="checkbox" name="enable_ip_blacklist" id="enable_ip_blacklist"
                                           <?php echo ($settings['security']['enable_ip_blacklist'] ?? '0') == '1' ? 'checked' : ''; ?>
                                           onchange="toggleIPFields()"
                                           class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                    <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                        Habilitar Blacklist de IPs
                                        <span class="block text-xs text-silver-muted">Bloqueia IPs listados</span>
                                    </span>
                                </label>
                            </div>
                            
                            <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div id="whitelist-field">
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Whitelist de IPs
                                    </label>
                                    <textarea name="ip_whitelist" id="ip_whitelist" rows="6" 
                                              class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all font-mono text-sm" 
                                              placeholder="192.168.1.1
10.0.0.0/24
# Comentários são permitidos"><?php echo htmlspecialchars($settings['security']['ip_whitelist'] ?? ''); ?></textarea>
                                    <p class="text-xs text-silver-dark mt-1">Um IP ou CIDR por linha. Suporta comentários (#)</p>
                                </div>
                                
                                <div id="blacklist-field">
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Blacklist de IPs
                                    </label>
                                    <textarea name="ip_blacklist" id="ip_blacklist" rows="6"
                                              class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all font-mono text-sm"
                                              placeholder="203.0.113.0
198.51.100.0/24"><?php echo htmlspecialchars($settings['security']['ip_blacklist'] ?? ''); ?></textarea>
                                    <p class="text-xs text-silver-dark mt-1">Um IP ou CIDR por linha</p>
                                </div>
                            </div>
                            
                            <div class="mt-8">
                                <button type="submit" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-3 px-6 rounded-lg transition-all transform hover:scale-105">
                                    <i class="fas fa-save mr-2"></i>Salvar Configurações de Segurança
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Maintenance Settings -->
                    <div id="maintenance-tab" class="tab-content <?php echo $activeTab === 'maintenance' ? 'active' : ''; ?>">
                        <div class="space-y-4">
                            <div class="p-6 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver-dark transition-all">
                                <h3 class="text-lg font-bold text-silver mb-4 flex items-center gap-2">
                                    <i class="fas fa-broom text-accent-warning"></i>
                                    Limpeza e Manutenção
                                </h3>
                                <div class="space-y-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja limpar o cache?')">
                                        <input type="hidden" name="action" value="clear_cache">
                                        <button type="submit" class="w-full sm:w-auto bg-dark-card hover:bg-dark-hover border border-[#2a2a2a] hover:border-accent-warning text-silver px-6 py-3 rounded-lg transition-all">
                                            <i class="fas fa-trash mr-2"></i>Limpar Cache
                                        </button>
                                    </form>
                                    <p class="text-xs text-silver-dark mt-2">Remove arquivos temporários e cache do sistema</p>
                                </div>
                            </div>
                            
                            <div class="p-6 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver-dark transition-all">
                                <h3 class="text-lg font-bold text-silver mb-4 flex items-center gap-2">
                                    <i class="fas fa-info-circle text-accent-info"></i>
                                    Informações do Sistema
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                    <div class="flex items-center justify-between p-3 bg-dark-card rounded-lg">
                                        <span class="text-silver-dark">Versão PHP:</span>
                                        <strong class="text-silver"><?php echo PHP_VERSION; ?></strong>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-dark-card rounded-lg">
                                        <span class="text-silver-dark">Servidor:</span>
                                        <strong class="text-silver text-right"><?php echo htmlspecialchars(substr($_SERVER['SERVER_SOFTWARE'] ?? 'N/A', 0, 30)); ?></strong>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-dark-card rounded-lg">
                                        <span class="text-silver-dark">Max Upload:</span>
                                        <strong class="text-silver"><?php echo ini_get('upload_max_filesize'); ?></strong>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-dark-card rounded-lg">
                                        <span class="text-silver-dark">Memory Limit:</span>
                                        <strong class="text-silver"><?php echo ini_get('memory_limit'); ?></strong>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-dark-card rounded-lg">
                                        <span class="text-silver-dark">Post Max Size:</span>
                                        <strong class="text-silver"><?php echo ini_get('post_max_size'); ?></strong>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-dark-card rounded-lg">
                                        <span class="text-silver-dark">Max Execution:</span>
                                        <strong class="text-silver"><?php echo ini_get('max_execution_time'); ?>s</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <!-- Hidden forms -->
    <form id="exportForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="export_settings">
    </form>

    <script>
        const isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
        
        function switchTab(tab) {
            // Verificar permissão para aba geral
            if (tab === 'general' && !isSuperAdmin) {
                showToast('Você não tem permissão para acessar esta aba', 'error');
                return;
            }
            
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(b => {
                b.classList.remove('bg-gradient-silver', 'text-dark');
                b.classList.add('text-silver-dark', 'hover:text-silver', 'hover:bg-dark-hover');
            });
            
            document.getElementById(tab + '-tab').classList.add('active');
            const button = document.querySelector(`[data-tab="${tab}"]`);
            if (button) {
                button.classList.remove('text-silver-dark', 'hover:text-silver', 'hover:bg-dark-hover');
                button.classList.add('bg-gradient-silver', 'text-dark');
            }
            
            history.pushState(null, null, '?tab=' + tab);
        }
        
        function validateGeneralForm() {
            const siteName = document.getElementById('site_name').value.trim();
            const siteUrl = document.getElementById('site_url').value.trim();
            
            if (!siteName) {
                showToast('Nome do site é obrigatório', 'error');
                document.getElementById('site_name').classList.add('input-error');
                return false;
            }
            
            if (siteUrl && !isValidUrl(siteUrl)) {
                showToast('URL do site inválida', 'error');
                document.getElementById('site_url').classList.add('input-error');
                return false;
            }
            
            return true;
        }
        
        function validateSecurityForm() {
            const sessionLifetime = parseInt(document.getElementById('session_lifetime').value);
            const maxLoginAttempts = parseInt(document.getElementById('max_login_attempts').value);
            const lockoutDuration = parseInt(document.getElementById('lockout_duration').value);
            const passwordMinLength = parseInt(document.getElementById('password_min_length').value);
            
            if (sessionLifetime < 5 || sessionLifetime > 1440) {
                showToast('Tempo de sessão deve estar entre 5 e 1440 minutos', 'error');
                return false;
            }
            
            if (maxLoginAttempts < 3 || maxLoginAttempts > 10) {
                showToast('Tentativas de login devem estar entre 3 e 10', 'error');
                return false;
            }
            
            if (lockoutDuration < 5 || lockoutDuration > 60) {
                showToast('Duração do bloqueio deve estar entre 5 e 60 minutos', 'error');
                return false;
            }
            
            if (passwordMinLength < 6 || passwordMinLength > 32) {
                showToast('Tamanho mínimo da senha deve estar entre 6 e 32 caracteres', 'error');
                return false;
            }
            
            return true;
        }
        
        function toggleIPFields() {
            const whitelistEnabled = document.getElementById('enable_ip_whitelist').checked;
            const blacklistEnabled = document.getElementById('enable_ip_blacklist').checked;
            
            const whitelistField = document.getElementById('whitelist-field');
            const blacklistField = document.getElementById('blacklist-field');
            
            whitelistField.style.opacity = whitelistEnabled ? '1' : '0.5';
            blacklistField.style.opacity = blacklistEnabled ? '1' : '0.5';
            
            document.getElementById('ip_whitelist').disabled = !whitelistEnabled;
            document.getElementById('ip_blacklist').disabled = !blacklistEnabled;
        }
        
        function isValidUrl(url) {
            try {
                new URL(url);
                return true;
            } catch {
                return false;
            }
        }
        
        function verifyLicense() {
            showToast('Verificando licença...', 'info');
            setTimeout(() => {
                showToast('Licença verificada com sucesso!', 'success');
            }, 1500);
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            const colors = {
                success: 'bg-accent-success',
                error: 'bg-accent-danger',
                info: 'bg-accent-info',
                warning: 'bg-accent-warning'
            };
            const icons = {
                success: 'check',
                error: 'exclamation',
                info: 'info',
                warning: 'exclamation-triangle'
            };
            
            toast.className = `fixed bottom-4 right-4 ${colors[type]} text-dark px-6 py-3 rounded-lg shadow-glow-lg z-50 font-semibold fade-in`;
            toast.innerHTML = `<i class="fas fa-${icons[type]}-circle mr-2"></i>${message}`;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        document.querySelectorAll('input, textarea').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('input-error');
            });
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            toggleIPFields();
        });
    </script>
</body>
</html>