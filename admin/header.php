<?php
/**
 * Cloaker Pro - Header Component
 * Cabeçalho modular para todas as páginas
 * 
 * VERSÃO 2.1 - COM SUPORTE MULTI-TENANCY
 * - Sanitização consistente de dados
 * - Proteção XSS aprimorada
 * - Validação de variáveis obrigatórias
 * - Informações de tenant e super admin
 */

// ==========================================
// VALIDAÇÃO E INICIALIZAÇÃO DE VARIÁVEIS
// ==========================================

// Garantir que as variáveis necessárias existam com valores padrão seguros
if (!isset($user) || !is_array($user)) {
    $user = [
        'username' => 'Usuário',
        'email' => 'usuario@example.com'
    ];
}

if (!isset($licenseInfo) || !is_array($licenseInfo)) {
    $licenseInfo = ['plan' => 'FREE'];
}

// Sanitizar variáveis de página
$pageTitle = isset($pageTitle) ? sanitize($pageTitle, 'string') : 'Cloaker Pro';
$pageSubtitle = isset($pageSubtitle) ? sanitize($pageSubtitle, 'string') : '';

// Para ações e info personalizadas, aceitar HTML mas com cuidado
$pageAction = $pageAction ?? '';
$pageInfo = $pageInfo ?? '';

// Sanitizar dados do usuário
$displayName = !empty($user['display_name']) ? $user['display_name'] : $user['username'];
$userName = sanitize($displayName ?? 'Usuário', 'string');
$userEmail = sanitize($user['email'] ?? '', 'email');
$userInitial = strtoupper(substr($userName, 0, 1));
$licensePlan = sanitize($licenseInfo['plan'] ?? 'FREE', 'string');

// Formatar data de expiração se existir
$licenseExpires = '';
if (isset($licenseInfo['expires']) && !empty($licenseInfo['expires'])) {
    $expiresDate = date('d/m/Y', strtotime($licenseInfo['expires']));
    $licenseExpires = sanitize($expiresDate, 'string');
}

// ==========================================
// MULTI-TENANCY: INFORMAÇÕES DO TENANT
// ==========================================
$isSuperAdmin = false;
$currentTenant = null;
$tenantName = '';
$tenantPlan = '';

// Verificar se o middleware de tenant está disponível
if (isset($GLOBALS['tenantMiddleware']) && $GLOBALS['tenantMiddleware']) {
    $tenantMiddleware = $GLOBALS['tenantMiddleware'];
    $isSuperAdmin = $tenantMiddleware->isSuperAdmin();
    
    if (!$isSuperAdmin) {
        $currentTenant = $tenantMiddleware->getCurrentTenant();
        if ($currentTenant) {
            $tenantName = sanitize($currentTenant['name'] ?? '', 'string');
            $tenantPlan = strtoupper(sanitize($currentTenant['plan'] ?? 'FREE', 'string'));
        }
    }
}

?>

