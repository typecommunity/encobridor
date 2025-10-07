<?php
/**
 * Cloaker Pro - Sidebar Component
 * Menu lateral com opção de colapsar
 * Versão centralizada - mesma lógica em todas as páginas
 */

// ==========================================
// INICIALIZAÇÃO CENTRALIZADA DE VARIÁVEIS
// ==========================================

// Garantir que user existe
if (!isset($user)) {
    try {
        if (!isset($auth)) {
            require_once '../core/Auth.php';
            $auth = new Auth();
        }
        $user = $auth->getCurrentUser();
    } catch (Exception $e) {
        $user = ['username' => 'Usuário', 'display_name' => 'Usuário'];
        error_log("Sidebar user error: " . $e->getMessage());
    }
}

// Definir nome de exibição
$displayName = !empty($user['display_name']) ? $user['display_name'] : $user['username'];

// Garantir que licenseInfo existe
if (!isset($licenseInfo)) {
    try {
        if (!isset($license)) {
            require_once '../core/License.php';
            $license = new License();
        }
        $licenseInfo = $license->getInfo();
    } catch (Exception $e) {
        $licenseInfo = ['plan' => 'FREE'];
        error_log("Sidebar license error: " . $e->getMessage());
    }
}

// ==========================================
// MULTI-TENANCY: VERIFICAR SE É SUPER ADMIN
// ==========================================
$isSuperAdmin = false;
if (isset($GLOBALS['tenantMiddleware']) && $GLOBALS['tenantMiddleware']) {
    $isSuperAdmin = $GLOBALS['tenantMiddleware']->isSuperAdmin();
}

// ==========================================
// VERIFICAR DISPONIBILIDADE DE FUNCIONALIDADES
// ==========================================

// Verificar Fingerprinting
if (!isset($fingerprintingAvailable)) {
    $fingerprintingAvailable = false;
    try {
        if (!isset($db)) {
            $db = Database::getInstance();
        }
        $tableCheck = $db->query("SHOW TABLES LIKE 'fingerprints'");
        $fingerprintingAvailable = $tableCheck && $tableCheck->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Sidebar fingerprinting check error: " . $e->getMessage());
    }
}

// Verificar Anti-Scraping
if (!isset($antiScrapingAvailable)) {
    $antiScrapingAvailable = file_exists('../core/RateLimiter.php');
}

// ==========================================
// OBTER ESTATÍSTICAS PARA BADGES
// ==========================================

// Stats de Fingerprints
if (!isset($fingerprintStats)) {
    $fingerprintStats = ['suspicious_count' => 0];
    
    if ($fingerprintingAvailable) {
        try {
            if (!isset($db)) {
                $db = Database::getInstance();
            }
            $result = $db->query("SELECT COUNT(*) as count FROM fingerprints WHERE is_suspicious = 1");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $fingerprintStats['suspicious_count'] = $row ? intval($row['count']) : 0;
        } catch (Exception $e) {
            error_log("Sidebar fingerprint stats error: " . $e->getMessage());
        }
    }
}

// Stats de Anti-Scraping
if (!isset($antiScrapingStats)) {
    $antiScrapingStats = ['attempts_hour' => 0];
    
    if ($antiScrapingAvailable) {
        try {
            if (!isset($db)) {
                $db = Database::getInstance();
            }
            $tableCheck = $db->query("SHOW TABLES LIKE 'scraping_attempts'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $result = $db->query("SELECT COUNT(*) as count FROM scraping_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $antiScrapingStats['attempts_hour'] = $row ? intval($row['count']) : 0;
            }
        } catch (Exception $e) {
            error_log("Sidebar anti-scraping stats error: " . $e->getMessage());
        }
    }
}

