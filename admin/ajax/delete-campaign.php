<?php
/**
 * Cloaker Pro - AJAX Delete Campaign
 * Handler para deletar campanhas via AJAX
 */

require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';
require_once '../../core/Campaign.php';
require_once '../../core/Analytics.php';
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
if (!$auth->hasPermission('delete_campaigns')) {
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
    // Obter ID da campanha
    $campaignId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if (!$campaignId) {
        Utils::jsonResponse(['error' => 'Campaign ID is required'], 400);
    }
    
    $campaign = new Campaign();
    $analytics = new Analytics();
    
    // Verificar se a campanha existe
    $camp = $campaign->get($campaignId);
    if (!$camp) {
        Utils::jsonResponse(['error' => 'Campaign not found'], 404);
    }
    
    // Opções de exclusão
    $deleteStats = isset($_POST['delete_stats']) && $_POST['delete_stats'] === 'true';
    $createBackup = isset($_POST['create_backup']) && $_POST['create_backup'] === 'true';
    
    // Criar backup se solicitado
    if ($createBackup) {
        $backupData = [
            'campaign' => $camp,
            'stats' => $analytics->getCampaignStats($campaignId, 'all'),
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $auth->getCurrentUser()['username']
        ];
        
        // Salvar backup
        $backupFile = dirname(dirname(__DIR__)) . '/storage/backups/campaign_' . $campaignId . '_' . time() . '.json';
        @mkdir(dirname($backupFile), 0755, true);
        file_put_contents($backupFile, json_encode($backupData, JSON_PRETTY_PRINT));
    }
    
    // Deletar estatísticas se solicitado
    if ($deleteStats) {
        $analytics->deleteCampaignStats($campaignId);
    }
    
    // Deletar campanha
    $success = $campaign->delete($campaignId);
    
    if ($success) {
        // Log da ação
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, data, created_at)
            VALUES (?, 'delete', 'campaign', ?, ?, NOW())
        ");
        $stmt->execute([
            $auth->getCurrentUser()['id'],
            $campaignId,
            json_encode([
                'campaign_name' => $camp['name'],
                'delete_stats' => $deleteStats,
                'backup_created' => $createBackup
            ])
        ]);
        
        Utils::jsonResponse([
            'success' => true,
            'message' => 'Campanha excluída com sucesso!',
            'backup_created' => $createBackup
        ]);
    } else {
        Utils::jsonResponse(['error' => 'Erro ao excluir campanha'], 500);
    }
    
} catch (Exception $e) {
    Utils::logError('Error deleting campaign: ' . $e->getMessage());
    Utils::jsonResponse(['error' => 'Erro interno ao excluir campanha'], 500);
}