<!-- Header -->
<header class="bg-dark-secondary border-b border-[#2a2a2a] backdrop-blur-xl sticky top-0 z-40 transition-all duration-300">
    <div class="flex items-center justify-between px-8 py-4 gap-4">
        
        <!-- Page Title -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-bold bg-gradient-silver bg-clip-text text-transparent truncate">
                    <?php echo $pageTitle; ?>
                </h1>
                
                <!-- Super Admin Badge -->
                <?php if ($isSuperAdmin): ?>
                <span class="px-3 py-1 bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-xs font-bold rounded-full shadow-glow flex items-center gap-2">
                    <i class="fas fa-crown"></i>
                    SUPER ADMIN
                </span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($pageSubtitle)): ?>
            <p class="text-silver-dark text-sm mt-1 truncate">
                <?php echo $pageSubtitle; ?>
            </p>
            <?php endif; ?>
        </div>
        
        <!-- Actions & User Menu -->
        <div class="flex items-center gap-3 flex-shrink-0">
            
            <!-- Tenant Info Badge (só aparece para clientes) -->
            <?php if (!$isSuperAdmin && $currentTenant): ?>
            <div class="hidden md:flex items-center gap-2 px-4 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg">
                <div class="flex items-center gap-2">
                    <i class="fas fa-building text-blue-400"></i>
                    <div class="text-left">
                        <p class="text-xs text-silver-dark">Empresa</p>
                        <p class="text-sm font-semibold text-silver"><?php echo $tenantName; ?></p>
                    </div>
                </div>
                <div class="border-l border-[#2a2a2a] pl-2 ml-2">
                    <span class="px-2 py-1 rounded text-xs font-bold
                        <?php
                        switch($tenantPlan) {
                            case 'FREE': echo 'bg-gray-600/20 text-gray-400'; break;
                            case 'BASIC': echo 'bg-blue-600/20 text-blue-400'; break;
                            case 'PRO': echo 'bg-purple-600/20 text-purple-400'; break;
                            case 'ENTERPRISE': echo 'bg-yellow-600/20 text-yellow-400'; break;
                            default: echo 'bg-gray-600/20 text-gray-400';
                        }
                        ?>">
                        <?php echo $tenantPlan; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Custom Action (botões, info extra, etc) -->
            <?php if (!empty($pageAction)): ?>
                <div class="page-actions">
                    <?php echo $pageAction; ?>
                </div>
            <?php endif; ?>
            
            <!-- Custom Info (badges, status, etc) -->
            <?php if (!empty($pageInfo)): ?>
                <div class="page-info">
                    <?php echo $pageInfo; ?>
                </div>
            <?php endif; ?>
            
            <!-- User Menu -->
            <div class="relative group">
                <button class="flex items-center gap-3 px-4 py-2 bg-dark-card border border-[#2a2a2a] rounded-lg hover:border-silver hover:shadow-glow transition-all duration-300">
                    
                    <!-- Avatar -->
                    <div class="w-10 h-10 <?php echo $isSuperAdmin ? 'bg-gradient-to-br from-purple-600 to-indigo-600' : 'bg-gradient-silver'; ?> rounded-full flex items-center justify-center <?php echo $isSuperAdmin ? 'text-white' : 'text-dark'; ?> font-bold shadow-glow relative">
                        <?php echo $userInitial; ?>
                        <?php if ($isSuperAdmin): ?>
                        <div class="absolute -top-1 -right-1 w-4 h-4 bg-yellow-400 rounded-full flex items-center justify-center">
                            <i class="fas fa-crown text-[8px] text-purple-900"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- User Info (hidden on mobile) -->
                    <div class="text-left hidden lg:block">
                        <p class="text-sm font-semibold text-silver"><?php echo $userName; ?></p>
                        <p class="text-xs text-silver-dark truncate max-w-[150px]" title="<?php echo $userEmail; ?>">
                            <?php echo $userEmail; ?>
                        </p>
                    </div>
                    
                    <!-- Dropdown Icon -->
                    <i class="fas fa-chevron-down text-silver-dark text-xs group-hover:rotate-180 transition-transform duration-300"></i>
                </button>
                
                <!-- Dropdown Menu -->
                <div class="absolute right-0 mt-2 w-72 bg-dark-card border border-[#2a2a2a] rounded-xl shadow-glow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 overflow-hidden">
                    
                    <!-- User Info Section -->
                    <div class="p-4 border-b border-[#2a2a2a] bg-dark-tertiary">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-12 h-12 <?php echo $isSuperAdmin ? 'bg-gradient-to-br from-purple-600 to-indigo-600' : 'bg-gradient-silver'; ?> rounded-full flex items-center justify-center <?php echo $isSuperAdmin ? 'text-white' : 'text-dark'; ?> font-bold text-lg shadow-glow relative">
                                <?php echo $userInitial; ?>
                                <?php if ($isSuperAdmin): ?>
                                <div class="absolute -top-1 -right-1 w-5 h-5 bg-yellow-400 rounded-full flex items-center justify-center">
                                    <i class="fas fa-crown text-[10px] text-purple-900"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-silver text-base truncate"><?php echo $userName; ?></p>
                                <p class="text-sm text-silver-dark break-all"><?php echo $userEmail; ?></p>
                                <?php if ($isSuperAdmin): ?>
                                <span class="inline-block mt-1 px-2 py-0.5 bg-purple-600/20 text-purple-400 text-xs font-semibold rounded">
                                    Super Administrador
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Tenant Info no Dropdown (mobile) -->
                        <?php if (!$isSuperAdmin && $currentTenant): ?>
                        <div class="md:hidden p-3 bg-dark-card rounded-lg border border-[#2a2a2a]">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-silver-dark">Empresa</p>
                                    <p class="text-sm font-semibold text-silver"><?php echo $tenantName; ?></p>
                                </div>
                                <span class="px-2 py-1 rounded text-xs font-bold
                                    <?php
                                    switch($tenantPlan) {
                                        case 'FREE': echo 'bg-gray-600/20 text-gray-400'; break;
                                        case 'BASIC': echo 'bg-blue-600/20 text-blue-400'; break;
                                        case 'PRO': echo 'bg-purple-600/20 text-purple-400'; break;
                                        case 'ENTERPRISE': echo 'bg-yellow-600/20 text-yellow-400'; break;
                                        default: echo 'bg-gray-600/20 text-gray-400';
                                    }
                                    ?>">
                                    <?php echo $tenantPlan; ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Menu Items -->
                    <nav class="py-2" role="navigation" aria-label="Menu do usuário">
                        
                        <!-- Super Admin Menu -->
                        <?php if ($isSuperAdmin): ?>
                        <a href="tenants.php" class="flex items-center gap-3 px-4 py-3 text-purple-400 hover:text-purple-300 hover:bg-dark-hover transition-all duration-300 bg-purple-600/10">
                            <i class="fas fa-building text-lg w-5"></i>
                            <span class="font-medium">Gerenciar Clientes</span>
                            <i class="fas fa-arrow-right ml-auto text-xs"></i>
                        </a>
                        <div class="border-t border-[#2a2a2a] my-2"></div>
                        <?php endif; ?>
                        
                        <a href="profile.php" class="flex items-center gap-3 px-4 py-3 text-silver-dark hover:text-silver hover:bg-dark-hover transition-all duration-300">
                            <i class="fas fa-user-circle text-lg w-5"></i>
                            <span class="font-medium">Meu Perfil</span>
                        </a>
                        
                        <a href="settings.php" class="flex items-center gap-3 px-4 py-3 text-silver-dark hover:text-silver hover:bg-dark-hover transition-all duration-300">
                            <i class="fas fa-cog text-lg w-5"></i>
                            <span class="font-medium">Configurações</span>
                        </a>
                        
                        <?php if (!$isSuperAdmin): ?>
                        <a href="billing.php" class="flex items-center gap-3 px-4 py-3 text-silver-dark hover:text-silver hover:bg-dark-hover transition-all duration-300">
                            <i class="fas fa-credit-card text-lg w-5"></i>
                            <span class="font-medium">Plano e Faturamento</span>
                        </a>
                        <?php endif; ?>
                        
                        <a href="license.php" class="flex items-center gap-3 px-4 py-3 text-silver-dark hover:text-silver hover:bg-dark-hover transition-all duration-300">
                            <i class="fas fa-key text-lg w-5"></i>
                            <span class="font-medium">Gerenciar Licença</span>
                        </a>
                        
                        <div class="border-t border-[#2a2a2a] my-2"></div>
                        
                        <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-silver-dark hover:text-accent-danger hover:bg-dark-hover transition-all duration-300">
                            <i class="fas fa-sign-out-alt text-lg w-5"></i>
                            <span class="font-medium">Sair</span>
                        </a>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</header>

<?php
/**
 * NOTAS DE SEGURANÇA E RECURSOS:
 * 
 * SEGURANÇA:
 * 1. Todas as variáveis de usuário são sanitizadas usando a função sanitize()
 * 2. Email usa sanitização específica com FILTER_SANITIZE_EMAIL
 * 3. Datas são formatadas e sanitizadas
 * 4. $pageAction e $pageInfo devem vir APENAS de código confiável
 * 5. Atributos HTML usam aspas duplas para melhor proteção
 * 
 * MULTI-TENANCY:
 * 1. Detecta automaticamente se o usuário é Super Admin
 * 2. Exibe badge "SUPER ADMIN" com coroa para super admins
 * 3. Mostra informações do tenant (empresa e plano) para clientes
 * 4. Avatar diferenciado: gradiente roxo para super admin, prata para clientes
 * 5. Menu especial para super admin com link para gerenciar clientes
 * 6. Responsivo: info do tenant fica visível no desktop e no dropdown mobile
 * 
 * ACESSIBILIDADE:
 * 1. Adicionado title no email para acessibilidade
 * 2. Adicionado role e aria-label no menu para acessibilidade
 * 3. Uso consistente de min-w-0 e truncate para prevenir overflow
 */
?>