// Determinar página atual
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Sidebar -->
<div id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-dark-secondary border-r border-[#2a2a2a] shadow-2xl z-50 transition-all duration-300">
    <!-- Logo -->
    <div class="relative flex items-center justify-center h-16 bg-gradient-silver overflow-hidden animate-shine">
        <span id="logoText" class="text-dark text-xl font-bold tracking-wider uppercase z-10 transition-opacity duration-300">
            🛡️ Cloaker Pro
        </span>
        <span id="logoIcon" class="text-dark text-xl font-bold z-10 opacity-0 absolute transition-opacity duration-300">
            🛡️
        </span>
    </div>
    
    <!-- Toggle Button -->
    <button id="sidebarToggle" class="absolute -right-3 top-20 w-6 h-6 bg-gradient-silver rounded-full shadow-glow flex items-center justify-center text-dark hover:scale-110 transition-all duration-300 z-50">
        <i class="fas fa-chevron-left text-xs transition-transform duration-300" id="toggleIcon"></i>
    </button>
    
    <!-- User Info Compact -->
    <div class="px-4 py-3 border-b border-[#2a2a2a]">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 <?php echo $isSuperAdmin ? 'bg-gradient-to-br from-purple-600 to-indigo-600' : 'bg-gradient-silver'; ?> rounded-full flex items-center justify-center <?php echo $isSuperAdmin ? 'text-white' : 'text-dark'; ?> text-sm font-bold shadow-glow flex-shrink-0 relative">
                <?php echo strtoupper(substr($displayName, 0, 2)); ?>
                <?php if ($isSuperAdmin): ?>
                <div class="absolute -top-1 -right-1 w-4 h-4 bg-yellow-400 rounded-full flex items-center justify-center">
                    <i class="fas fa-crown text-[8px] text-purple-900"></i>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0 transition-all duration-300" id="userInfo">
                <div class="text-sm font-semibold text-silver truncate"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="text-xs text-silver-dark truncate">@<?php echo htmlspecialchars($user['username']); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="mt-4 relative z-10 px-2 overflow-y-auto" style="max-height: calc(100vh - 280px);">
        <!-- Dashboard -->
        <a href="index.php" class="sidebar-item <?php echo ($currentPage === 'index.php') ? 'active' : ''; ?>" data-tooltip="Dashboard">
            <i class="fas fa-tachometer-alt sidebar-icon"></i>
            <span class="sidebar-text">Dashboard</span>
            <?php if ($currentPage === 'index.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>
        
        <!-- SUPER ADMIN: Gerenciar Clientes -->
        <?php if ($isSuperAdmin): ?>
        <a href="tenants.php" class="sidebar-item sidebar-item-admin <?php echo ($currentPage === 'tenants.php' || $currentPage === 'tenant-details.php') ? 'active' : ''; ?>" data-tooltip="Gerenciar Clientes">
            <i class="fas fa-building sidebar-icon"></i>
            <span class="sidebar-text">Gerenciar Clientes</span>
            <span class="sidebar-admin-badge">ADMIN</span>
            <?php if ($currentPage === 'tenants.php' || $currentPage === 'tenant-details.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>
        
        <!-- Divider após menu admin -->
        <div class="sidebar-divider"></div>
        <?php endif; ?>
        
        <!-- Campanhas -->
        <a href="campaigns.php" class="sidebar-item <?php echo ($currentPage === 'campaigns.php' || $currentPage === 'campaign-create.php' || $currentPage === 'campaign-edit.php') ? 'active' : ''; ?>" data-tooltip="Campanhas">
            <i class="fas fa-rocket sidebar-icon"></i>
            <span class="sidebar-text">Campanhas</span>
            <?php if ($currentPage === 'campaigns.php' || $currentPage === 'campaign-create.php' || $currentPage === 'campaign-edit.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>

        <!-- Domínios Personalizados -->
<!-- Domínios Personalizados -->
<a href="domains.php" class="sidebar-item <?php echo ($currentPage === 'domains.php') ? 'active' : ''; ?>" data-tooltip="Domínios">
    <i class="fas fa-globe sidebar-icon"></i>
    <span class="sidebar-text">Domínios</span>
    <?php if ($currentPage === 'domains.php'): ?>
    <div class="sidebar-indicator"></div>
    <?php endif; ?>
</a>
        
        <!-- Analytics -->
        <a href="analytics.php" class="sidebar-item <?php echo ($currentPage === 'analytics.php') ? 'active' : ''; ?>" data-tooltip="Analytics">
            <i class="fas fa-chart-line sidebar-icon"></i>
            <span class="sidebar-text">Analytics</span>
            <?php if ($currentPage === 'analytics.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>
        
        <!-- Fingerprints (sempre visível se disponível) -->
        <?php if ($fingerprintingAvailable): ?>
        <a href="fingerprints.php" class="sidebar-item <?php echo ($currentPage === 'fingerprints.php') ? 'active' : ''; ?>" data-tooltip="Fingerprints">
            <i class="fas fa-fingerprint sidebar-icon"></i>
            <span class="sidebar-text">Fingerprints</span>
            <?php if ($fingerprintStats['suspicious_count'] > 0): ?>
            <span class="sidebar-badge">
                <?php echo $fingerprintStats['suspicious_count']; ?>
            </span>
            <?php endif; ?>
            <?php if ($currentPage === 'fingerprints.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <!-- Anti-Scraping (sempre visível se disponível) -->
        <?php if ($antiScrapingAvailable): ?>
        <a href="anti-scraping.php" class="sidebar-item <?php echo ($currentPage === 'anti-scraping.php') ? 'active' : ''; ?>" data-tooltip="Anti-Scraping">
            <i class="fas fa-shield-alt sidebar-icon"></i>
            <span class="sidebar-text">Anti-Scraping</span>
            <?php if ($antiScrapingStats['attempts_hour'] > 5): ?>
            <span class="sidebar-pulse"></span>
            <?php endif; ?>
            <?php if ($currentPage === 'anti-scraping.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <!-- Divider -->
        <div class="sidebar-divider"></div>
        
        <!-- Perfil -->
        <a href="profile.php" class="sidebar-item <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>" data-tooltip="Meu Perfil">
            <i class="fas fa-user-circle sidebar-icon"></i>
            <span class="sidebar-text">Meu Perfil</span>
            <?php if ($currentPage === 'profile.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>
        
        <!-- Configurações -->
        <a href="settings.php" class="sidebar-item <?php echo ($currentPage === 'settings.php') ? 'active' : ''; ?>" data-tooltip="Configurações">
            <i class="fas fa-cog sidebar-icon"></i>
            <span class="sidebar-text">Configurações</span>
            <?php if ($currentPage === 'settings.php'): ?>
            <div class="sidebar-indicator"></div>
            <?php endif; ?>
        </a>

        <!-- Changelog -->
        <a href="../changelog.php" target="_blank" class="sidebar-item" data-tooltip="Changelog">
            <i class="fas fa-file-alt sidebar-icon"></i>
            <span class="sidebar-text">Changelog</span>
        </a>
        
        <!-- Divider -->
        <div class="sidebar-divider"></div>
        
        <!-- Logout -->
        <a href="logout.php" class="sidebar-item logout-item" data-tooltip="Sair">
            <i class="fas fa-sign-out-alt sidebar-icon"></i>
            <span class="sidebar-text">Sair</span>
        </a>
    </nav>
    
   
    </div>
</div>

<style>
/* Sidebar Base Styles */
.sidebar-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    margin: 0.5rem 0.5rem 0;
    border-radius: 0.5rem;
    color: #808080;
    transition: all 0.3s ease;
    position: relative;
    text-decoration: none;
    overflow: hidden;
}

.sidebar-item:hover {
    color: #c0c0c0;
    background-color: #242424;
    transform: translateX(2px);
}

.sidebar-item.active {
    background-color: #141414;
    color: #c0c0c0;
    border-left: 4px solid #c0c0c0;
    box-shadow: 0 0 20px rgba(192, 192, 192, 0.15);
}

/* Super Admin Item - Destaque Especial */
.sidebar-item-admin {
    background: linear-gradient(90deg, rgba(147, 51, 234, 0.1) 0%, transparent 100%);
    border-left: 3px solid #9333ea;
}

.sidebar-item-admin:hover {
    background: linear-gradient(90deg, rgba(147, 51, 234, 0.2) 0%, transparent 100%);
    color: #c084fc;
}

.sidebar-item-admin.active {
    background: linear-gradient(90deg, rgba(147, 51, 234, 0.15) 0%, transparent 100%);
    border-left: 4px solid #9333ea;
    box-shadow: 0 0 20px rgba(147, 51, 234, 0.3);
}

.sidebar-admin-badge {
    padding: 0.15rem 0.4rem;
    background: linear-gradient(135deg, #9333ea 0%, #7c3aed 100%);
    color: white;
    font-size: 0.65rem;
    font-weight: 700;
    border-radius: 0.25rem;
    flex-shrink: 0;
    transition: opacity 0.3s ease;
    letter-spacing: 0.5px;
}

.sidebar-item.logout-item:hover {
    color: #f87171;
}

.sidebar-icon {
    font-size: 1.25rem;
    min-width: 1.25rem;
    text-align: center;
    flex-shrink: 0;
    transition: transform 0.3s ease;
}

.sidebar-item:hover .sidebar-icon {
    transform: scale(1.1);
}

.sidebar-text {
    font-weight: 500;
    white-space: nowrap;
    flex: 1;
    transition: opacity 0.3s ease;
}

.sidebar-badge {
    padding: 0.25rem 0.5rem;
    background-color: #fbbf24;
    color: #000;
    font-size: 0.75rem;
    font-weight: 700;
    border-radius: 9999px;
    flex-shrink: 0;
    transition: opacity 0.3s ease;
}

.sidebar-pulse {
    width: 0.5rem;
    height: 0.5rem;
    background-color: #f87171;
    border-radius: 9999px;
    animation: pulse 2s ease-in-out infinite;
    flex-shrink: 0;
    transition: opacity 0.3s ease;
}

.sidebar-indicator {
    width: 0.5rem;
    height: 0.5rem;
    background-color: #c0c0c0;
    border-radius: 9999px;
    box-shadow: 0 0 20px rgba(192, 192, 192, 0.15);
    flex-shrink: 0;
    transition: opacity 0.3s ease;
}

.sidebar-divider {
    border-top: 1px solid #2a2a2a;
    margin: 1.5rem 1rem;
    transition: margin 0.3s ease;
}

/* Collapsed State */
#sidebar.collapsed {
    width: 5rem;
}

#sidebar.collapsed .sidebar-item {
    justify-content: center;
    gap: 0;
    padding: 1rem 0.5rem;
    border-left: none;
}

#sidebar.collapsed .sidebar-item.active {
    border-left: none;
    border-bottom: 4px solid #c0c0c0;
}

#sidebar.collapsed .sidebar-item-admin {
    border-left: none;
    border-bottom: 3px solid #9333ea;
}

#sidebar.collapsed .sidebar-item-admin.active {
    border-bottom: 4px solid #9333ea;
}

#sidebar.collapsed .sidebar-text,
#sidebar.collapsed .sidebar-badge,
#sidebar.collapsed .sidebar-admin-badge,
#sidebar.collapsed .sidebar-pulse,
#sidebar.collapsed .sidebar-indicator {
    opacity: 0;
    width: 0;
    padding: 0;
    margin: 0;
}

#sidebar.collapsed #userInfo {
    opacity: 0;
    width: 0;
    overflow: hidden;
}

#sidebar.collapsed #licenseInfo {
    opacity: 0;
    height: 0;
    padding: 0;
    overflow: hidden;
}

