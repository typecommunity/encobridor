<?php
/**
 * Cloaker Pro - Endpoint Público v3.0 FINAL
 * Redirecionamento inteligente com Engine integrado
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/storage/logs/error.log');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Utils.php';
require_once __DIR__ . '/core/Campaign.php';
require_once __DIR__ . '/core/Engine.php';

$campaign = new Campaign();
$engine = new Engine();

// OBTER SLUG DA URL
$slug = $_GET['c'] ?? '';

if (empty($slug) && isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = explode('/', trim($uri, '/'));
    
    if (count($parts) >= 2 && $parts[0] === 'c') {
        $slug = $parts[1];
    }
}

// Validar slug
if (empty($slug) || !preg_match('/^[a-z0-9\-]{8,64}$/i', $slug)) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 - Not Found</h1></body></html>');
}

try {
    // BUSCAR CAMPANHA
    $campaignData = $campaign->getBySlug($slug);
    
    if (!$campaignData) {
        http_response_code(404);
        error_log("Campaign not found: slug=$slug");
        die('<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 - Not Found</h1></body></html>');
    }
    
    // VERIFICAR STATUS
    if ($campaignData['status'] !== 'active') {
        http_response_code(503);
        error_log("Campaign inactive: id={$campaignData['id']}, status={$campaignData['status']}");
        die('<!DOCTYPE html><html><head><title>Unavailable</title></head><body><h1>Service Temporarily Unavailable</h1></body></html>');
    }
    
    // PROCESSAR COM ENGINE
    $decision = $engine->process($campaignData['id'], $_SERVER);
    
    // VERIFICAR SE TEM URL
    if (empty($decision['url'])) {
        error_log("No URL returned by Engine for campaign {$campaignData['id']}");
        http_response_code(500);
        die('<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Internal Error</h1></body></html>');
    }
    
    $targetUrl = $decision['url'];
    $mode = $campaignData['mode'] ?? 'redirect';
    
    // HEADERS INFORMATIVOS
    header('X-Cloaker-Action: ' . ($decision['action'] ?? 'unknown'));
    header('X-Cloaker-Mode: ' . $mode);
    
    // ==========================================
    // MODO PROXY
    // ==========================================
    if ($mode === 'proxy') {
        $content = fetchUrlContent($targetUrl);
        
        if ($content !== false) {
            // Injetar fingerprint.js
            $fingerprintScript = '<script src="' . rtrim(BASE_URL, '/') . '/assets/js/fingerprint.js" defer></script>';
            $content = str_ireplace('</body>', $fingerprintScript . "\n</body>", $content);
            
            header('Content-Type: text/html; charset=UTF-8');
            echo $content;
            exit;
        }
        
        // Fallback para redirect se proxy falhar
        error_log("Proxy mode failed for campaign {$campaignData['id']}, falling back to redirect");
    }
    
    // ==========================================
    // MODO IFRAME
    // ==========================================
    if ($mode === 'iframe') {
        echo generateIframePage($targetUrl);
        exit;
    }
    
    // ==========================================
    // MODO REDIRECT (PADRÃO)
    // ==========================================
    
    // Aplicar delay se configurado
    if (!empty($decision['delay']) && $decision['delay'] > 0) {
        sleep((int)$decision['delay']);
    }
    
    // Página de cloaking intermediária
    if (!empty($decision['use_cloaking_page'])) {
        echo generateCloakingPage($targetUrl, $decision);
        exit;
    }
    
    // Redirect 302 simples
    header('Location: ' . $targetUrl, true, 302);
    exit;
    
} catch (Exception $e) {
    error_log('Cloak Critical Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
    http_response_code(500);
    die('<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Internal Server Error</h1></body></html>');
}

// ==========================================
// FUNÇÕES AUXILIARES
// ==========================================

function fetchUrlContent($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
            CURLOPT_ENCODING => '',
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 400 && $content !== false) {
            return $content;
        }
    }
    
    return false;
}

function generateIframePage($url) {
    $baseUrl = rtrim(BASE_URL, '/');
    return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loading...</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; }
        iframe { width: 100%; height: 100%; border: none; display: block; }
    </style>
</head>
<body>
    <iframe src="' . htmlspecialchars($url) . '" allowfullscreen></iframe>
    <script src="' . $baseUrl . '/assets/js/fingerprint.js" defer></script>
</body>
</html>';
}

function generateCloakingPage($url, $decision) {
    $delay = max(0, (int)($decision['delay'] ?? 0));
    $baseUrl = rtrim(BASE_URL, '/');
    
    return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loading...</title>
    <meta http-equiv="refresh" content="' . $delay . ';url=' . htmlspecialchars($url) . '">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: white;
        }
        .container { text-align: center; padding: 40px; }
        .spinner {
            width: 60px;
            height: 60px;
            margin: 0 auto 30px;
            border: 5px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h1 { font-size: 24px; margin-bottom: 10px; font-weight: 600; }
        p { font-size: 16px; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>Carregando...</h1>
        <p>Você será redirecionado em instantes</p>
    </div>
    <script src="' . $baseUrl . '/assets/js/fingerprint.js" defer></script>
    <script>
        setTimeout(function() {
            window.location.href = "' . addslashes($url) . '";
        }, ' . ($delay * 1000) . ');
    </script>
</body>
</html>';
}