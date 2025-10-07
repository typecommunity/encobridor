<?php
/**
 * Cloaker Pro - Fingerprint API Endpoint
 * Recebe e armazena fingerprints do cliente
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Responder OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Utils.php';

try {
    $db = Database::getInstance();
    
    // Ler JSON
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['fingerprint'])) {
        throw new Exception('Invalid fingerprint data');
    }
    
    $fingerprint = $data['fingerprint'];
    $fpData = $data['data'] ?? [];
    $isUpdate = $data['update'] ?? false;
    
    // Informações básicas
    $ip = Utils::getRealIP();
    $userAgent = Utils::getUserAgent();
    
    // Verificar se já existe
    $stmt = $db->prepare("
        SELECT id, visit_count 
        FROM fingerprints 
        WHERE fingerprint_hash = ? 
        ORDER BY last_seen DESC 
        LIMIT 1
    ");
    $stmt->execute([$fingerprint]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Atualizar existente
        $stmt = $db->prepare("
            UPDATE fingerprints 
            SET last_seen = NOW(),
                visit_count = visit_count + 1,
                mouse_movements = ?,
                clicks = ?,
                key_presses = ?,
                scrolls = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['behavior']['mouseMovements'] ?? 0,
            $data['behavior']['clicks'] ?? 0,
            $data['behavior']['keyPresses'] ?? 0,
            $data['behavior']['scrolls'] ?? 0,
            $existing['id']
        ]);
        
        $response = [
            'success' => true,
            'id' => $existing['id'],
            'action' => 'updated',
            'visit_count' => $existing['visit_count'] + 1
        ];
    } else {
        // Inserir novo
        $visitorId = bin2hex(random_bytes(16));
        
        $stmt = $db->prepare("
            INSERT INTO fingerprints (
                visitor_id,
                fingerprint_hash,
                ip_address,
                user_agent,
                screen_width,
                screen_height,
                color_depth,
                pixel_ratio,
                viewport_width,
                viewport_height,
                timezone_offset,
                timezone_name,
                platform,
                language,
                hardware_concurrency,
                device_memory,
                cookies_enabled,
                canvas_hash,
                webgl_vendor,
                webgl_renderer,
                audio_context,
                touch_support,
                max_touch_points,
                plugins,
                fonts,
                is_suspicious,
                risk_score,
                first_seen,
                last_seen,
                visit_count,
                is_active
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, 1
            )
        ");
        
        $stmt->execute([
            $visitorId,
            $fingerprint,
            $ip,
            $userAgent,
            $fpData['screen']['width'] ?? 0,
            $fpData['screen']['height'] ?? 0,
            $fpData['screen']['colorDepth'] ?? 0,
            $fpData['screen']['pixelRatio'] ?? 1,
            $fpData['viewport']['width'] ?? 0,
            $fpData['viewport']['height'] ?? 0,
            $fpData['timezone']['offset'] ?? 0,
            $fpData['timezone']['name'] ?? '',
            $fpData['platform'] ?? '',
            $fpData['language'] ?? '',
            $fpData['hardwareConcurrency'] ?? 0,
            $fpData['deviceMemory'] ?? 0,
            $fpData['cookies'] ? 1 : 0,
            $fpData['canvas'] ?? '',
            $fpData['webgl']['vendor'] ?? '',
            $fpData['webgl']['renderer'] ?? '',
            !empty($fpData['audio']) && $fpData['audio'] !== 'not_supported' ? 1 : 0,
            !empty($fpData['touchSupport']['touchEvent']) ? 1 : 0,
            $fpData['touchSupport']['maxTouchPoints'] ?? 0,
            $fpData['plugins'] ?? '',
            $fpData['fonts'] ?? '',
            0, // is_suspicious - calcular depois
            0, // risk_score - calcular depois
        ]);
        
        $newId = $db->lastInsertId();
        
        $response = [
            'success' => true,
            'id' => $newId,
            'visitor_id' => $visitorId,
            'action' => 'created',
            'visit_count' => 1
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('Fingerprint API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal error'
    ]);
}