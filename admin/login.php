<?php
/**
 * Cloaker Pro - Admin Login
 * Tela de login do painel administrativo
 */

// Configurar tratamento de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Iniciar sessão se ainda não iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Definir caminho base
define('BASE_PATH', dirname(dirname(__FILE__)));

// Verificar e carregar arquivos necessários
$required_files = [
    BASE_PATH . '/config.php',
    BASE_PATH . '/core/Database.php',
    BASE_PATH . '/core/Utils.php',
    BASE_PATH . '/core/Auth.php'
];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("Arquivo necessário não encontrado: " . basename($file));
    }
    require_once $file;
}

// Inicializar classes
try {
    $auth = new Auth();
} catch (Exception $e) {
    die("Erro ao inicializar sistema: " . $e->getMessage());
}

$error = '';
$success = '';

// Se já está logado, redirecionar
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST['remember']);
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor, preencha todos os campos.';
    } else {
        try {
            $result = $auth->login($username, $password, $remember);
            
            if ($result['success']) {
                // Verificar se precisa de 2FA
                if (isset($result['require_2fa']) && $result['require_2fa']) {
                    $_SESSION['pending_2fa'] = $result['user']['id'];
                    header('Location: login-2fa.php');
                    exit;
                }
                
                // Redirecionar para o dashboard
                $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = $result['message'];
            }
        } catch (Exception $e) {
            $error = 'Erro ao processar login. Por favor, tente novamente.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

// Processar reset de senha
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $result = $auth->resetPassword($email);
                if ($result['success']) {
                    $success = 'Email de recuperação enviado! Verifique sua caixa de entrada.';
                } else {
                    $error = $result['message'];
                }
            } catch (Exception $e) {
                $error = 'Erro ao processar solicitação.';
                error_log("Password reset error: " . $e->getMessage());
            }
        } else {
            $error = 'Email inválido.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Cloaker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Configuração Dark Theme Tailwind -->
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
                            hover: '#242424',
                        },
                        silver: {
                            DEFAULT: '#c0c0c0',
                            light: '#e8e8e8',
                            dark: '#808080',
                            muted: '#a8a8a8',
                        },
                        accent: {
                            success: '#4ade80',
                            danger: '#f87171',
                            warning: '#fbbf24',
                            info: '#60a5fa',
                        }
                    },
                    backgroundImage: {
                        'gradient-silver': 'linear-gradient(135deg, #c0c0c0 0%, #808080 100%)',
                        'gradient-dark': 'linear-gradient(180deg, #0a0a0a 0%, #000000 100%)',
                    },
                    boxShadow: {
                        'glow': '0 0 20px rgba(192, 192, 192, 0.15)',
                        'glow-lg': '0 0 30px rgba(192, 192, 192, 0.25)',
                    }
                }
            }
        }
    </script>
    
    <style>
        /* Background animado dark */
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
        
        /* Padrão de fundo sutil */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 50% 20%, rgba(192, 192, 192, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
            animation: pulse 10s ease infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        
        .card-shadow {
            box-shadow: 0 20px 60px rgba(0,0,0,0.8), 0 0 40px rgba(192, 192, 192, 0.1);
        }
        
        .input-focus {
            transition: all 0.3s ease;
        }
        
        .input-focus:focus {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(192, 192, 192, 0.3);
        }
        
        /* Animação shine no logo */
        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        .logo-shine {
            position: relative;
            overflow: hidden;
        }
        
        .logo-shine::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shine 3s infinite;
        }
        
        /* Scrollbar customizada */
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #0a0a0a;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #808080;
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #c0c0c0;
        }
        
        /* Efeito glow nos inputs */
        .input-glow:focus {
            box-shadow: 0 0 0 3px rgba(192, 192, 192, 0.2);
        }
        
        /* Animação de entrada */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
