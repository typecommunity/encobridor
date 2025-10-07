<?php
/**
 * ============================================
 * CLOAKER PRO - LICENSE CONFIGURATION PAGE v2.0
 * ============================================
 * 
 * VERSÃO COM:
 * - Validação obrigatória antes de salvar
 * - Detecção de bloqueio por webhook
 * - Botão de revalidação forçada
 * - Status visual da licença
 * ============================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $auth->getCurrentUser();
$config_file = __DIR__ . '/../config/license.json';
$config_dir = __DIR__ . '/../config';
$cache_dir = __DIR__ . '/../cache';
$cache_file = $cache_dir . '/license_validation.json';
$env_file = __DIR__ . '/../.env';

// Verificar se foi bloqueado por webhook
$blocked_by_webhook = $_SESSION['license_blocked_by_webhook'] ?? false;
$license_error = $_SESSION['license_error'] ?? null;

// Verificar status atual da licença
$current_status = 'unknown';
$cache_info = null;
if (file_exists($cache_file)) {
    $cache_info = json_decode(file_get_contents($cache_file), true);
    if ($cache_info) {
        $current_status = $cache_info['valid'] ? 'active' : 'blocked';
    }
}

// Função para criar diretório
function createDirectory($path, &$error) {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0755, true)) {
            $error = "Não foi possível criar a pasta: {$path}";
            return false;
        }
    }
    if (!is_writable($path)) {
        $error = "A pasta {$path} não tem permissão de escrita";
        return false;
    }
    return true;
}

// Função para validar licença na API
function validateLicenseWithAPI($license_key, $domain, $email, &$error_message) {
    // Carregar .env
    $env_file = __DIR__ . '/../.env';
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
    
    $webhook_secret = getenv('CLOAKER_WEBHOOK_SECRET') ?: getenv('AUTOSTACKER_WEBHOOK_SECRET');
    $api_url = getenv('AUTOSTACKER_API_URL');
    
    if (!$webhook_secret || !$api_url) {
        $error_message = "Sistema de licença não configurado no .env";
        return false;
    }
    
    // Gerar assinatura
    $payload = $license_key . '|' . $domain . '|' . $email;
    $signature = hash_hmac('sha256', $payload, $webhook_secret);
    
    // Preparar dados
    $data = [
        'license_key' => $license_key,
        'domain' => $domain,
        'email' => $email,
        'signature' => $signature
    ];
    
    // Fazer requisição
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        $error_message = "Erro de conexão: {$curl_error}";
        return false;
    }
    
    if ($http_code !== 200) {
        $result = json_decode($response, true);
        $error_message = $result['error'] ?? "Erro na validação (HTTP {$http_code})";
        return false;
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !isset($result['success']) || $result['success'] !== true) {
        $error_message = $result['error'] ?? "Licença inválida";
        return false;
    }
    
    return true;
}

// Criar pastas necessárias
$setup_error = null;
if (!createDirectory($config_dir, $setup_error) || !createDirectory($cache_dir, $setup_error)) {
    $error = $setup_error;
}

// Carregar configuração existente
$current_config = [];
if (file_exists($config_file)) {
    $json_content = @file_get_contents($config_file);
    if ($json_content !== false) {
        $current_config = json_decode($json_content, true) ?? [];
    }
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Forçar revalidação
    if ($_POST['action'] === 'force_revalidation') {
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
        // Limpar variáveis de sessão
        unset($_SESSION['license_error']);
        unset($_SESSION['license_blocked_by_webhook']);
        
        $success = 'Cache limpo! Redirecionando para revalidar...';
        header("Location: index.php");
        exit;
    }
    
    // Salvar licença
    if ($_POST['action'] === 'save_license') {
        $license_key = trim($_POST['license_key']);
        $domain = trim($_POST['domain']);
        $email = trim($_POST['email']);
        
        // Validações básicas
        if (empty($license_key) || empty($domain) || empty($email)) {
            $error = "Todos os campos são obrigatórios";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Formato de email inválido";
        } elseif (!preg_match('/^CLP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $license_key)) {
            $error = "Formato de chave de licença inválido. Use: CLP-XXXX-XXXX-XXXX-XXXX";
        } else {
            // VALIDAÇÃO OBRIGATÓRIA: Verificar se a licença existe e está ativa no AutoStacker
            $validation_error = '';
            if (!validateLicenseWithAPI($license_key, $domain, $email, $validation_error)) {
                $error = "Licença inválida ou não encontrada no AutoStacker: {$validation_error}";
            } else {
                // Licença válida - pode salvar
                $config = [
                    'license_key' => $license_key,
                    'domain' => $domain,
                    'email' => $email,
                    'configured_at' => date('Y-m-d H:i:s'),
                    'configured_by' => $user['username'] ?? 'admin'
                ];
                
                $json_content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                
                if ($json_content === false) {
                    $error = "Erro ao gerar JSON: " . json_last_error_msg();
                } else {
                    $bytes_written = @file_put_contents($config_file, $json_content);
                    
                    if ($bytes_written === false) {
                        $error = "Erro ao salvar arquivo de configuração";
                    } else {
                        // Limpar cache e sessão
                        if (file_exists($cache_file)) {
                            @unlink($cache_file);
                        }
                        unset($_SESSION['license_error']);
                        unset($_SESSION['license_blocked_by_webhook']);
                        
                        $success = "Licença validada e configurada com sucesso!";
                        $current_config = $config;
                        
                        // Atualizar status
                        $current_status = 'active';
                    }
                }
            }
        }
    }
    
    // Testar licença
    if ($_POST['action'] === 'test_license') {
        if (!$current_config) {
            $test_error = "Nenhuma licença configurada ainda";
        } else {
            $validation_error = '';
            if (validateLicenseWithAPI(
                $current_config['license_key'], 
                $current_config['domain'], 
                $current_config['email'], 
                $validation_error
            )) {
                $test_success = "✅ Licença válida e ativa no AutoStacker!";
                
                // Atualizar cache com sucesso
                @file_put_contents($cache_file, json_encode([
                    'valid' => true,
                    'timestamp' => time(),
                    'license' => []
                ]));
                
                $current_status = 'active';
            } else {
                $test_error = "❌ " . $validation_error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licença - Cloaker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: { DEFAULT: '#000000', secondary: '#0a0a0a', tertiary: '#141414', card: '#1a1a1a', hover: '#242424' },
                        silver: { DEFAULT: '#c0c0c0', light: '#e8e8e8', dark: '#808080', muted: '#a8a8a8' },
                        accent: { success: '#4ade80', danger: '#f87171', warning: '#fbbf24', info: '#60a5fa' }
                    },
                    backgroundImage: { 'gradient-silver': 'linear-gradient(135deg, #c0c0c0 0%, #808080 100%)' },
                    boxShadow: { 'glow': '0 0 20px rgba(192, 192, 192, 0.15)', 'glow-lg': '0 0 30px rgba(192, 192, 192, 0.25)' }
                }
            }
        }
    </script>
    
    <style>
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        .pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Configuração de Licença';
        $pageInfo = '<div class="flex items-center gap-3">
                        <div class="px-4 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg">
                            <i class="fas fa-key text-silver-dark mr-2"></i>
                            <span class="text-silver text-sm font-medium">Gestão de Licença</span>
                        </div>
                    </div>';
        require_once 'header.php'; 
        ?>

        <main class="p-8 flex-1">
            
            <!-- ========================================== -->
            <!-- ALERTA CRÍTICO: BLOQUEIO POR WEBHOOK -->
            <!-- ========================================== -->
            <?php if ($blocked_by_webhook && $license_error): ?>
            <div class="bg-red-500/20 border-2 border-red-500 text-red-500 rounded-xl p-6 mb-6 pulse fade-in">
                <div class="flex items-start gap-4">
                    <i class="fas fa-exclamation-triangle text-4xl"></i>
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold mb-3">🚫 SISTEMA BLOQUEADO</h3>
                        <p class="text-lg mb-3 font-semibold"><?= htmlspecialchars($license_error) ?></p>
                        <div class="bg-red-900/30 rounded-lg p-4 mb-4">
                            <p class="text-sm text-red-300 mb-2">
                                <strong>Sua licença foi bloqueada no AutoStacker.</strong>
                            </p>
                            <p class="text-sm text-red-300">
                                Possíveis motivos: Pagamento cancelado, suspensão manual, ou violação de termos.
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <a href="https://autostacker.app/minha-conta" target="_blank" 
                               class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                                <i class="fas fa-external-link-alt"></i>
                                Verificar no AutoStacker
                            </a>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="force_revalidation">
                                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-red-800 hover:bg-red-900 text-white rounded-lg transition-colors">
                                    <i class="fas fa-sync-alt"></i>
                                    Revalidar Agora
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Mensagens normais -->
            <?php if (isset($success)): ?>
            <div class="bg-accent-success/10 border border-accent-success/30 text-accent-success rounded-xl p-4 mb-6 fade-in flex items-center gap-3">
                <i class="fas fa-check-circle text-2xl"></i>
                <div>
                    <div class="font-semibold mb-1">Sucesso!</div>
                    <div class="text-sm"><?= htmlspecialchars($success) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-accent-danger/10 border border-accent-danger/30 text-accent-danger rounded-xl p-4 mb-6 fade-in flex items-center gap-3">
                <i class="fas fa-exclamation-circle text-2xl"></i>
                <div>
                    <div class="font-semibold mb-1">Erro na validação</div>
                    <div class="text-sm"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($test_success)): ?>
            <div class="bg-accent-success/10 border border-accent-success/30 text-accent-success rounded-xl p-4 mb-6 fade-in flex items-center gap-3">
                <i class="fas fa-check-circle text-2xl"></i>
                <div>
                    <div class="font-semibold mb-1">Teste bem-sucedido!</div>
                    <div class="text-sm"><?= htmlspecialchars($test_success) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($test_error)): ?>
            <div class="bg-accent-danger/10 border border-accent-danger/30 text-accent-danger rounded-xl p-4 mb-6 fade-in flex items-center gap-3">
                <i class="fas fa-times-circle text-2xl"></i>
                <div>
                    <div class="font-semibold mb-1">Erro no teste</div>
                    <div class="text-sm"><?= htmlspecialchars($test_error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- STATUS DA LICENÇA -->
            <!-- ========================================== -->
            <?php if (!empty($current_config)): ?>
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in mb-6">
                <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary flex items-center justify-between">
                    <h2 class="text-lg font-bold text-silver flex items-center gap-2">
                        <i class="fas fa-info-circle text-silver"></i>
                        Status da Licença
                    </h2>
                    <?php if ($current_status === 'active'): ?>
                    <span class="px-4 py-2 bg-green-500/20 text-green-500 rounded-full text-sm font-bold">
                        <i class="fas fa-check-circle"></i> ATIVA
                    </span>
                    <?php elseif ($current_status === 'blocked'): ?>
                    <span class="px-4 py-2 bg-red-500/20 text-red-500 rounded-full text-sm font-bold pulse">
                        <i class="fas fa-times-circle"></i> BLOQUEADA
                    </span>
                    <?php else: ?>
                    <span class="px-4 py-2 bg-yellow-500/20 text-yellow-500 rounded-full text-sm font-bold">
                        <i class="fas fa-question-circle"></i> DESCONHECIDO
                    </span>
                    <?php endif; ?>
                </div>
                <div class="p-6">
                    <div class="space-y-3 mb-6">
                        <div class="flex justify-between items-center py-3 border-b border-[#2a2a2a]">
                            <span class="text-silver-dark">Configurada em:</span>
                            <span class="text-silver font-semibold"><?= htmlspecialchars($current_config['configured_at'] ?? 'N/A') ?></span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b border-[#2a2a2a]">
                            <span class="text-silver-dark">Chave:</span>
                            <span class="text-silver font-mono text-sm"><?= htmlspecialchars($current_config['license_key'] ?? 'N/A') ?></span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b border-[#2a2a2a]">
                            <span class="text-silver-dark">Domínio:</span>
                            <span class="text-silver font-semibold"><?= htmlspecialchars($current_config['domain'] ?? 'N/A') ?></span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b border-[#2a2a2a]">
                            <span class="text-silver-dark">Email:</span>
                            <span class="text-silver font-semibold"><?= htmlspecialchars($current_config['email'] ?? 'N/A') ?></span>
                        </div>
                        <?php if ($cache_info && isset($cache_info['timestamp'])): ?>
                        <div class="flex justify-between items-center py-3">
                            <span class="text-silver-dark">Última verificação:</span>
                            <span class="text-silver text-sm"><?= date('d/m/Y H:i:s', $cache_info['timestamp']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <form method="POST">
                            <input type="hidden" name="action" value="test_license">
                            <button type="submit" class="w-full bg-dark-tertiary hover:bg-dark-hover border border-[#2a2a2a] hover:border-accent-info text-silver hover:text-accent-info px-6 py-3 rounded-lg transition-all">
                                <i class="fas fa-vial mr-2"></i>Testar Licença
                            </button>
                        </form>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="force_revalidation">
                            <button type="submit" class="w-full bg-dark-tertiary hover:bg-dark-hover border border-[#2a2a2a] hover:border-accent-warning text-silver hover:text-accent-warning px-6 py-3 rounded-lg transition-all">
                                <i class="fas fa-sync-alt mr-2"></i>Forçar Revalidação
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info sobre validação -->
            <div class="bg-accent-info/10 border-l-4 border-accent-info rounded-xl p-6 mb-6 fade-in">
                <h4 class="text-accent-info font-bold text-lg mb-4 flex items-center gap-2">
                    <i class="fas fa-shield-alt"></i>
                    Validação Obrigatória de Licença
                </h4>
                <p class="text-silver-light mb-3">
                    O sistema valida a licença no AutoStacker ANTES de salvar. Apenas licenças válidas e ativas serão aceitas.
                </p>
                <ol class="space-y-2 text-silver-light ml-6 list-decimal">
                    <li>Acesse: <a href="https://autostacker.app/login/cloaker/" target="_blank" class="text-accent-info hover:text-accent-info/80 underline">AutoStacker - Cloaker</a></li>
                    <li>Gere uma nova licença para o domínio: <strong><?= $_SERVER['HTTP_HOST'] ?></strong></li>
                    <li>Copie a chave de licença gerada</li>
                    <li>Cole nos campos abaixo - o sistema validará automaticamente</li>
                </ol>
            </div>

            <!-- Formulário de configuração -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in mb-6">
                <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary">
                    <h2 class="text-lg font-bold text-silver flex items-center gap-2">
                        <i class="fas fa-key text-silver"></i>
                        <?= empty($current_config) ? 'Configurar' : 'Atualizar' ?> Licença
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="save_license">
                        
                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Chave de Licença *
                            </label>
                            <input type="text" name="license_key" 
                                   placeholder="CLP-XXXX-XXXX-XXXX-XXXX" 
                                   value="<?= htmlspecialchars($current_config['license_key'] ?? '') ?>"
                                   pattern="CLP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver font-mono"
                                   required>
                            <p class="text-silver-dark text-sm mt-2">Será validada no AutoStacker antes de salvar</p>
                        </div>

                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Domínio *
                            </label>
                            <input type="text" name="domain" 
                                   placeholder="exemplo.com" 
                                   value="<?= htmlspecialchars($current_config['domain'] ?? $_SERVER['HTTP_HOST']) ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver"
                                   required>
                            <p class="text-silver-dark text-sm mt-2">Deve corresponder ao domínio cadastrado no AutoStacker</p>
                        </div>

                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                Email do Administrador *
                            </label>
                            <input type="email" name="email" 
                                   placeholder="admin@exemplo.com" 
                                   value="<?= htmlspecialchars($current_config['email'] ?? '') ?>"
                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver"
                                   required>
                            <p class="text-silver-dark text-sm mt-2">Deve corresponder ao email cadastrado no AutoStacker</p>
                        </div>

                        <button type="submit" class="bg-gradient-silver hover:shadow-glow text-dark font-bold px-6 py-3 rounded-lg transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-shield-check mr-2"></i>Validar e Salvar Licença
                        </button>
                    </form>
                </div>
            </div>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>
</body>
</html>