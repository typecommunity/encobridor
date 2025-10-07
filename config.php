<?php
/**
 * Cloaker Pro - Configuração do Sistema
 * Versão 1.0.0 - Otimizada
 * 
 * Este arquivo configura todas as constantes e variáveis globais do sistema
 */

// ==========================================
// CONFIGURAÇÕES DE ERRO
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0); // SEMPRE 0 em produção
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/storage/logs/error.log');

// ==========================================
// SESSÃO
// ==========================================

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'use_strict_mode' => true
    ]);
}

// ==========================================
// TIMEZONE
// ==========================================

date_default_timezone_set('America/Sao_Paulo');

// ==========================================
// FUNÇÕES DE DETECÇÃO
// ==========================================

/**
 * Detectar URL base automaticamente
 * Remove /admin/, /c/ e outros diretórios do caminho
 */
function getBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    
    // Remover diretórios conhecidos do caminho
    $removePatterns = ['/admin', '\\admin', '/c', '\\c', '/api', '\\api'];
    $script = str_replace($removePatterns, '', $script);
    
    // Limpar barras
    $script = trim($script, '/\\');
    $script = empty($script) ? '' : '/' . $script;
    
    return $protocol . '://' . $host . $script;
}

/**
 * Detectar caminho base do sistema
 */
function getBasePath() {
    return dirname(__FILE__);
}

// ==========================================
// CARREGAR VARIÁVEIS DE AMBIENTE (.env)
// ==========================================

$envFile = __DIR__ . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Ignorar comentários
        if (strpos(trim($line), '#') === 0) continue;
        
        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remover aspas
            $value = trim($value, '"\'');
            
            // Definir variável de ambiente
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// ==========================================
// CONSTANTES PRINCIPAIS - URLs e Caminhos
// ==========================================

// BASE_URL: Prioridade: BASE_URL > APP_URL > Detecção Automática
define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? $_ENV['APP_URL'] ?? getBaseUrl(), '/'));
define('BASE_PATH', rtrim(getBasePath(), '/'));

// URLs do sistema
define('ADMIN_URL', BASE_URL . '/admin');
define('ASSETS_URL', BASE_URL . '/admin/assets');
define('API_URL', BASE_URL . '/api');
define('CLOAK_URL', BASE_URL . '/c');

// Caminhos de diretórios
define('CORE_PATH', BASE_PATH . '/core');
define('ADMIN_PATH', BASE_PATH . '/admin');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('DATA_PATH', BASE_PATH . '/data');
define('CACHE_PATH', STORAGE_PATH . '/cache');
define('LOGS_PATH', STORAGE_PATH . '/logs');
define('BACKUP_PATH', STORAGE_PATH . '/backups');

// ==========================================
// BANCO DE DADOS
// ==========================================

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'ataw_clock');
define('DB_USER', $_ENV['DB_USER'] ?? 'ataw_clock');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// ==========================================
// APLICAÇÃO
// ==========================================

define('APP_NAME', $_ENV['APP_NAME'] ?? 'Cloaker Pro');
define('APP_VERSION', $_ENV['APP_VERSION'] ?? '1.0.0');
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('APP_KEY', $_ENV['APP_KEY'] ?? base64_encode(random_bytes(32)));

// ==========================================
// LICENÇA
// ==========================================

