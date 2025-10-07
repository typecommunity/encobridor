<?php
/**
 * Cloaker Pro - AJAX Save Rule
 * Handler para salvar regras via AJAX
 */

require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';
require_once '../../core/Rules.php';
require_once '../../core/Utils.php';

// Verificar se é requisição AJAX
if (!Utils::isAjax()) {
    Utils::jsonResponse(['error' => 'Invalid request'], 400);
}

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    Utils::jsonResponse(['error' => 'Unauthorized'], 401);
}

// Verificar permissão
if (!$auth->hasPermission('manage_rules')) {
    Utils::jsonResponse(['error' => 'Permission denied'], 403);
}

// Verificar método POST
if (!Utils::isPost()) {
    Utils::jsonResponse(['error' => 'Method not allowed'], 405);
}

// Verificar CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!Utils::verifyCSRFToken($csrfToken)) {
    Utils::jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

try {
    $rules = new Rules();
    
    // Obter dados do POST
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    $data = [
        'name' => Utils::sanitize($_POST['name'] ?? ''),
        'type' => $_POST['type'] ?? '',
        'condition' => $_POST['condition'] ?? 'equals',
        'value' => $_POST['value'] ?? '',
        'action' => $_POST['action'] ?? 'safe',
        'priority' => (int)($_POST['priority'] ?? 0),
        'status' => $_POST['status'] ?? 'active',
        'description' => Utils::sanitize($_POST['description'] ?? ''),
        'apply_to' => $_POST['apply_to'] ?? 'all',
        'campaigns' => $_POST['campaigns'] ?? []
    ];
    
    // Validações
    if (empty($data['name'])) {
        Utils::jsonResponse(['error' => 'Nome da regra é obrigatório'], 400);
    }
    
    $validTypes = ['geo', 'device', 'browser', 'os', 'ip', 'referrer', 'language', 
                   'isp', 'bot', 'vpn', 'time', 'url_param', 'cookie', 'header'];
    
    if (!in_array($data['type'], $validTypes)) {
        Utils::jsonResponse(['error' => 'Tipo de regra inválido'], 400);
    }
    
    $validConditions = ['equals', 'not_equals', 'contains', 'not_contains', 
                       'starts_with', 'ends_with', 'matches', 'greater_than', 
                       'less_than', 'between', 'in_list'];
    
    if (!in_array($data['condition'], $validConditions)) {
        Utils::jsonResponse(['error' => 'Condição inválida'], 400);
    }
    
    if (empty($data['value'])) {
        Utils::jsonResponse(['error' => 'Valor da regra é obrigatório'], 400);
    }
    
    // Processar valores especiais baseado no tipo
    switch ($data['type']) {
        case 'geo':
            // Validar códigos de país
            $countries = array_map('trim', explode(',', $data['value']));
            foreach ($countries as $country) {
                if (!preg_match('/^[A-Z]{2}$/', $country)) {
                    Utils::jsonResponse(['error' => 'Código de país inválido: ' . $country], 400);
                }
            }
            break;
            
        case 'ip':
            // Validar IPs ou ranges
            $ips = array_map('trim', explode(',', $data['value']));
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $ip)) {
                    Utils::jsonResponse(['error' => 'IP ou range inválido: ' . $ip], 400);
                }
            }
            break;
            
        case 'time':
            // Validar formato de hora
            if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $data['value'])) {
                Utils::jsonResponse(['error' => 'Formato de horário inválido. Use HH:MM-HH:MM'], 400);
            }
            break;
            
        case 'bot':
        case 'vpn':
            // Para detecção booleana, aceitar true/false
            if (!in_array(strtolower($data['value']), ['true', 'false', '1', '0', 'yes', 'no'])) {
                Utils::jsonResponse(['error' => 'Valor deve ser true ou false'], 400);
            }
            $data['value'] = in_array(strtolower($data['value']), ['true', '1', 'yes']) ? 'true' : 'false';
            break;
            
        case 'device':
            // Validar tipos de dispositivo
            $validDevices = ['mobile', 'tablet', 'desktop', 'tv', 'bot'];
            $devices = array_map('trim', explode(',', strtolower($data['value'])));
            foreach ($devices as $device) {
                if (!in_array($device, $validDevices)) {
                    Utils::jsonResponse(['error' => 'Tipo de dispositivo inválido: ' . $device], 400);
                }
            }
            break;
            
        case 'browser':
            // Validar navegadores
            $validBrowsers = ['chrome', 'firefox', 'safari', 'edge', 'opera', 'ie'];
            $browsers = array_map('trim', explode(',', strtolower($data['value'])));
            foreach ($browsers as $browser) {
                if (!in_array($browser, $validBrowsers)) {
                    Utils::jsonResponse(['error' => 'Navegador inválido: ' . $browser], 400);
                }
            }
            break;
            
        case 'os':
            // Validar sistemas operacionais
            $validOS = ['windows', 'macos', 'linux', 'android', 'ios'];
            $systems = array_map('trim', explode(',', strtolower($data['value'])));
            foreach ($systems as $os) {
                if (!in_array($os, $validOS)) {
                    Utils::jsonResponse(['error' => 'Sistema operacional inválido: ' . $os], 400);
                }
            }
            break;
    }
    
    // Preparar dados para salvar
    $ruleData = [
        'name' => $data['name'],
        'type' => $data['type'],
        'condition' => $data['condition'],
        'value' => $data['value'],
        'action' => $data['action'],
        'priority' => $data['priority'],
        'status' => $data['status'],
        'description' => $data['description'],
        'apply_to' => $data['apply_to'],
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Se aplicar apenas a campanhas selecionadas
    if ($data['apply_to'] === 'selected' && !empty($data['campaigns'])) {
        $ruleData['campaigns'] = json_encode($data['campaigns']);
    } else {
        $ruleData['campaigns'] = null;
    }
    
    // Salvar ou atualizar regra
    if ($id) {
        // Verificar se a regra existe
        $existingRule = $rules->get($id);
        if (!$existingRule) {
            Utils::jsonResponse(['error' => 'Regra não encontrada'], 404);
        }
        
        // Atualizar
        $success = $rules->update($id, $ruleData);
        $message = 'Regra atualizada com sucesso!';
    } else {
        // Criar nova
        $ruleData['created_at'] = date('Y-m-d H:i:s');
        $id = $rules->create($ruleData);
        $success = $id !== false;
        $message = 'Regra criada com sucesso!';
    }
    
    if ($success) {
        // Log da ação
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, data, created_at)
            VALUES (?, ?, 'rule', ?, ?, NOW())
        ");
        $stmt->execute([
            $auth->getCurrentUser()['id'],
            $id ? 'update' : 'create',
            $id,
            json_encode($ruleData)
        ]);
        
        // Limpar cache de regras
        Utils::clearCache(dirname(dirname(__DIR__)) . '/storage/cache/rules');
        
        Utils::jsonResponse([
            'success' => true,
            'message' => $message,
            'rule_id' => $id
        ]);
    } else {
        Utils::jsonResponse(['error' => 'Erro ao salvar regra'], 500);
    }
    
} catch (Exception $e) {
    Utils::logError('Error saving rule: ' . $e->getMessage());
    Utils::jsonResponse(['error' => 'Erro interno ao salvar regra'], 500);
}