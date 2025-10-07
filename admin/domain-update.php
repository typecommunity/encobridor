<?php
/**
 * Atualização de Domínio - Handler
 * Processa adição/remoção de domínios personalizados
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Desabilitar para não interferir no JSON
ini_set('log_errors', 1);

// Headers JSON sempre
header('Content-Type: application/json');

// Capturar erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erro interno: ' . $error['message'],
            'debug' => $error
        ]);
    }
});

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../core/Database.php';
    require_once __DIR__ . '/../core/Auth.php';
    require_once __DIR__ . '/../core/TenantManager.php';
    require_once __DIR__ . '/../core/TenantMiddleware.php';
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Erro ao carregar dependências: ' . $e->getMessage()]));
}

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Não autenticado']));
}

// Inicializar multi-tenancy
$db = Database::getInstance();
$tenantManager = new TenantManager($db);
$tenantMiddleware = new TenantMiddleware($tenantManager, $auth);
$tenantMiddleware->handle();

$user = $auth->getCurrentUser();
$isSuperAdmin = $tenantMiddleware->isSuperAdmin();
$tenantId = $tenantMiddleware->getTenantId();

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Método não permitido']));
}

// Obter dados
$action = $_POST['action'] ?? '';
$domain = trim($_POST['domain'] ?? '');
$targetTenantId = (int)($_POST['tenant_id'] ?? $tenantId);

try {
    // Validações de permissão
    if (!$isSuperAdmin && $targetTenantId !== $tenantId) {
        throw new Exception('Sem permissão para modificar este tenant');
    }

    // Processar ação
    switch ($action) {
        case 'set_domain':
            // Validar domínio
            if (empty($domain)) {
                throw new Exception('Domínio não pode estar vazio');
            }

            // Validar formato do domínio
            if (!preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', $domain)) {
                throw new Exception('Formato de domínio inválido. Use apenas letras, números e hífens.');
            }

            // Verificar se domínio já está em uso
            $checkSql = "SELECT id, name FROM tenants WHERE domain = ? AND id != ?";
            $checkResult = $db->raw($checkSql, [$domain, $targetTenantId]);
            $existing = $checkResult ? $checkResult->fetch(PDO::FETCH_ASSOC) : null;

            if ($existing) {
                throw new Exception('Este domínio já está sendo usado por: ' . $existing['name']);
            }

            // Atualizar domínio
            $updateSql = "UPDATE tenants SET domain = ?, updated_at = NOW() WHERE id = ?";
            $db->raw($updateSql, [$domain, $targetTenantId]);

            // Registrar atividade
            $activitySql = "INSERT INTO tenant_activities (tenant_id, user_id, action, description, ip_address, created_at) 
                           VALUES (?, ?, 'domain_updated', ?, ?, NOW())";
            $db->raw($activitySql, [
                $targetTenantId,
                $user['id'],
                "Domínio configurado: {$domain}",
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            $_SESSION['success'] = "Domínio configurado com sucesso: {$domain}";
            echo json_encode([
                'success' => true,
                'message' => 'Domínio configurado com sucesso',
                'domain' => $domain
            ]);
            break;

        case 'remove_domain':
            // Remover domínio
            $updateSql = "UPDATE tenants SET domain = NULL, updated_at = NOW() WHERE id = ?";
            $db->raw($updateSql, [$targetTenantId]);

            // Registrar atividade
            $activitySql = "INSERT INTO tenant_activities (tenant_id, user_id, action, description, ip_address, created_at) 
                           VALUES (?, ?, 'domain_removed', 'Domínio personalizado removido', ?, NOW())";
            $db->raw($activitySql, [
                $targetTenantId,
                $user['id'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            $_SESSION['success'] = 'Domínio removido com sucesso';
            echo json_encode([
                'success' => true,
                'message' => 'Domínio removido com sucesso'
            ]);
            break;

        case 'check_dns':
            // Verificar DNS do domínio
            if (empty($domain)) {
                throw new Exception('Domínio não informado');
            }

            $dnsRecords = @dns_get_record($domain, DNS_A);
            $serverIP = $_SERVER['SERVER_ADDR'] ?? null;

            $dnsConfigured = false;
            $foundIPs = [];

            if ($dnsRecords) {
                foreach ($dnsRecords as $record) {
                    if (isset($record['ip'])) {
                        $foundIPs[] = $record['ip'];
                        if ($record['ip'] === $serverIP) {
                            $dnsConfigured = true;
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'dns_configured' => $dnsConfigured,
                'server_ip' => $serverIP,
                'found_ips' => $foundIPs,
                'message' => $dnsConfigured 
                    ? 'DNS configurado corretamente!' 
                    : 'DNS não está apontando para este servidor'
            ]);
            break;

        default:
            throw new Exception('Ação inválida');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}