define('LICENSE_KEY', $_ENV['LICENSE_KEY'] ?? '');
define('LICENSE_DOMAIN', $_ENV['LICENSE_DOMAIN'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
define('AUTOSTACKER_API', $_ENV['AUTOSTACKER_API'] ?? 'https://autostacker.app/api/cloaker/verify');

// ==========================================
// MODOS DE OPERAÇÃO
// ==========================================

define('DEBUG_MODE', filter_var($_ENV['DEBUG_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('MAINTENANCE_MODE', filter_var($_ENV['MAINTENANCE_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('DEMO_MODE', filter_var($_ENV['DEMO_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));

// ==========================================
// CACHE
// ==========================================

define('CACHE_ENABLED', filter_var($_ENV['CACHE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('CACHE_TTL', intval($_ENV['CACHE_TTL'] ?? 300)); // 5 minutos
define('CACHE_DRIVER', $_ENV['CACHE_DRIVER'] ?? 'file'); // file, redis, memcached

// ==========================================
// REDIS (se usar)
// ==========================================

define('REDIS_HOST', $_ENV['REDIS_HOST'] ?? '127.0.0.1');
define('REDIS_PORT', intval($_ENV['REDIS_PORT'] ?? 6379));
define('REDIS_PASSWORD', $_ENV['REDIS_PASSWORD'] ?? null);
define('REDIS_DATABASE', intval($_ENV['REDIS_DATABASE'] ?? 0));

// ==========================================
// SEGURANÇA
// ==========================================

define('SESSION_LIFETIME', intval($_ENV['SESSION_LIFETIME'] ?? 120)); // minutos
define('CSRF_ENABLED', filter_var($_ENV['CSRF_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('RATE_LIMIT_ENABLED', filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('RATE_LIMIT_REQUESTS', intval($_ENV['RATE_LIMIT_REQUESTS'] ?? 60)); // requests/minuto

// ==========================================
// GEOIP
// ==========================================

define('GEOIP_ENABLED', filter_var($_ENV['GEOIP_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('GEOIP_DATABASE', $_ENV['GEOIP_DATABASE'] ?? DATA_PATH . '/GeoLite2-City.mmdb');
define('GEOIP_API_FALLBACK', $_ENV['GEOIP_API_FALLBACK'] ?? 'http://ip-api.com/json/');

// ==========================================
// DETECÇÃO
// ==========================================

define('BOT_DETECTION', filter_var($_ENV['BOT_DETECTION'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('VPN_DETECTION', filter_var($_ENV['VPN_DETECTION'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('PROXY_DETECTION', filter_var($_ENV['PROXY_DETECTION'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('TOR_DETECTION', filter_var($_ENV['TOR_DETECTION'] ?? true, FILTER_VALIDATE_BOOLEAN));

// ==========================================
// ANALYTICS
// ==========================================

define('ANALYTICS_ENABLED', filter_var($_ENV['ANALYTICS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('ANALYTICS_RETENTION_DAYS', intval($_ENV['ANALYTICS_RETENTION_DAYS'] ?? 90));

// ==========================================
// EMAIL (para notificações)
// ==========================================

define('MAIL_DRIVER', $_ENV['MAIL_DRIVER'] ?? 'smtp');
define('MAIL_HOST', $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com');
define('MAIL_PORT', intval($_ENV['MAIL_PORT'] ?? 587));
define('MAIL_USERNAME', $_ENV['MAIL_USERNAME'] ?? '');
define('MAIL_PASSWORD', $_ENV['MAIL_PASSWORD'] ?? '');
define('MAIL_ENCRYPTION', $_ENV['MAIL_ENCRYPTION'] ?? 'tls');
define('MAIL_FROM_ADDRESS', $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@' . LICENSE_DOMAIN);
define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME'] ?? APP_NAME);

// ==========================================
// AUTOLOAD DE CLASSES
// ==========================================

spl_autoload_register(function ($class) {
    // Remover namespace raiz
    $class = str_replace('CloakerPro\\', '', $class);
    
    // Converter namespace em path
    $class = str_replace('\\', '/', $class);
    
    // Locais para procurar classes
    $paths = [
        CORE_PATH . '/' . $class . '.php',
        ADMIN_PATH . '/' . $class . '.php',
        BASE_PATH . '/' . $class . '.php'
    ];
    
    foreach ($paths as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ==========================================
// FUNÇÕES AUXILIARES GLOBAIS
// ==========================================

/**
 * Obter configuração
 */
function config($key, $default = null) {
    $constName = strtoupper(str_replace('.', '_', $key));
    
    if (defined($constName)) {
        return constant($constName);
    }
    
    if (isset($_ENV[$constName])) {
        return $_ENV[$constName];
    }
    
    return $default;
}

/**
 * Verificar modo debug
 */
function isDebug() {
    return DEBUG_MODE === true;
}

/**
 * Log de debug
 */
function debugLog($message, $data = null) {
    if (!isDebug()) return;
    
    $log = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($data !== null) {
        $log .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $log .= PHP_EOL;
    
    @file_put_contents(LOGS_PATH . '/debug.log', $log, FILE_APPEND | LOCK_EX);
}

/**
 * Sanitizar entrada
 */
function sanitize($input, $type = 'string') {
    if (is_array($input)) {
        return array_map(function($item) use ($type) {
            return sanitize($item, $type);
        }, $input);
    }
    
    switch ($type) {
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'int':
            return intval($input);
        case 'float':
            return floatval($input);
        case 'bool':
            return filter_var($input, FILTER_VALIDATE_BOOLEAN);
        case 'html':
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        default:
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Gerar token CSRF
 */
function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validar token CSRF
 */
function csrfValid($token) {
    if (!CSRF_ENABLED) return true;
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Redirecionar
 */
function redirect($url, $code = 302) {
    header("Location: $url", true, $code);
    exit;
}

/**
 * Resposta JSON
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Verificar se é AJAX
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Obter IP real do visitante
 */
function getClientIp() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Formatar bytes para legível
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Criar diretórios se não existirem
 */
function ensureDirectories() {
    $dirs = [
        STORAGE_PATH,
        CACHE_PATH,
        LOGS_PATH,
        DATA_PATH,
        BACKUP_PATH,
        ADMIN_PATH . '/assets/css',
        ADMIN_PATH . '/assets/js',
        ADMIN_PATH . '/assets/img'
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

// ==========================================
// INICIALIZAÇÃO
// ==========================================

// Criar diretórios necessários
ensureDirectories();

// Verificar modo de manutenção
if (MAINTENANCE_MODE && !defined('SKIP_MAINTENANCE')) {
    $allowedIPs = array_map('trim', explode(',', $_ENV['MAINTENANCE_IPS'] ?? ''));
    $currentIP = getClientIp();
    
    if (!in_array($currentIP, $allowedIPs)) {
        http_response_code(503);
        header('Retry-After: 3600');
        die('<!DOCTYPE html><html><head><title>Manutenção</title><meta charset="utf-8"></head>
             <body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;background:#f5f5f5;">
             <div style="text-align:center;"><h1>Em Manutenção</h1><p>Voltaremos em breve!</p></div></body></html>');
    }
}

// ==========================================
// CONEXÃO COM BANCO DE DADOS
// ==========================================

try {
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=%s",
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
        PDO::ATTR_PERSISTENT => false
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    if (DEBUG_MODE) {
        debugLog('Database connected', [
            'host' => DB_HOST,
            'database' => DB_NAME
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    
    if (DEBUG_MODE) {
        die("Database error: " . $e->getMessage());
    }
    
    http_response_code(503);
    die('Service temporarily unavailable');
}

// ==========================================
// LOG DE INICIALIZAÇÃO (DEBUG)
// ==========================================

if (DEBUG_MODE) {
    debugLog('System initialized', [
        'BASE_URL' => BASE_URL,
        'APP_ENV' => APP_ENV,
        'PHP_VERSION' => PHP_VERSION,
        'SCRIPT' => $_SERVER['SCRIPT_NAME'] ?? 'N/A'
    ]);
}