<?php
/**
 * admin/fingerprint-details.php
 * Versão simplificada e funcional
 */

// Configuração básica
error_reporting(0); // Desabilitar erros para não quebrar JSON
header('Content-Type: application/json; charset=UTF-8');

// Resposta padrão
$response = [
    'error' => null,
    'fingerprint' => null
];

try {
    // Validar ID
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        $response['error'] = 'ID inválido';
        echo json_encode($response);
        exit;
    }
    
    // Incluir config e Database
    $basePath = dirname(__DIR__);
    
    // Incluir config
    if (!file_exists($basePath . '/config.php')) {
        $response['error'] = 'Arquivo config.php não encontrado';
        echo json_encode($response);
        exit;
    }
    require_once $basePath . '/config.php';
    
    // Incluir Database
    if (!file_exists($basePath . '/core/Database.php')) {
        $response['error'] = 'Arquivo Database.php não encontrado';
        echo json_encode($response);
        exit;
    }
    require_once $basePath . '/core/Database.php';
    
    // SKIP AUTH FOR NOW - Comentar esta seção se quiser pular autenticação
    /*
    if (file_exists($basePath . '/core/Auth.php')) {
        require_once $basePath . '/core/Auth.php';
        $auth = new Auth();
        if (!$auth->isLoggedIn()) {
            $response['error'] = 'Não autenticado';
            echo json_encode($response);
            exit;
        }
    }
    */
    
    // Conectar ao banco usando PDO direto do config
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Buscar fingerprint
    $stmt = $pdo->prepare("SELECT * FROM fingerprints WHERE id = ?");
    $stmt->execute([$id]);
    $fingerprint = $stmt->fetch();
    
    if (!$fingerprint) {
        $response['error'] = 'Fingerprint não encontrado';
        echo json_encode($response);
        exit;
    }
    
    // Preparar resposta
    $response['fingerprint'] = $fingerprint;
    
    // Adicionar análise básica
    $trustScore = isset($fingerprint['trust_score']) ? intval($fingerprint['trust_score']) : 50;
    
    $response['analysis'] = [
        'risk_level' => $trustScore <= 30 ? 'high' : ($trustScore <= 60 ? 'medium' : 'low'),
        'trust_level' => $trustScore <= 30 ? 'low' : ($trustScore <= 60 ? 'medium' : 'high'),
        'flags' => []
    ];
    
    // Adicionar flags
    if (isset($fingerprint['is_suspicious']) && $fingerprint['is_suspicious'] == 1) {
        $response['analysis']['flags'][] = 'suspicious';
    }
    if (isset($fingerprint['is_verified']) && $fingerprint['is_verified'] == 1) {
        $response['analysis']['flags'][] = 'verified';
    }
    
    // Formatar datas
    if (!empty($fingerprint['first_seen'])) {
        $response['fingerprint']['first_seen_formatted'] = date('d/m/Y H:i:s', strtotime($fingerprint['first_seen']));
    }
    if (!empty($fingerprint['last_seen'])) {
        $response['fingerprint']['last_seen_formatted'] = date('d/m/Y H:i:s', strtotime($fingerprint['last_seen']));
    }
    
    // Sucesso
    echo json_encode($response);
    
} catch (PDOException $e) {
    $response['error'] = 'Erro no banco de dados';
    $response['debug'] = $e->getMessage(); // Remover em produção
    echo json_encode($response);
    
} catch (Exception $e) {
    $response['error'] = 'Erro geral';
    $response['debug'] = $e->getMessage(); // Remover em produção
    echo json_encode($response);
}