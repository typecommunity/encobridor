<?php
/**
 * Cloaker Pro - Instalador Web v2.0
 * Sistema de instalação automática
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se já está instalado
if (file_exists('.env')) {
    $envContent = file_get_contents('.env');
    if (strpos($envContent, 'INSTALLED=true') !== false) {
        header('Location: admin/login.php');
        exit;
    }
}

$step = intval($_GET['step'] ?? 1);
$error = '';
$success = '';

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2: // Requisitos verificados
            header('Location: install.php?step=3');
            exit;
            
        case 3: // Configurar banco de dados
            $db_host = trim($_POST['db_host'] ?? 'localhost');
            $db_port = trim($_POST['db_port'] ?? '3306');
            $db_name = trim($_POST['db_name'] ?? '');
            $db_user = trim($_POST['db_user'] ?? '');
            $db_pass = $_POST['db_pass'] ?? '';
            
            if (empty($db_name) || empty($db_user)) {
                $error = 'Nome do banco e usuário são obrigatórios';
            } else {
                try {
                    // Conectar ao MySQL
                    $dsn = "mysql:host=$db_host;port=$db_port;charset=utf8mb4";
                    $pdo = new PDO($dsn, $db_user, $db_pass);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Criar banco se não existir
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    // Conectar ao banco
                    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
                    $pdo = new PDO($dsn, $db_user, $db_pass);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Ler e executar arquivo SQL
                    $sqlFile = __DIR__ . '/database.sql';
                    if (!file_exists($sqlFile)) {
                        throw new Exception('Arquivo database.sql não encontrado');
                    }
                    
                    $sql = file_get_contents($sqlFile);
                    
                    // Remover comentários e dividir em statements
                    $sql = preg_replace('/--.*$/m', '', $sql);
                    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
                    
                    // Executar SQL
                    $pdo->exec($sql);
                    
                    // Salvar na sessão
                    $_SESSION['install'] = [
                        'db_host' => $db_host,
                        'db_port' => $db_port,
                        'db_name' => $db_name,
                        'db_user' => $db_user,
                        'db_pass' => $db_pass
                    ];
                    
                    header('Location: install.php?step=4');
                    exit;
                    
                } catch (PDOException $e) {
                    $error = 'Erro no banco: ' . $e->getMessage();
                }
            }
            break;
            
        case 4: // Configurar licença
            $license_key = trim($_POST['license_key'] ?? '');
            $license_domain = trim($_POST['license_domain'] ?? $_SERVER['HTTP_HOST']);
            
            if (empty($license_key)) {
                $license_key = 'DEMO-' . strtoupper(bin2hex(random_bytes(8)));
            }
            
            $_SESSION['install']['license_key'] = $license_key;
            $_SESSION['install']['license_domain'] = $license_domain;
            
            header('Location: install.php?step=5');
            exit;
            
        case 5: // Criar admin
            $admin_user = trim($_POST['admin_user'] ?? '');
            $admin_pass = $_POST['admin_pass'] ?? '';
            $admin_email = trim($_POST['admin_email'] ?? '');
            $admin_name = trim($_POST['admin_name'] ?? $admin_user);
            
            if (empty($admin_user) || empty($admin_pass)) {
                $error = 'Usuário e senha são obrigatórios';
            } elseif (strlen($admin_pass) < 6) {
                $error = 'Senha deve ter no mínimo 6 caracteres';
            } else {
                try {
                    // Criar .env
                    $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                            . '://' . $_SERVER['HTTP_HOST'] 
                            . rtrim(dirname($_SERVER['REQUEST_URI']), '/');
                    
                    $envContent = "# Cloaker Pro Configuration\n";
                    $envContent .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
                    $envContent .= "DB_HOST={$_SESSION['install']['db_host']}\n";
                    $envContent .= "DB_PORT={$_SESSION['install']['db_port']}\n";
                    $envContent .= "DB_NAME={$_SESSION['install']['db_name']}\n";
                    $envContent .= "DB_USER={$_SESSION['install']['db_user']}\n";
                    $envContent .= "DB_PASS={$_SESSION['install']['db_pass']}\n\n";
                    $envContent .= "LICENSE_KEY={$_SESSION['install']['license_key']}\n";
                    $envContent .= "LICENSE_DOMAIN={$_SESSION['install']['license_domain']}\n\n";
                    $envContent .= "APP_KEY=" . base64_encode(random_bytes(32)) . "\n";
                    $envContent .= "APP_URL=$appUrl\n";
                    $envContent .= "DEBUG_MODE=false\n";
                    $envContent .= "TIMEZONE=America/Sao_Paulo\n\n";
                    $envContent .= "INSTALLED=true\n";
                    
                    file_put_contents('.env', $envContent);
                    
                    // Conectar ao banco
                    $dsn = "mysql:host={$_SESSION['install']['db_host']};port={$_SESSION['install']['db_port']};dbname={$_SESSION['install']['db_name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $_SESSION['install']['db_user'], $_SESSION['install']['db_pass']);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Criar admin
                    $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, display_name, password, email, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')");
                    $stmt->execute([$admin_user, $admin_name, $hash, $admin_email]);
                    
                    // Atualizar settings
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'license_key'");
                    $stmt->execute([$_SESSION['install']['license_key']]);
                    
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'license_domain'");
                    $stmt->execute([$_SESSION['install']['license_domain']]);
                    
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'site_url'");
                    $stmt->execute([$appUrl]);
                    
                    unset($_SESSION['install']);
                    
                    header('Location: install.php?step=6');
                    exit;
                    
                } catch (Exception $e) {
                    $error = 'Erro: ' . $e->getMessage();
                }
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Cloaker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            DEFAULT: '#000000',
                            secondary: '#0a0a0a',
                            tertiary: '#141414',
                            card: '#1a1a1a',
                        },
                        silver: {
                            DEFAULT: '#c0c0c0',
                            light: '#e8e8e8',
                            dark: '#808080',
                            muted: '#a8a8a8',
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        .bg-animated {
            background: linear-gradient(-45deg, #000000, #0a0a0a, #141414, #0a0a0a);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.05) 0%, transparent 50%);
            pointer-events: none;
            animation: pulse 10s ease infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        .card-shadow {
            box-shadow: 0 20px 60px rgba(0,0,0,0.8), 0 0 40px rgba(192, 192, 192, 0.1);
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in-up { animation: fadeInUp 0.6s ease-out; }
    </style>
</head>
<body class="bg-animated min-h-screen flex items-center justify-center p-4 relative">
    <div class="w-full max-w-2xl relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8 fade-in-up">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-gradient-to-br from-silver to-silver-dark rounded-full shadow-lg mb-4">
                <i class="fas fa-shield-alt text-5xl text-dark"></i>
            </div>
            <h1 class="text-4xl font-bold text-silver mb-2">Cloaker Pro</h1>
            <p class="text-silver-dark text-sm uppercase tracking-wider">Instalação do Sistema</p>
        </div>

        <!-- Progress Bar -->
        <div class="mb-8 fade-in-up">
            <div class="flex justify-between items-center bg-dark-card border border-[#2a2a2a] rounded-xl p-4">
                <?php 
                $steps = [
                    1 => ['icon' => 'fa-home', 'label' => 'Início'],
                    2 => ['icon' => 'fa-check-circle', 'label' => 'Requisitos'],
                    3 => ['icon' => 'fa-database', 'label' => 'Banco'],
                    4 => ['icon' => 'fa-key', 'label' => 'Licença'],
                    5 => ['icon' => 'fa-user-shield', 'label' => 'Admin'],
                    6 => ['icon' => 'fa-flag-checkered', 'label' => 'Concluído']
                ];
                foreach ($steps as $num => $info): 
                    $active = $step >= $num;
                ?>
                <div class="flex flex-col items-center <?php echo $num < 6 ? 'flex-1' : ''; ?>">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $active ? 'bg-gradient-to-br from-silver to-silver-dark text-dark' : 'bg-dark-tertiary text-silver-dark'; ?> mb-2">
                        <i class="fas <?php echo $info['icon']; ?>"></i>
                    </div>
                    <span class="text-xs <?php echo $active ? 'text-silver' : 'text-silver-dark'; ?>"><?php echo $info['label']; ?></span>
                </div>
                <?php if ($num < 6): ?>
                <div class="flex-1 h-0.5 <?php echo $step > $num ? 'bg-silver' : 'bg-[#2a2a2a]'; ?> mx-2"></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Content Card -->
        <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl card-shadow p-8 fade-in-up">
            <?php if ($error): ?>
            <div class="bg-red-500 bg-opacity-10 border border-red-500 text-red-500 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if ($step == 1): ?>
                <h2 class="text-2xl font-bold text-silver mb-4">Bem-vindo ao Instalador</h2>
                <p class="text-silver-dark mb-6 leading-relaxed">
                    O Cloaker Pro é um sistema profissional de cloaking desenvolvido para proteger suas campanhas e otimizar conversões. Esta instalação configurará todo o sistema automaticamente.
                </p>
                
                <div class="bg-yellow-500 bg-opacity-10 border border-yellow-500 text-yellow-500 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Importante:</strong> Tenha as credenciais do banco de dados MySQL em mãos.
                </div>

                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="bg-dark-tertiary border border-[#2a2a2a] rounded-lg p-4 text-center">
                        <i class="fas fa-bolt text-3xl text-green-500 mb-2"></i>
                        <p class="text-silver-dark text-sm">Instalação Rápida</p>
                    </div>
                    <div class="bg-dark-tertiary border border-[#2a2a2a] rounded-lg p-4 text-center">
                        <i class="fas fa-shield-alt text-3xl text-blue-500 mb-2"></i>
                        <p class="text-silver-dark text-sm">100% Seguro</p>
                    </div>
                    <div class="bg-dark-tertiary border border-[#2a2a2a] rounded-lg p-4 text-center">
                        <i class="fas fa-cogs text-3xl text-purple-500 mb-2"></i>
                        <p class="text-silver-dark text-sm">Auto-Config</p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <a href="?step=2" class="bg-gradient-to-r from-silver to-silver-dark text-dark font-bold py-3 px-6 rounded-lg hover:shadow-lg transition-all">
                        Começar Instalação <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>

            <?php elseif ($step == 2): ?>
                <h2 class="text-2xl font-bold text-silver mb-4">Verificação de Requisitos</h2>
                
                <div class="space-y-3 mb-6">
                    <?php
                    @mkdir('storage', 0755, true);
                    @mkdir('storage/logs', 0755, true);
                    @mkdir('storage/cache', 0755, true);
                    @mkdir('data', 0755, true);
                    
                    $requirements = [
                        'PHP >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
                        'PDO MySQL' => extension_loaded('pdo_mysql'),
                        'cURL' => extension_loaded('curl'),
                        'JSON' => extension_loaded('json'),
                        'OpenSSL' => extension_loaded('openssl'),
                        'Mbstring' => extension_loaded('mbstring'),
                        'Diretório storage gravável' => is_writable(__DIR__ . '/storage'),
                        'Diretório data gravável' => is_writable(__DIR__ . '/data'),
                        'Arquivo database.sql' => file_exists(__DIR__ . '/database.sql')
                    ];
                    
                    $allOk = true;
                    foreach ($requirements as $name => $ok):
                        if (!$ok) $allOk = false;
                    ?>
                    <div class="flex items-center justify-between bg-dark-tertiary border border-[#2a2a2a] rounded-lg p-4">
                        <span class="text-silver-dark"><?php echo $name; ?></span>
                        <?php if ($ok): ?>
                        <span class="flex items-center text-green-500">
                            <i class="fas fa-check-circle text-xl"></i>
                        </span>
                        <?php else: ?>
                        <span class="flex items-center text-red-500">
                            <i class="fas fa-times-circle text-xl"></i>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <form method="POST">
                    <div class="flex justify-between">
                        <a href="?step=1" class="bg-dark-tertiary text-silver-dark py-3 px-6 rounded-lg hover:bg-dark-secondary transition-all">
                            <i class="fas fa-arrow-left mr-2"></i>Voltar
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-silver to-silver-dark text-dark font-bold py-3 px-6 rounded-lg hover:shadow-lg transition-all" <?php echo !$allOk ? 'disabled' : ''; ?>>
                            <?php echo $allOk ? 'Continuar' : 'Corrija os Problemas'; ?> <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step == 3): ?>
                <h2 class="text-2xl font-bold text-silver mb-4">Configuração do Banco de Dados</h2>
                
                <form method="POST" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-silver-dark text-sm mb-2">Host MySQL</label>
                            <input type="text" name="db_host" value="localhost" required
                                   class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                        </div>
                        <div>
                            <label class="block text-silver-dark text-sm mb-2">Porta</label>
                            <input type="number" name="db_port" value="3306" required
                                   class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Nome do Banco</label>
                        <input type="text" name="db_name" placeholder="cloaker_pro" required
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                        <p class="text-xs text-silver-dark mt-1">Será criado automaticamente se não existir</p>
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Usuário MySQL</label>
                        <input type="text" name="db_user" placeholder="root" required
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Senha MySQL</label>
                        <input type="password" name="db_pass" placeholder="••••••••"
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                        <p class="text-xs text-silver-dark mt-1">Deixe vazio se não houver senha</p>
                    </div>
                    
                    <div class="flex justify-between pt-4">
                        <a href="?step=2" class="bg-dark-tertiary text-silver-dark py-3 px-6 rounded-lg hover:bg-dark-secondary transition-all">
                            <i class="fas fa-arrow-left mr-2"></i>Voltar
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-silver to-silver-dark text-dark font-bold py-3 px-6 rounded-lg hover:shadow-lg transition-all">
                            Testar e Continuar <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step == 4): ?>
                <h2 class="text-2xl font-bold text-silver mb-4">Configuração da Licença</h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Chave de Licença (Opcional)</label>
                        <input type="text" name="license_key" placeholder="XXXX-XXXX-XXXX-XXXX"
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                        <p class="text-xs text-silver-dark mt-1">Deixe vazio para modo DEMO</p>
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Domínio</label>
                        <input type="text" name="license_domain" value="<?php echo $_SERVER['HTTP_HOST']; ?>" required
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                    </div>
                    
                    <div class="bg-blue-500 bg-opacity-10 border border-blue-500 text-blue-500 px-4 py-3 rounded-lg">
                        <i class="fas fa-info-circle mr-2"></i>
                        Você pode alterar a licença posteriormente no painel admin
                    </div>
                    
                    <div class="flex justify-between pt-4">
                        <a href="?step=3" class="bg-dark-tertiary text-silver-dark py-3 px-6 rounded-lg hover:bg-dark-secondary transition-all">
                            <i class="fas fa-arrow-left mr-2"></i>Voltar
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-silver to-silver-dark text-dark font-bold py-3 px-6 rounded-lg hover:shadow-lg transition-all">
                            Continuar <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step == 5): ?>
                <h2 class="text-2xl font-bold text-silver mb-4">Criar Usuário Administrador</h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Nome de Exibição</label>
                        <input type="text" name="admin_name" placeholder="Administrator" required
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Nome de Usuário</label>
                        <input type="text" name="admin_user" placeholder="admin" required
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Senha</label>
                        <input type="password" name="admin_pass" placeholder="••••••••" required minlength="6"
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                        <p class="text-xs text-silver-dark mt-1">Mínimo 6 caracteres</p>
                    </div>
                    
                    <div>
                        <label class="block text-silver-dark text-sm mb-2">Email (opcional)</label>
                        <input type="email" name="admin_email" placeholder="admin@exemplo.com"
                               class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-3 text-silver focus:outline-none focus:border-silver">
                    </div>
                    
                    <div class="flex justify-between pt-4">
                        <a href="?step=4" class="bg-dark-tertiary text-silver-dark py-3 px-6 rounded-lg hover:bg-dark-secondary transition-all">
                            <i class="fas fa-arrow-left mr-2"></i>Voltar
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-silver to-silver-dark text-dark font-bold py-3 px-6 rounded-lg hover:shadow-lg transition-all">
                            Finalizar Instalação <i class="fas fa-check ml-2"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step == 6): ?>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-green-500 rounded-full mb-4">
                        <i class="fas fa-check text-4xl text-white"></i>
                    </div>
                    <h2 class="text-3xl font-bold text-silver mb-4">Instalação Concluída!</h2>
                    <p class="text-silver-dark mb-6">
                        O Cloaker Pro foi instalado com sucesso. Você já pode começar a usar o sistema.
                    </p>
                    
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="bg-green-500 bg-opacity-10 border border-green-500 rounded-lg p-4">
                            <i class="fas fa-database text-2xl text-green-500 mb-2"></i>
                            <p class="text-silver-dark text-sm">Banco Configurado</p>
                        </div>
                        <div class="bg-green-500 bg-opacity-10 border border-green-500 rounded-lg p-4">
                            <i class="fas fa-table text-2xl text-green-500 mb-2"></i>
                            <p class="text-silver-dark text-sm">Tabelas Criadas</p>
                        </div>
                        <div class="bg-green-500 bg-opacity-10 border border-green-500 rounded-lg p-4">
                            <i class="fas fa-user-shield text-2xl text-green-500 mb-2"></i>
                            <p class="text-silver-dark text-sm">Admin Criado</p>
                        </div>
                        <div class="bg-green-500 bg-opacity-10 border border-green-500 rounded-lg p-4">
                            <i class="fas fa-cog text-2xl text-green-500 mb-2"></i>
                            <p class="text-silver-dark text-sm">Sistema Pronto</p>
                        </div>
                    </div>
                    
                    <div class="bg-red-500 bg-opacity-10 border border-red-500 text-red-500 px-4 py-3 rounded-lg mb-6">
                        <i class="fas fa-shield-alt mr-2"></i>
                        <strong>IMPORTANTE:</strong> Remova o arquivo <code class="bg-dark-tertiary px-2 py-1 rounded">install.php</code> por segurança!
                    </div>
                    
                    <a href="admin/login.php" class="inline-block bg-gradient-to-r from-silver to-silver-dark text-dark font-bold py-4 px-8 rounded-lg hover:shadow-lg transition-all text-lg">
                        Acessar Painel Admin <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 fade-in-up">
            <p class="text-silver-dark text-sm">
                © <?php echo date('Y'); ?> <span class="text-silver font-semibold">Cloaker Pro</span> - Powered by AutoStacker
            </p>
        </div>
    </div>
</body>
</html>