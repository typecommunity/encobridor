<?php
/**
 * ============================================
 * CLOAKER PRO - LICENSE VERIFICATION MIDDLEWARE
 * ============================================
 * 
 * Arquivo: license_check.php
 * Caminho: cloaker-pro/includes/license_check.php
 * 
 * VERSÃO COMPLETA COM:
 * - Bloqueio total do sistema com licença inválida
 * - Redirecionamento inteligente (config vs bloqueio)
 * - Suporte a subdiretórios
 * - Cache de 24h
 * - Tolerância a falhas de API
 * ============================================
 */

// Carregar configurações do .env
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

// Função para obter URL base do sistema
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // Detectar caminho base do sistema
    $script_name = $_SERVER['SCRIPT_NAME'];
    $base_path = '';
    
    // Se estiver em subdiretório (ex: /cloaker-pro/)
    if (strpos($script_name, '/') !== false) {
        $parts = explode('/', $script_name);
        array_pop($parts); // Remove o nome do arquivo
        
        // Remove 'admin' ou 'includes' se estiver nesses diretórios
        if (end($parts) === 'admin' || end($parts) === 'includes') {
            array_pop($parts);
        }
        
        $base_path = implode('/', $parts);
    }
    
    return $protocol . '://' . $host . $base_path;
}

// Função para validar licença
function validateLicense() {
    $cache_file = __DIR__ . '/../cache/license_validation.json';
    $config_file = __DIR__ . '/../config/license.json';
    $cache_duration = 86400; // 24 horas
    
    // Criar pasta cache se não existir
    if (!is_dir(__DIR__ . '/../cache')) {
        @mkdir(__DIR__ . '/../cache', 0755, true);
    }
    
    // Verificar cache existente
    if (file_exists($cache_file)) {
        $cache = json_decode(file_get_contents($cache_file), true);
        
        if ($cache && isset($cache['timestamp']) && (time() - $cache['timestamp']) < $cache_duration) {
            if ($cache['valid'] === false) {
                redirectToBlocked($cache['error'] ?? 'License invalid');
            }
            return true;
        }
    }
    
    // Verificar se existe configuração de licença
    if (!file_exists($config_file)) {
        redirectToLicenseConfig('License not configured - Please configure your license');
    }
    
    $config = json_decode(file_get_contents($config_file), true);
    
    if (!$config || empty($config['license_key']) || empty($config['domain']) || empty($config['email'])) {
        redirectToLicenseConfig('Invalid license configuration - Please reconfigure');
    }
    
    // Carregar webhook secret
    $webhook_secret = getenv('CLOAKER_WEBHOOK_SECRET') ?: getenv('AUTOSTACKER_WEBHOOK_SECRET');
    $api_url = getenv('AUTOSTACKER_API_URL');
    
    if (!$webhook_secret || !$api_url) {
        redirectToLicenseConfig('License system not configured - Check .env file');
    }
    
    // Gerar assinatura
    $payload = $config['license_key'] . '|' . $config['domain'] . '|' . $config['email'];
    $signature = hash_hmac('sha256', $payload, $webhook_secret);
    
    // Preparar dados
    $data = [
        'license_key' => $config['license_key'],
        'domain' => $config['domain'],
        'email' => $config['email'],
        'signature' => $signature
    ];
    
    // Fazer requisição à API
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Verificar resposta
    if (!$response || $http_code !== 200) {
        // Tolerância a falhas - usar cache antigo se existir
        if (isset($cache) && $cache['valid'] === true) {
            return true;
        }
        saveCacheAndBlock('Unable to validate license - API communication error');
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !isset($result['success'])) {
        saveCacheAndBlock('Invalid API response');
    }
    
    if ($result['success'] !== true) {
        $error = $result['error'] ?? 'License validation failed';
        saveCacheAndBlock($error);
    }
    
    // Licença válida - salvar cache
    file_put_contents($cache_file, json_encode([
        'valid' => true,
        'timestamp' => time(),
        'license' => $result['license'] ?? []
    ]));
    
    return true;
}

// Salvar cache de falha e bloquear
function saveCacheAndBlock($error) {
    $cache_file = __DIR__ . '/../cache/license_validation.json';
    @file_put_contents($cache_file, json_encode([
        'valid' => false,
        'timestamp' => time(),
        'error' => $error
    ]));
    redirectToBlocked($error);
}

// Redirecionar para página de bloqueio
function redirectToBlocked($error) {
    $_SESSION['license_error'] = $error;
    $base_url = getBaseUrl();
    header("Location: {$base_url}/license-blocked.php");
    exit;
}

// Redirecionar para página de configuração
function redirectToLicenseConfig($error) {
    $_SESSION['license_error'] = $error;
    $base_url = getBaseUrl();
    header("Location: {$base_url}/admin/license.php");
    exit;
}

// Executar validação automaticamente
session_start();

// Páginas que NÃO precisam de validação de licença
$current_page = basename($_SERVER['PHP_SELF']);
$excluded_pages = [
    'login.php',              // Login do admin
    'license.php',            // Configuração de licença
    'license-blocked.php',    // Página de bloqueio
    'logout.php'              // Logout do admin
];

// Se não estiver em página excluída, validar licença
if (!in_array($current_page, $excluded_pages)) {
    validateLicense();
}