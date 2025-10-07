<?php
/**
 * Cloaker Pro - AJAX Save Campaign
 * Handler para salvar campanhas via AJAX
 */

require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';
require_once '../../core/Campaign.php';
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
    $campaign = new Campaign();
    
    // Obter dados do POST
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    $data = [
        'name' => Utils::sanitize($_POST['name'] ?? ''),
        'slug' => Utils::sanitize($_POST['slug'] ?? '', 'alphanumeric'),
        'mode' => $_POST['mode'] ?? 'redirect',
        'safe_page' => Utils::sanitize($_POST['safe_page'] ?? '', 'url'),
        'money_page' => Utils::sanitize($_POST['money_page'] ?? '', 'url'),
        'status' => $_POST['status'] ?? 'draft',
        'rules' => [],
        'settings' => []
    ];
    
    // Validações
    if (empty($data['name'])) {
        Utils::jsonResponse(['error' => 'Nome da campanha é obrigatório'], 400);
    }
    
    if (empty($data['safe_page']) || empty($data['money_page'])) {
        Utils::jsonResponse(['error' => 'URLs das páginas são obrigatórias'], 400);
    }
    
    if (!Utils::validate($data['safe_page'], 'url') || !Utils::validate($data['money_page'], 'url')) {
        Utils::jsonResponse(['error' => 'URLs inválidas'], 400);
    }
    
    // Gerar slug se não fornecido
    if (empty($data['slug'])) {
        $data['slug'] = Utils::generateSlug($data['name']) . '-' . Utils::generateRandomString(6);
    }
    
    // Processar regras
    if (isset($_POST['rules']) && is_array($_POST['rules'])) {
        foreach ($_POST['rules'] as $rule) {
            $data['rules'][] = [
                'type' => $rule['type'] ?? '',
                'condition' => $rule['condition'] ?? 'equals',
                'value' => $rule['value'] ?? '',
                'action' => $rule['action'] ?? 'safe'
            ];
        }
    }
    
    // Processar configurações
    $data['settings'] = [
        'enable_ab_testing' => isset($_POST['enable_ab_testing']),
        'ab_percentage' => (int)($_POST['ab_percentage'] ?? 50),
        'enable_pixel' => isset($_POST['enable_pixel']),
        'pixel_code' => $_POST['pixel_code'] ?? '',
        'enable_redirect_delay' => isset($_POST['enable_redirect_delay']),
        'redirect_delay' => (int)($_POST['redirect_delay'] ?? 0),
        'enable_referrer_check' => isset($_POST['enable_referrer_check']),
        'allowed_referrers' => $_POST['allowed_referrers'] ?? '',
        'enable_geo_targeting' => isset($_POST['enable_geo_targeting']),
        'allowed_countries' => $_POST['allowed_countries'] ?? [],
        'blocked_countries' => $_POST['blocked_countries'] ?? [],
        'enable_device_targeting' => isset($_POST['enable_device_targeting']),
        'allowed_devices' => $_POST['allowed_devices'] ?? [],
        'enable_time_targeting' => isset($_POST['enable_time_targeting']),
        'time_start' => $_POST['time_start'] ?? '',
        'time_end' => $_POST['time_end'] ?? '',
        'timezone' => $_POST['timezone'] ?? 'America/Sao_Paulo'
    ];
    
    // Salvar ou atualizar campanha
    if ($id) {
        // Verificar se usuário tem permissão para editar
        $existingCampaign = $campaign->get($id);
        if (!$existingCampaign) {
            Utils::jsonResponse(['error' => 'Campanha não encontrada'], 404);
        }
        
        // Atualizar
        $success = $campaign->update($id, $data);
        $message = 'Campanha atualizada com sucesso!';
    } else {
        // Criar nova
        $id = $campaign->create($data);
        $success = $id !== false;
        $message = 'Campanha criada com sucesso!';
    }
    
    if ($success) {
        Utils::jsonResponse([
            'success' => true,
            'message' => $message,
            'campaign_id' => $id,
            'redirect' => 'campaigns.php'
        ]);
    } else {
        Utils::jsonResponse(['error' => 'Erro ao salvar campanha'], 500);
    }
    
} catch (Exception $e) {
    Utils::logError('Error saving campaign: ' . $e->getMessage());
    Utils::jsonResponse(['error' => 'Erro interno ao salvar campanha'], 500);
}