#sidebar.collapsed .sidebar-divider {
    margin: 1rem 0.5rem;
}

#sidebar.collapsed #toggleIcon {
    transform: rotate(180deg);
}

#sidebar.collapsed #logoText {
    opacity: 0;
}

#sidebar.collapsed #logoIcon {
    opacity: 1;
}

/* Tooltips */
#sidebar.collapsed .sidebar-item::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%);
    margin-left: 0.75rem;
    padding: 0.5rem 0.75rem;
    background-color: #1a1a1a;
    border: 1px solid #2a2a2a;
    border-radius: 0.5rem;
    color: #c0c0c0;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
    z-index: 1000;
}

#sidebar.collapsed .sidebar-item:hover::after {
    opacity: 1;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const adjustableElements = document.querySelectorAll('.ml-64');
    
    // Verificar estado salvo
    const isSidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (isSidebarCollapsed) {
        sidebar.classList.add('collapsed');
        adjustableElements.forEach(el => {
            el.classList.remove('ml-64');
            el.classList.add('ml-20');
        });
    }
    
    // Toggle ao clicar
    toggleBtn.addEventListener('click', function() {
        const isCollapsed = sidebar.classList.toggle('collapsed');
        
        adjustableElements.forEach(el => {
            if (isCollapsed) {
                el.classList.remove('ml-64');
                el.classList.add('ml-20');
            } else {
                el.classList.remove('ml-20');
                el.classList.add('ml-64');
            }
        });
        
        localStorage.setItem('sidebarCollapsed', isCollapsed);
    });
});
</script>