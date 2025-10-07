<?php
/**
 * ============================================
 * CLOAKER PRO - LICENSE GUARD v3.0 FINAL
 * ============================================
 * 
 * Sistema de proteção de licença com bloqueio total
 * 
 * CARACTERÍSTICAS:
 * - Bloqueio em 100% das páginas (exceto license.php, login.php, logout.php)
 * - Cache de 5 minutos para detecção rápida de bloqueios
 * - Suporte a webhook para bloqueio instantâneo
 * - Proteção contra redeclaração de funções
 * - Exit após todos os redirecionamentos
 * 
 * AUTOR: Cloaker Pro Team
 * VERSÃO: 3.0
 * DATA: 2025-01-06
 * ============================================
 */

// ==========================================
// 1. INICIAR SESSÃO (CRÍTICO!)
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================
// 2. PÁGINAS PERMITIDAS SEM LICENÇA
// ==========================================
$current_page = basename($_SERVER['PHP_SELF']);
$allowed_pages = ['license.php', 'login.php', 'logout.php'];

if (in_array($current_page, $allowed_pages)) {
    return; // Permite acesso sem verificação
}

// ==========================================
// 3. FUNÇÕES AUXILIARES
// ==========================================

/**
 * Carregar variáveis de ambiente do arquivo .env
 */
if (!function_exists('loadLicenseEnv')) {
    function loadLicenseEnv() {
        $envFile = __DIR__ . '/../.env';
        $env = [];
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') === false) continue;
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value, '"\'');
            }
        }
        
        return $env;
    }
}

/**
 * Obter URL base do sistema
 */
if (!function_exists('getLicenseBaseUrl')) {
    function getLicenseBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        
        $script_name = $_SERVER['SCRIPT_NAME'];
        $base_path = '';
        
        if (strpos($script_name, '/') !== false) {
            $parts = explode('/', $script_name);
            array_pop($parts); // Remove nome do arquivo
            
            // Remove 'admin', 'includes' ou 'api' se estiver nesses diretórios
            if (end($parts) === 'admin' || end($parts) === 'includes' || end($parts) === 'api') {
                array_pop($parts);
            }
            
            $base_path = implode('/', $parts);
        }
        
        return $protocol . '://' . $host . $base_path;
    }
}

/**
 * Redirecionar para página de licença com erro
 */
if (!function_exists('redirectToLicense')) {
    function redirectToLicense($error) {
        $_SESSION['license_error'] = $error;
        header("Location: " . getLicenseBaseUrl() . "/admin/license.php");
        exit;
    }
}

// ==========================================
// 4. CARREGAR CONFIGURAÇÕES
// ==========================================
$env = loadLicenseEnv();

// ==========================================
// 5. DEFINIR ARQUIVOS E PASTAS
// ==========================================
$licenseFile = __DIR__ . '/../config/license.json';
$cacheFile = __DIR__ . '/../cache/license_validation.json';

// Criar pastas se não existirem
@mkdir(__DIR__ . '/../config', 0755, true);
@mkdir(__DIR__ . '/../cache', 0755, true);

// ==========================================
// 6. VERIFICAR SE LICENÇA ESTÁ CONFIGURADA
// ==========================================
if (!file_exists($licenseFile)) {
    redirectToLicense('Sistema bloqueado - Configure sua licença para acessar');
}

// ==========================================
// 7. CARREGAR CONFIGURAÇÃO DE LICENÇA
// ==========================================
$licenseConfig = json_decode(file_get_contents($licenseFile), true);

if (!$licenseConfig || 
    empty($licenseConfig['license_key']) || 
    empty($licenseConfig['domain']) || 
    empty($licenseConfig['email'])) {
    redirectToLicense('Configuração de licença inválida - Reconfigure sua licença');
}

// ==========================================
// 8. VERIFICAR CACHE (5 MINUTOS)
// ==========================================
$cache_duration = 300; // 5 MINUTOS (300 segundos)

