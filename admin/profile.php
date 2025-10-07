<?php
/**
 * Cloaker Pro - Profile
 * Gerenciamento de perfil do usuário
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../includes/license_guard.php';
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/License.php';

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

try {
    $user = $auth->getCurrentUser();
    $license = new License();
    $db = Database::getInstance();
    
    if (method_exists($db, 'getConnection')) {
        $conn = $db->getConnection();
    } else {
        $conn = $db;
    }

    $message = '';
    $error = '';
    $activeTab = $_GET['tab'] ?? 'account';

    // Verificar e adicionar coluna display_name se não existir
    try {
        $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'display_name'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL AFTER username");
            error_log("Coluna display_name adicionada com sucesso");
        }
    } catch (Exception $e) {
        error_log("Error checking/adding display_name column: " . $e->getMessage());
    }

    // Processar formulários
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'update_account':
                    $username = trim($_POST['username'] ?? '');
                    $displayName = trim($_POST['display_name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    
                    if (empty($username)) {
                        throw new Exception('Nome de usuário é obrigatório');
                    }
                    
                    if (strlen($username) < 3 || strlen($username) > 50) {
                        throw new Exception('Nome de usuário deve ter entre 3 e 50 caracteres');
                    }
                    
                    // Validar username (apenas letras, números, underscore e hífen)
                    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
                        throw new Exception('Nome de usuário pode conter apenas letras, números, _ e -');
                    }
                    
                    if (!empty($displayName) && strlen($displayName) > 100) {
                        throw new Exception('Nome de exibição deve ter no máximo 100 caracteres');
                    }
                    
                    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('Email inválido');
                    }
                    
                    // Verificar se username já existe
                    $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $checkStmt->execute([$username, $user['id']]);
                    if ($checkStmt->fetch()) {
                        throw new Exception('Nome de usuário já está em uso');
                    }
                    
                    // Verificar se email já existe
                    if (!empty($email)) {
                        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $checkStmt->execute([$email, $user['id']]);
                        if ($checkStmt->fetch()) {
                            throw new Exception('Email já está em uso');
                        }
                    }
                    
                    // Atualizar usuário
                    $stmt = $conn->prepare("
                        UPDATE users 
                        SET username = ?, display_name = ?, email = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $displayName, $email, $user['id']]);
                    
                    // Atualizar sessão
                    $user['username'] = $username;
                    $user['display_name'] = $displayName;
                    $user['email'] = $email;
                    $_SESSION['user'] = $user;
                    
                    $message = 'Dados da conta atualizados com sucesso!';
                    $activeTab = 'account';
                    break;
                    
                case 'change_password':
                    $currentPassword = $_POST['current_password'] ?? '';
                    $newPassword = $_POST['new_password'] ?? '';
                    $confirmPassword = $_POST['confirm_password'] ?? '';
                    
                    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                        throw new Exception('Todos os campos de senha são obrigatórios');
                    }
                    
                    // Verificar senha atual
                    if (!password_verify($currentPassword, $user['password'])) {
                        throw new Exception('Senha atual incorreta');
                    }
                    
                    // Validar nova senha
                    if (strlen($newPassword) < 8) {
                        throw new Exception('Nova senha deve ter no mínimo 8 caracteres');
                    }
                    
                    if ($newPassword !== $confirmPassword) {
                        throw new Exception('Nova senha e confirmação não coincidem');
                    }
                    
                    // Atualizar senha
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$hashedPassword, $user['id']]);
                    
                    $message = 'Senha alterada com sucesso!';
                    $activeTab = 'security';
                    break;
                    
                case 'toggle_2fa':
                    $enable2FA = isset($_POST['enable_2fa']) ? 1 : 0;
                    
                    $stmt = $conn->prepare("UPDATE users SET two_factor_enabled = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$enable2FA, $user['id']]);
                    
                    $user['two_factor_enabled'] = $enable2FA;
                    $_SESSION['user'] = $user;
                    
                    $message = $enable2FA ? '2FA habilitado com sucesso!' : '2FA desabilitado com sucesso!';
                    $activeTab = 'security';
                    break;
                    
                default:
                    throw new Exception('Ação inválida');
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            error_log("Profile action error: " . $e->getMessage());
        }
    }
    
    // Recarregar dados do usuário
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se não tem display_name, usa username
    if (empty($user['display_name'])) {
        $user['display_name'] = $user['username'];
    }
    
    // Estatísticas do usuário
    $activityCount = 0;
    try {
        $activityStmt = $conn->prepare("SELECT COUNT(*) as count FROM user_activities WHERE user_id = ?");
        $activityStmt->execute([$user['id']]);
        $result = $activityStmt->fetch(PDO::FETCH_ASSOC);
        $activityCount = $result ? intval($result['count']) : 0;
    } catch (Exception $e) {
        error_log("Activity count error: " . $e->getMessage());
    }
    
    $licenseInfo = $license->getInfo();

} catch (Exception $e) {
    error_log("Profile critical error: " . $e->getMessage());
    die("Erro ao carregar perfil. Verifique os logs para mais detalhes.");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - Cloaker Pro</title>
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
        @keyframes shine { 0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); } 100% { transform: translateX(100%) translateY(100%) rotate(45deg); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .animate-shine::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent); animation: shine 3s infinite; }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
        
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .input-error { border-color: #f87171 !important; }
        .password-strength { height: 4px; border-radius: 2px; transition: all 0.3s ease; }
        .strength-weak { width: 33%; background-color: #f87171; }
        .strength-medium { width: 66%; background-color: #fbbf24; }
        .strength-strong { width: 100%; background-color: #4ade80; }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Meu Perfil';
        require_once 'header.php'; 
        ?>

        <main class="p-8 flex-1">
            <?php if ($message): ?>
            <div class="bg-accent-success bg-opacity-10 border border-accent-success text-accent-success px-4 py-3 rounded-lg mb-4 fade-in">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="bg-accent-danger bg-opacity-10 border border-accent-danger text-accent-danger px-4 py-3 rounded-lg mb-4 fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in hover:border-silver hover:shadow-glow transition-all">
                <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                    <div class="relative">
                        <div class="w-24 h-24 bg-gradient-silver rounded-full flex items-center justify-center text-dark text-3xl font-bold shadow-glow">
                            <?php echo strtoupper(substr($user['display_name'], 0, 2)); ?>
                        </div>
                        <?php if ($user['two_factor_enabled']): ?>
                        <div class="absolute -bottom-2 -right-2 bg-accent-success text-dark w-8 h-8 rounded-full flex items-center justify-center shadow-lg">
                            <i class="fas fa-shield-alt text-sm"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex-1">
                        <h2 class="text-2xl font-bold text-silver mb-2"><?php echo htmlspecialchars($user['display_name']); ?></h2>
                        <div class="flex flex-wrap items-center gap-4 text-sm text-silver-dark">
                            <span class="flex items-center gap-2">
                                <i class="fas fa-at"></i>
                                @<?php echo htmlspecialchars($user['username']); ?>
                            </span>
                            <?php if (!empty($user['email'])): ?>
                            <span class="flex items-center gap-2">
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($user['email']); ?>
                            </span>
                            <?php endif; ?>
                            <span class="flex items-center gap-2">
                                <i class="fas fa-user-tag"></i>
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                            <span class="flex items-center gap-2">
                                <i class="fas fa-calendar"></i>
                                Membro desde <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="flex gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-silver"><?php echo $activityCount; ?></div>
                            <div class="text-xs text-silver-dark uppercase tracking-wide">Atividades</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-silver"><?php echo $user['status'] === 'active' ? '✓' : '✗'; ?></div>
                            <div class="text-xs text-silver-dark uppercase tracking-wide">Status</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                <div class="border-b border-[#2a2a2a] flex overflow-x-auto bg-dark-tertiary">
                    <button onclick="switchTab('account')" data-tab="account" class="tab-button px-6 py-4 font-semibold whitespace-nowrap transition-all <?php echo $activeTab === 'account' ? 'bg-gradient-silver text-dark' : 'text-silver-dark hover:text-silver hover:bg-dark-hover'; ?>">
                        <i class="fas fa-user mr-2"></i>Conta
                    </button>
                    <button onclick="switchTab('security')" data-tab="security" class="tab-button px-6 py-4 font-semibold whitespace-nowrap transition-all <?php echo $activeTab === 'security' ? 'bg-gradient-silver text-dark' : 'text-silver-dark hover:text-silver hover:bg-dark-hover'; ?>">
                        <i class="fas fa-lock mr-2"></i>Segurança
                    </button>
                    <button onclick="switchTab('activity')" data-tab="activity" class="tab-button px-6 py-4 font-semibold whitespace-nowrap transition-all <?php echo $activeTab === 'activity' ? 'bg-gradient-silver text-dark' : 'text-silver-dark hover:text-silver hover:bg-dark-hover'; ?>">
                        <i class="fas fa-history mr-2"></i>Atividades
                    </button>
                </div>

                <div class="p-6">
                    <div id="account-tab" class="tab-content <?php echo $activeTab === 'account' ? 'active' : ''; ?>">
                        <form method="POST" action="?tab=account">
                            <input type="hidden" name="action" value="update_account">
                            
                            <div class="max-w-2xl space-y-6">
                                <h3 class="text-lg font-bold text-silver mb-6">Informações da Conta</h3>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Nome de Exibição
                                    </label>
                                    <input type="text" name="display_name" 
                                           value="<?php echo htmlspecialchars($user['display_name'] ?? ''); ?>"
                                           maxlength="100"
                                           placeholder="Como você quer ser chamado"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Seu nome público no sistema (pode conter espaços)</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                        Nome de Usuário <span class="text-accent-danger">*</span>
                                    </label>
                                    <input type="text" name="username" required
                                           value="<?php echo htmlspecialchars($user['username']); ?>"
                                           minlength="3" maxlength="50"
                                           pattern="[a-zA-Z0-9_-]+"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Usado para login - apenas letras, números, _ e -</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">Email</label>
                                    <input type="email" name="email"
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                           placeholder="seu@email.com"
                                           class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                    <p class="text-xs text-silver-dark mt-1">Para recuperação de senha</p>
                                </div>
                                
                                <div class="flex items-center gap-3 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                                    <i class="fas fa-info-circle text-accent-info text-xl"></i>
                                    <div class="text-sm text-silver-dark">
                                        <strong class="text-silver">Diferença:</strong> Nome de exibição pode ter espaços (ex: "João Silva"), nome de usuário é único para login (ex: "joao_silva")
                                    </div>
                                </div>
                                
                                <button type="submit" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-3 px-6 rounded-lg transition-all transform hover:scale-105">
                                    <i class="fas fa-save mr-2"></i>Salvar Alterações
                                </button>
                            </div>
                        </form>
                    </div>

                    <div id="security-tab" class="tab-content <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                        <div class="max-w-2xl space-y-8">
                            <div>
                                <h3 class="text-lg font-bold text-silver mb-6">Alterar Senha</h3>
                                
                                <form method="POST" action="?tab=security">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="space-y-6">
                                        <div>
                                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                                Senha Atual <span class="text-accent-danger">*</span>
                                            </label>
                                            <input type="password" name="current_password" required
                                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                                Nova Senha <span class="text-accent-danger">*</span>
                                            </label>
                                            <input type="password" name="new_password" id="new_password" required minlength="8"
                                                   oninput="checkPasswordStrength()"
                                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                            <div class="mt-2 bg-dark-tertiary rounded-full h-1 overflow-hidden">
                                                <div id="password-strength" class="password-strength"></div>
                                            </div>
                                            <p class="text-xs text-silver-dark mt-1" id="strength-text">Mínimo de 8 caracteres</p>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-semibold text-silver-dark mb-2 uppercase tracking-wide">
                                                Confirmar Nova Senha <span class="text-accent-danger">*</span>
                                            </label>
                                            <input type="password" name="confirm_password" required minlength="8"
                                                   class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver transition-all">
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6">
                                        <button type="submit" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-3 px-6 rounded-lg transition-all transform hover:scale-105">
                                            <i class="fas fa-key mr-2"></i>Alterar Senha
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="border-t border-[#2a2a2a] pt-8">
                                <h3 class="text-lg font-bold text-silver mb-6">Autenticação de Dois Fatores (2FA)</h3>
                                
                                <form method="POST" action="?tab=security">
                                    <input type="hidden" name="action" value="toggle_2fa">
                                    
                                    <div class="flex items-start gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                                        <div class="flex-1">
                                            <label class="flex items-center cursor-pointer group">
                                                <input type="checkbox" name="enable_2fa" 
                                                       <?php echo $user['two_factor_enabled'] ? 'checked' : ''; ?>
                                                       onchange="this.form.submit()"
                                                       class="w-5 h-5 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                                                <span class="ml-3 text-sm text-silver-dark group-hover:text-silver transition-colors">
                                                    <strong class="text-silver block">Habilitar 2FA</strong>
                                                    Adiciona segurança extra ao fazer login
                                                </span>
                                            </label>
                                        </div>
                                        <?php if ($user['two_factor_enabled']): ?>
                                        <span class="px-3 py-1 bg-accent-success text-dark text-xs font-bold rounded-full">ATIVO</span>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div id="activity-tab" class="tab-content <?php echo $activeTab === 'activity' ? 'active' : ''; ?>">
                        <h3 class="text-lg font-bold text-silver mb-6">Atividades Recentes</h3>
                        
                        <div class="space-y-3">
                            <?php
                            try {
                                $activityStmt = $conn->prepare("SELECT * FROM user_activities WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
                                $activityStmt->execute([$user['id']]);
                                $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (empty($activities)):
                            ?>
                            <div class="text-center py-12 text-silver-dark">
                                <i class="fas fa-history text-6xl mb-4 opacity-30"></i>
                                <p class="text-lg">Nenhuma atividade registrada</p>
                                <p class="text-sm mt-2">As atividades de login/logout aparecerão aqui</p>
                            </div>
                            <?php else: foreach ($activities as $activity): 
                                $data = json_decode($activity['data'], true);
                                $icon = 'circle';
                                $color = 'text-silver-dark';
                                
                                switch ($activity['action']) {
                                    case 'login':
                                        $icon = 'sign-in-alt';
                                        $color = 'text-accent-success';
                                        break;
                                    case 'logout':
                                        $icon = 'sign-out-alt';
                                        $color = 'text-accent-warning';
                                        break;
                                }
                            ?>
                            <div class="flex items-center gap-4 p-4 bg-dark-tertiary border border-[#2a2a2a] rounded-lg hover:border-silver-dark transition-all">
                                <div class="w-10 h-10 flex items-center justify-center bg-dark-card rounded-full">
                                    <i class="fas fa-<?php echo $icon; ?> <?php echo $color; ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm text-silver font-medium">
                                        <?php echo $activity['action'] === 'login' ? 'Login realizado' : 'Logout realizado'; ?>
                                    </div>
                                    <?php if (isset($data['ip'])): ?>
                                    <div class="text-xs text-silver-dark mt-1">
                                        IP: <?php echo htmlspecialchars($data['ip']); ?>
                                        <?php if (isset($data['user_agent'])): ?>
                                        | <?php echo htmlspecialchars(substr($data['user_agent'], 0, 50)) . '...'; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-silver-dark text-right">
                                    <?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                            <?php } catch (Exception $e) { ?>
                            <div class="text-center py-12 text-silver-dark">
                                <p>Erro ao carregar atividades</p>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(b => {
                b.classList.remove('bg-gradient-silver', 'text-dark');
                b.classList.add('text-silver-dark', 'hover:text-silver', 'hover:bg-dark-hover');
            });
            
            document.getElementById(tab + '-tab').classList.add('active');
            const button = document.querySelector(`[data-tab="${tab}"]`);
            if (button) {
                button.classList.remove('text-silver-dark', 'hover:text-silver', 'hover:bg-dark-hover');
                button.classList.add('bg-gradient-silver', 'text-dark');
            }
            
            history.pushState(null, null, '?tab=' + tab);
        }
        
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('password-strength');
            const strengthText = document.getElementById('strength-text');
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            strengthBar.className = 'password-strength';
            
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Senha fraca';
                strengthText.className = 'text-xs text-accent-danger mt-1';
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Senha média';
                strengthText.className = 'text-xs text-accent-warning mt-1';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Senha forte';
                strengthText.className = 'text-xs text-accent-success mt-1';
            }
        }
    </script>
</body>
</html>