</head>
<body class="bg-animated min-h-screen flex items-center justify-center p-4 relative">
    <div class="w-full max-w-md relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8 fade-in-up">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-gradient-silver rounded-full shadow-glow-lg mb-4 logo-shine">
                <i class="fas fa-shield-alt text-5xl text-dark relative z-10"></i>
            </div>
            <h1 class="text-4xl font-bold text-silver mb-2 tracking-tight">Cloaker Pro</h1>
            <p class="text-silver-dark text-sm uppercase tracking-wider">Sistema Profissional de Cloaking</p>
        </div>

        <!-- Login Card -->
        <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl card-shadow p-8 backdrop-blur-xl fade-in-up" style="animation-delay: 0.2s;">
            <?php if (isset($_GET['action']) && $_GET['action'] === 'reset'): ?>
                <!-- Reset Password Form -->
                <h2 class="text-2xl font-bold text-silver mb-6 flex items-center gap-3">
                    <i class="fas fa-key text-silver"></i>
                    Recuperar Senha
                </h2>
                
                <?php if ($error): ?>
                <div class="bg-accent-danger bg-opacity-10 border border-accent-danger text-accent-danger px-4 py-3 rounded-lg mb-4 animate-pulse">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="bg-accent-success bg-opacity-10 border border-accent-success text-accent-success px-4 py-3 rounded-lg mb-4">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="?action=reset">
                    <div class="mb-6">
                        <label class="block text-silver-dark text-sm font-semibold mb-2 uppercase tracking-wide" for="email">
                            Email Cadastrado
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-silver-dark"></i>
                            </div>
                            <input type="email" name="email" id="email" required
                                   class="input-focus input-glow w-full pl-12 pr-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark"
                                   placeholder="seu@email.com">
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full bg-gradient-silver hover:shadow-glow text-dark font-bold py-3 px-4 rounded-lg transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-paper-plane mr-2"></i>Enviar Email de Recuperação
                    </button>
                </form>
                
                <div class="mt-6 text-center">
                    <a href="login.php" class="text-silver-dark hover:text-silver text-sm transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Voltar ao Login
                    </a>
                </div>
            <?php else: ?>
                <!-- Login Form -->
                <h2 class="text-2xl font-bold text-silver mb-6 flex items-center gap-3">
                    <i class="fas fa-sign-in-alt text-silver"></i>
                    Acessar Painel
                </h2>
                
                <?php if ($error): ?>
                <div class="bg-accent-danger bg-opacity-10 border border-accent-danger text-accent-danger px-4 py-3 rounded-lg mb-4 animate-pulse">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="loginForm">
                    <div class="mb-4">
                        <label class="block text-silver-dark text-sm font-semibold mb-2 uppercase tracking-wide" for="username">
                            Usuário ou Email
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-user text-silver-dark"></i>
                            </div>
                            <input type="text" name="username" id="username" required autofocus
                                   class="input-focus input-glow w-full pl-12 pr-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark"
                                   placeholder="admin">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-silver-dark text-sm font-semibold mb-2 uppercase tracking-wide" for="password">
                            Senha
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-silver-dark"></i>
                            </div>
                            <input type="password" name="password" id="password" required
                                   class="input-focus input-glow w-full pl-12 pr-12 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg focus:outline-none focus:border-silver text-silver placeholder-silver-dark"
                                   placeholder="••••••••">
                            <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-4 flex items-center">
                                <i id="toggleIcon" class="fas fa-eye text-silver-dark hover:text-silver transition-colors"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between mb-6">
                        <label class="flex items-center cursor-pointer group">
                            <input type="checkbox" name="remember" class="w-4 h-4 text-silver bg-dark-tertiary border-[#2a2a2a] rounded focus:ring-silver focus:ring-2">
                            <span class="ml-2 text-sm text-silver-dark group-hover:text-silver transition-colors">Lembrar de mim</span>
                        </label>
                        <a href="?action=reset" class="text-sm text-silver-dark hover:text-silver transition-colors">
                            Esqueceu a senha?
                        </a>
                    </div>
                    
                    <button type="submit" id="loginBtn" class="w-full bg-gradient-silver hover:shadow-glow text-dark font-bold py-3 px-4 rounded-lg transition-all duration-300 transform hover:scale-105 relative overflow-hidden group">
                        <span class="relative z-10">
                            <i class="fas fa-sign-in-alt mr-2"></i>Entrar
                        </span>
                        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-0 group-hover:opacity-20 transform -translate-x-full group-hover:translate-x-full transition-transform duration-1000"></div>
                    </button>
                </form>
                
                <!-- Divider -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-[#2a2a2a]"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-4 bg-dark-card text-silver-dark uppercase tracking-wider text-xs">Segurança Premium</span>
                    </div>
                </div>
                
                <!-- Security Features -->
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                        <i class="fas fa-shield-alt text-accent-success text-lg mb-1"></i>
                        <p class="text-xs text-silver-dark">Criptografia</p>
                    </div>
                    <div class="p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                        <i class="fas fa-lock text-accent-info text-lg mb-1"></i>
                        <p class="text-xs text-silver-dark">SSL/TLS</p>
                    </div>
                    <div class="p-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg">
                        <i class="fas fa-user-shield text-accent-warning text-lg mb-1"></i>
                        <p class="text-xs text-silver-dark">2FA Ready</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-8 fade-in-up" style="animation-delay: 0.4s;">
            <p class="text-silver-dark text-sm">
                &copy; <?php echo date('Y'); ?> <span class="text-silver font-semibold">Cloaker Pro</span> - Powered by <span class="text-silver-light">AutoStacker</span>
            </p>
            <div class="flex items-center justify-center gap-4 mt-3">
                <a href="#" class="text-silver-dark hover:text-silver transition-colors text-xs">
                    <i class="fas fa-file-contract mr-1"></i>Termos
                </a>
                <span class="text-silver-dark">•</span>
                <a href="#" class="text-silver-dark hover:text-silver transition-colors text-xs">
                    <i class="fas fa-shield-alt mr-1"></i>Privacidade
                </a>
                <span class="text-silver-dark">•</span>
                <a href="#" class="text-silver-dark hover:text-silver transition-colors text-xs">
                    <i class="fas fa-question-circle mr-1"></i>Suporte
                </a>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Login form animation
        document.getElementById('loginForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Entrando...';
            btn.disabled = true;
            btn.classList.add('opacity-75');
        });
        
        // Auto-focus
        document.getElementById('username')?.focus();
        
        // Adicionar efeito de digitação suave
        const inputs = document.querySelectorAll('input[type="text"], input[type="password"], input[type="email"]');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('scale-105');
            });
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('scale-105');
            });
        });
    </script>
</body>
</html>