if (file_exists($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true);
    
    if ($cache && isset($cache['timestamp'])) {
        $cache_age = time() - $cache['timestamp'];
        
        // Cache ainda válido (menos de 5 minutos)
        if ($cache_age < $cache_duration) {
            
            // BLOQUEIO IMEDIATO se cache diz que é inválido
            if (isset($cache['valid']) && $cache['valid'] === false) {
                $_SESSION['license_error'] = $cache['error'] ?? 'Licença inválida';
                $_SESSION['license_blocked_by_webhook'] = $cache['blocked_by_webhook'] ?? false;
                
                header("Location: " . getLicenseBaseUrl() . "/admin/license.php");
                exit;
            }
            
            // Cache válido e licença OK - liberar acesso
            if (isset($cache['valid']) && $cache['valid'] === true) {
                return;
            }
        }
    }
}

// ==========================================
// 9. CACHE EXPIROU - VALIDAR COM API
// ==========================================
$webhook_secret = $env['CLOAKER_WEBHOOK_SECRET'] ?? $env['AUTOSTACKER_WEBHOOK_SECRET'] ?? null;
$api_url = $env['AUTOSTACKER_API_URL'] ?? null;

if (!$webhook_secret || !$api_url) {
    redirectToLicense('Sistema de licença não configurado - Verifique arquivo .env');
}

// ==========================================
// 10. GERAR ASSINATURA HMAC
// ==========================================
$payload = $licenseConfig['license_key'] . '|' . 
           $licenseConfig['domain'] . '|' . 
           $licenseConfig['email'];

$signature = hash_hmac('sha256', $payload, $webhook_secret);

// ==========================================
// 11. PREPARAR DADOS PARA API
// ==========================================
$data = [
    'license_key' => $licenseConfig['license_key'],
    'domain' => $licenseConfig['domain'],
    'email' => $licenseConfig['email'],
    'signature' => $signature
];

// ==========================================
// 12. FAZER REQUISIÇÃO À API
// ==========================================
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'User-Agent: CloakerPro/2.4.0'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// ==========================================
// 13. TRATAR ERROS DE COMUNICAÇÃO
// ==========================================
if ($curl_error) {
    $error = "Erro de comunicação com servidor de licenças: " . $curl_error;
    
    // Salvar cache de erro
    @file_put_contents($cacheFile, json_encode([
        'valid' => false,
        'timestamp' => time(),
        'error' => $error
    ]));
    
    redirectToLicense($error);
}

if (!$response || $http_code !== 200) {
    $error = "Erro HTTP na validação de licença (Código: $http_code)";
    
    // Salvar cache de erro
    @file_put_contents($cacheFile, json_encode([
        'valid' => false,
        'timestamp' => time(),
        'error' => $error
    ]));
    
    redirectToLicense($error);
}

// ==========================================
// 14. PROCESSAR RESPOSTA DA API
// ==========================================
$result = json_decode($response, true);

if (!$result || !isset($result['success'])) {
    $error = 'Resposta inválida do servidor de licenças';
    
    @file_put_contents($cacheFile, json_encode([
        'valid' => false,
        'timestamp' => time(),
        'error' => $error
    ]));
    
    redirectToLicense($error);
}

// ==========================================
// 15. VERIFICAR SE LICENÇA É VÁLIDA
// ==========================================
if ($result['success'] !== true) {
    $error = $result['error'] ?? 'Licença inválida ou expirada';
    
    // Salvar cache de licença inválida
    @file_put_contents($cacheFile, json_encode([
        'valid' => false,
        'timestamp' => time(),
        'error' => $error,
        'blocked_by_api' => true
    ]));
    
    redirectToLicense($error);
}

// ==========================================
// 16. LICENÇA VÁLIDA - SALVAR CACHE
// ==========================================
@file_put_contents($cacheFile, json_encode([
    'valid' => true,
    'timestamp' => time(),
    'license' => $result['license'] ?? [],
    'validated_at' => date('Y-m-d H:i:s')
]));

// Limpar mensagens de erro anteriores
unset($_SESSION['license_error']);
unset($_SESSION['license_blocked_by_webhook']);

// ==========================================
// 17. LIBERAR ACESSO AO SISTEMA
// ==========================================
return; // Permite acesso