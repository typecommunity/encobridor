<?php
/**
 * ============================================
 * CLOAKER PRO - WEBHOOK DE LICENÇA AUTOSTACKER
 * ============================================
 * 
 * Arquivo: webhook-license.php
 * Caminho: /api/webhook-license.php
 * 
 * RECEBE NOTIFICAÇÕES DO AUTOSTACKER:
 * - license.suspended
 * - license.cancelled
 * - license.expired
 * - license.activated
 * 
 * AÇÕES:
 * - Invalida cache imediatamente
 * - Bloqueia acesso ao sistema
 * ============================================
 */

header('Content-Type: application/json');

// Carregar .env
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

$webhook_secret = getenv('CLOAKER_WEBHOOK_SECRET') ?: getenv('AUTOSTACKER_WEBHOOK_SECRET');

if (!$webhook_secret) {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret not configured']);
    exit;
}

// ==========================================
// 1. LER DADOS DO WEBHOOK
// ==========================================

$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

// ==========================================
// 2. VERIFICAR ASSINATURA HMAC
// ==========================================

$received_signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

if (!hash_equals($expected_signature, $received_signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    
    // Log de tentativa de acesso não autorizado
    $log_file = __DIR__ . '/../storage/logs/webhook-security.log';
    @file_put_contents($log_file, date('Y-m-d H:i:s') . " - Invalid signature attempt from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . PHP_EOL, FILE_APPEND);
    
    exit;
}

// ==========================================
// 3. PROCESSAR EVENTO
// ==========================================

$event = $data['event'] ?? '';
$license_key = $data['license_key'] ?? '';
$domain = $data['domain'] ?? '';

// Log do evento recebido
$log_file = __DIR__ . '/../storage/logs/webhook-license.log';
$log_entry = date('Y-m-d H:i:s') . " - Event: {$event} | License: {$license_key} | Domain: {$domain}" . PHP_EOL;
@file_put_contents($log_file, $log_entry, FILE_APPEND);

// Arquivos críticos
$cache_file = __DIR__ . '/../cache/license_validation.json';
$config_file = __DIR__ . '/../config/license.json';

// ==========================================
// 4. INVALIDAR CACHE IMEDIATAMENTE
// ==========================================

switch ($event) {
    
    case 'license.suspended':
    case 'license.cancelled':
    case 'license.expired':
        // BLOQUEAR: Criar cache inválido
        $block_cache = [
            'valid' => false,
            'timestamp' => time(),
            'error' => 'Licença ' . str_replace('license.', '', $event),
            'blocked_by_webhook' => true,
            'blocked_at' => date('Y-m-d H:i:s')
        ];
        
        @file_put_contents($cache_file, json_encode($block_cache, JSON_PRETTY_PRINT));
        
        // Log
        $log_entry = date('Y-m-d H:i:s') . " - BLOQUEADO: Cache invalidado por evento {$event}" . PHP_EOL;
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'License blocked successfully',
            'action' => 'cache_invalidated'
        ]);
        break;
        
    case 'license.activated':
    case 'license.renewed':
        // DESBLOQUEAR: Remover cache para forçar nova validação
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
        
        // Log
        $log_entry = date('Y-m-d H:i:s') . " - DESBLOQUEADO: Cache removido por evento {$event}" . PHP_EOL;
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'License activated successfully',
            'action' => 'cache_cleared'
        ]);
        break;
        
    default:
        // Evento desconhecido
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Event received but not processed',
            'event' => $event
        ]);
        break;
}

exit;