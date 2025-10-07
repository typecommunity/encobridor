<?php
/**
 * Gerenciamento de Domínios - Multi-Tenancy
 * Versão corrigida seguindo o padrão do index.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ==========================================
// MULTI-TENANCY: INICIALIZAR (igual ao index.php)
// ==========================================
require_once '../core/TenantManager.php';
require_once '../core/TenantMiddleware.php';

$db = Database::getInstance();
$tenantManager = new TenantManager($db);
$tenantMiddleware = new TenantMiddleware($tenantManager, $auth);
$tenantMiddleware->handle();

// Tornar disponível globalmente
$GLOBALS['tenantMiddleware'] = $tenantMiddleware;

// Obter informações do tenant atual
$user = $auth->getCurrentUser();
$currentTenant = $tenantMiddleware->getCurrentTenant();
$isSuperAdmin = $tenantMiddleware->isSuperAdmin();
$tenantId = $tenantMiddleware->getTenantId();

// Informações do servidor
$serverIP = $_SERVER['SERVER_ADDR'] ?? $_SERVER['SERVER_NAME'] ?? '0.0.0.0';

// Mensagens de sessão
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domínios - Cloaker Pro</title>
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
                    }
                }
            }
        }
    </script>
    
    <style>
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
    </style>
</head>
<body class="bg-dark text-silver">
    <?php require_once 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen relative z-10 transition-all duration-300 flex flex-col">
        <?php 
        $pageTitle = 'Gerenciamento de Domínios';
        if (!$isSuperAdmin && $currentTenant) {
            $pageSubtitle = 'Cliente: ' . htmlspecialchars($currentTenant['name']);
        }
        require_once 'header.php'; 
        ?>

        <!-- Content -->
        <main class="p-8 flex-1">
            <?php if (!$isSuperAdmin && $currentTenant): ?>
            <!-- Informações do Tenant -->
            <div class="bg-gradient-to-r from-blue-900/20 to-purple-900/20 border border-blue-500/30 rounded-xl p-4 mb-6 fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-purple-600 rounded-xl flex items-center justify-center text-white text-xl font-bold">
                            <?= strtoupper(substr($currentTenant['name'], 0, 2)) ?>
                        </div>
                        <div>
                            <p class="font-bold text-silver text-lg"><?= htmlspecialchars($currentTenant['name']) ?></p>
                            <p class="text-sm text-silver-muted">
                                Plano: <span class="font-semibold text-blue-400"><?= strtoupper($currentTenant['plan']) ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="bg-green-600/20 border border-green-500 p-4 rounded-lg mb-6 flex items-center gap-3 fade-in">
                <i class="fas fa-check-circle text-green-400 text-xl"></i>
                <span class="text-green-200"><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="bg-red-600/20 border border-red-500 p-4 rounded-lg mb-6 flex items-center gap-3 fade-in">
                <i class="fas fa-exclamation-circle text-red-400 text-xl"></i>
                <span class="text-red-200"><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <!-- Status do Sistema -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 fade-in">
                <h2 class="text-xl font-bold text-silver mb-4 flex items-center gap-2">
                    <i class="fas fa-info-circle text-accent-info"></i>
                    Informações do Sistema
                </h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-dark-tertiary p-4 rounded-lg border border-[#2a2a2a]">
                        <div class="text-silver-dark text-sm mb-1">Usuário</div>
                        <div class="font-bold text-silver"><?php echo htmlspecialchars($user['username']); ?></div>
                    </div>
                    <div class="bg-dark-tertiary p-4 rounded-lg border border-[#2a2a2a]">
                        <div class="text-silver-dark text-sm mb-1">Perfil</div>
                        <div class="font-bold text-silver"><?php echo $isSuperAdmin ? 'Super Admin' : 'Admin'; ?></div>
                    </div>
                    <div class="bg-dark-tertiary p-4 rounded-lg border border-[#2a2a2a]">
                        <div class="text-silver-dark text-sm mb-1">Multi-tenancy</div>
                        <div class="font-bold text-accent-success">Ativo</div>
                    </div>
                    <div class="bg-dark-tertiary p-4 rounded-lg border border-[#2a2a2a]">
                        <div class="text-silver-dark text-sm mb-1">IP Servidor</div>
                        <div class="font-bold text-silver text-sm"><?php echo $serverIP; ?></div>
                    </div>
                </div>
            </div>

            <!-- Tabela de Tenants -->
            <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
                <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-bold text-silver flex items-center gap-2">
                            <i class="fas fa-globe text-accent-info"></i>
                            Tenants e Domínios
                        </h2>
                        <?php if ($isSuperAdmin): ?>
                        <button class="px-4 py-2 bg-accent-success hover:bg-green-500 rounded-lg font-semibold text-dark transition-all flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            Adicionar Tenant
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="p-6">
                    <?php
                    try {
                        // Preparar query com filtro de tenant
                        $sql = "SELECT id, name, slug, domain, email, phone, status, plan, 
                                max_campaigns, max_users, max_visitors_month, created_at 
                                FROM tenants WHERE 1=1";
                        $params = [];
                        
                        // Se não for super admin, filtrar pelo tenant do usuário
                        if (!$isSuperAdmin && $tenantId) {
                            $sql .= " AND id = ?";
                            $params[] = $tenantId;
                        }
                        
                        $sql .= " ORDER BY id DESC";
                        
                        $result = $db->raw($sql, $params);
                        $tenants = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
                        
                        if (empty($tenants)) {
                            echo '<div class="text-center py-12 text-silver-dark">';
                            echo '<i class="fas fa-inbox text-5xl mb-4 opacity-30"></i>';
                            echo '<p class="text-lg">Nenhum tenant cadastrado</p>';
                            echo '</div>';
                        } else {
                            echo '<div class="overflow-x-auto">';
                            echo '<table class="w-full">';
                            echo '<thead>';
                            echo '<tr class="border-b border-[#2a2a2a]">';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">ID</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Nome</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Slug</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Domínio</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Email</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Plano</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Status</th>';
                            echo '<th class="text-left p-3 text-silver-dark font-semibold">Limites</th>';
                            if ($isSuperAdmin) {
                                echo '<th class="text-left p-3 text-silver-dark font-semibold">Ações</th>';
                            }
                            echo '</tr>';
                            echo '</thead>';
                            echo '<tbody>';
                            
                            foreach ($tenants as $tenant) {
                                $statusColors = [
                                    'active' => 'bg-accent-success text-dark',
                                    'suspended' => 'bg-accent-danger text-white',
                                    'trial' => 'bg-accent-warning text-dark',
                                    'cancelled' => 'bg-silver-dark text-white'
                                ];
                                $statusColor = $statusColors[$tenant['status']] ?? 'bg-silver-dark text-white';
                                
                                $planColors = [
                                    'free' => 'text-silver-dark',
                                    'basic' => 'text-accent-info',
                                    'pro' => 'text-purple-400',
                                    'enterprise' => 'text-accent-warning'
                                ];
                                $planColor = $planColors[$tenant['plan']] ?? 'text-silver-dark';
                                
                                $domain = !empty($tenant['domain']) ? htmlspecialchars($tenant['domain']) : '<span class="text-silver-dark italic">não configurado</span>';
                                
                                echo '<tr class="border-t border-[#2a2a2a] hover:bg-dark-hover transition-colors">';
                                echo '<td class="p-3 text-silver-muted">' . $tenant['id'] . '</td>';
                                echo '<td class="p-3">';
                                echo '<div class="font-semibold text-silver">' . htmlspecialchars($tenant['name']) . '</div>';
                                echo '<div class="text-xs text-silver-dark">' . date('d/m/Y', strtotime($tenant['created_at'])) . '</div>';
                                echo '</td>';
                                echo '<td class="p-3"><code class="text-sm bg-dark-tertiary px-2 py-1 rounded text-accent-info">' . htmlspecialchars($tenant['slug']) . '</code></td>';
                                echo '<td class="p-3">' . $domain . '</td>';
                                echo '<td class="p-3 text-sm text-silver-muted">' . htmlspecialchars($tenant['email']) . '</td>';
                                echo '<td class="p-3"><span class="' . $planColor . ' font-bold uppercase text-sm">' . $tenant['plan'] . '</span></td>';
                                echo '<td class="p-3"><span class="' . $statusColor . ' px-3 py-1 rounded-full text-xs font-semibold">' . ucfirst($tenant['status']) . '</span></td>';
                                echo '<td class="p-3 text-sm text-silver-muted">';
                                echo '<div>Campanhas: <span class="text-silver font-semibold">' . $tenant['max_campaigns'] . '</span></div>';
                                echo '<div>Visitantes: <span class="text-silver font-semibold">' . number_format($tenant['max_visitors_month']) . '/mês</span></div>';
                                echo '</td>';
                                
                                echo '<td class="p-3">';
                                echo '<div class="flex gap-2">';
                                
                                // Botão configurar domínio
                                echo '<button onclick="openDomainModal(' . $tenant['id'] . ', \'' . addslashes($tenant['name']) . '\', \'' . addslashes($tenant['domain'] ?? '') . '\')" ';
                                echo 'class="text-accent-info hover:text-blue-400 transition-colors" title="Configurar Domínio">';
                                echo '<i class="fas fa-globe"></i>';
                                echo '</button>';
                                
                                // Botão verificar DNS (só se tiver domínio)
                                if (!empty($tenant['domain'])) {
                                    echo '<button onclick="checkDNS(' . $tenant['id'] . ', \'' . addslashes($tenant['domain']) . '\')" ';
                                    echo 'class="text-accent-warning hover:text-yellow-400 transition-colors" title="Verificar DNS">';
                                    echo '<i class="fas fa-check-circle"></i>';
                                    echo '</button>';
                                    
                                    // Botão remover domínio
                                    echo '<button onclick="removeDomain(' . $tenant['id'] . ', \'' . addslashes($tenant['name']) . '\')" ';
                                    echo 'class="text-accent-danger hover:text-red-400 transition-colors" title="Remover Domínio">';
                                    echo '<i class="fas fa-times-circle"></i>';
                                    echo '</button>';
                                }
                                
                                echo '</div>';
                                echo '</td>';
                                
                                echo '</tr>';
                            }
                            
                            echo '</tbody>';
                            echo '</table>';
                            echo '</div>';
                        }
                    } catch (PDOException $e) {
                        echo '<div class="bg-accent-danger/20 border border-accent-danger p-4 rounded-lg">';
                        echo '<p class="font-bold text-accent-danger mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>Erro de Banco de Dados</p>';
                        echo '<p class="text-sm text-silver-muted">' . htmlspecialchars($e->getMessage()) . '</p>';
                        echo '</div>';
                    } catch (Exception $e) {
                        echo '<div class="bg-accent-danger/20 border border-accent-danger p-4 rounded-lg">';
                        echo '<p class="font-bold text-accent-danger mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>Erro</p>';
                        echo '<p class="text-sm text-silver-muted">' . htmlspecialchars($e->getMessage()) . '</p>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Instruções de Configuração -->
            <div class="mt-6 bg-dark-card border border-[#2a2a2a] rounded-xl p-6 fade-in">
                <h3 class="text-lg font-bold text-silver mb-4 flex items-center gap-2">
                    <i class="fas fa-question-circle text-accent-info"></i>
                    Como configurar domínios personalizados
                </h3>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-accent-info rounded-full flex items-center justify-center font-bold text-dark flex-shrink-0">1</div>
                        <div>
                            <p class="font-semibold text-silver mb-1">Configure o DNS</p>
                            <p class="text-sm text-silver-muted">Adicione um registro A apontando para: <code class="bg-dark-tertiary px-2 py-1 rounded text-accent-info"><?php echo $serverIP; ?></code></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-accent-info rounded-full flex items-center justify-center font-bold text-dark flex-shrink-0">2</div>
                        <div>
                            <p class="font-semibold text-silver mb-1">Adicione o domínio ao tenant</p>
                            <p class="text-sm text-silver-muted">Configure o domínio personalizado na edição do tenant</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-accent-info rounded-full flex items-center justify-center font-bold text-dark flex-shrink-0">3</div>
                        <div>
                            <p class="font-semibold text-silver mb-1">Aguarde a propagação</p>
                            <p class="text-sm text-silver-muted">A propagação do DNS pode levar até 24 horas</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-accent-success rounded-full flex items-center justify-center font-bold text-dark flex-shrink-0">4</div>
                        <div>
                            <p class="font-semibold text-silver mb-1">Acesse pelo domínio</p>
                            <p class="text-sm text-silver-muted">Após a propagação, o sistema estará acessível pelo domínio personalizado</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php require_once 'footer.php'; ?>
    </div>

    <!-- Modal: Configurar Domínio -->
    <div id="domainModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-dark-card border border-[#2a2a2a] rounded-xl max-w-2xl w-full shadow-glow animate-fadeIn">
            <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary flex items-center justify-between">
                <h3 class="text-xl font-bold text-silver flex items-center gap-2">
                    <i class="fas fa-globe text-accent-info"></i>
                    Configurar Domínio Personalizado
                </h3>
                <button onclick="closeDomainModal()" class="text-silver-dark hover:text-silver transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="domainForm" class="p-6 space-y-4">
                <input type="hidden" id="modal_tenant_id" name="tenant_id">
                
                <div>
                    <label class="block text-silver-dark text-sm font-semibold mb-2">Cliente</label>
                    <div id="modal_tenant_name" class="text-silver font-bold text-lg"></div>
                </div>
                
                <div>
                    <label for="domain_input" class="block text-silver-dark text-sm font-semibold mb-2">
                        Domínio Personalizado
                        <span class="text-accent-danger">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="domain_input" 
                        name="domain"
                        placeholder="exemplo.com.br"
                        class="w-full px-4 py-3 bg-dark-tertiary border border-[#2a2a2a] rounded-lg text-silver focus:border-accent-info focus:outline-none transition-colors"
                        required
                    >
                    <p class="text-xs text-silver-dark mt-2">
                        Digite apenas o domínio, sem http:// ou www
                    </p>
                </div>

                <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4">
                    <h4 class="font-semibold text-silver mb-2 flex items-center gap-2">
                        <i class="fas fa-info-circle text-accent-info"></i>
                        Configuração DNS Necessária
                    </h4>
                    <p class="text-sm text-silver-muted mb-3">
                        Antes de configurar o domínio aqui, adicione um registro DNS:
                    </p>
                    <div class="bg-dark-tertiary rounded-lg p-3 font-mono text-sm">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-silver-dark">Tipo:</span>
                            <span class="text-silver font-bold">A</span>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-silver-dark">Host:</span>
                            <span class="text-silver font-bold">@ (ou seu domínio)</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-silver-dark">IP:</span>
                            <span class="text-accent-success font-bold"><?php echo $serverIP; ?></span>
                        </div>
                    </div>
                </div>

                <div id="modal_error" class="hidden bg-accent-danger/20 border border-accent-danger rounded-lg p-3 text-accent-danger text-sm"></div>

                <div class="flex gap-3 pt-4">
                    <button 
                        type="submit" 
                        class="flex-1 px-6 py-3 bg-accent-success hover:bg-green-500 text-dark font-bold rounded-lg transition-all flex items-center justify-center gap-2"
                    >
                        <i class="fas fa-save"></i>
                        Salvar Domínio
                    </button>
                    <button 
                        type="button" 
                        onclick="closeDomainModal()" 
                        class="px-6 py-3 bg-dark-tertiary hover:bg-dark-hover text-silver border border-[#2a2a2a] rounded-lg transition-all"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Status DNS -->
    <div id="dnsModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-dark-card border border-[#2a2a2a] rounded-xl max-w-lg w-full shadow-glow animate-fadeIn">
            <div class="p-6 border-b border-[#2a2a2a] bg-dark-tertiary flex items-center justify-between">
                <h3 class="text-xl font-bold text-silver flex items-center gap-2">
                    <i class="fas fa-check-circle text-accent-warning"></i>
                    Verificação de DNS
                </h3>
                <button onclick="closeDnsModal()" class="text-silver-dark hover:text-silver transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="dns_result" class="p-6">
                <div class="flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-accent-info"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Abrir modal de domínio
        function openDomainModal(tenantId, tenantName, currentDomain) {
            document.getElementById('modal_tenant_id').value = tenantId;
            document.getElementById('modal_tenant_name').textContent = tenantName;
            document.getElementById('domain_input').value = currentDomain || '';
            document.getElementById('modal_error').classList.add('hidden');
            document.getElementById('domainModal').classList.remove('hidden');
        }

        // Fechar modal de domínio
        function closeDomainModal() {
            document.getElementById('domainModal').classList.add('hidden');
            document.getElementById('domainForm').reset();
        }

        // Fechar modal de DNS
        function closeDnsModal() {
            document.getElementById('dnsModal').classList.add('hidden');
        }

        // Submeter formulário de domínio
        document.getElementById('domainForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'set_domain');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            const errorDiv = document.getElementById('modal_error');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Salvando...';
            errorDiv.classList.add('hidden');
            
            try {
                const response = await fetch('domain-update.php', {
                    method: 'POST',
                    body: formData
                });
                
                // Verificar se a resposta é JSON válido
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Resposta não-JSON:', text);
                    throw new Error('Resposta inválida do servidor. Verifique o console para detalhes.');
                }
                
                const data = await response.json();
                console.log('Resposta:', data); // Debug
                
                if (data.success) {
                    closeDomainModal();
                    showNotification('success', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    errorDiv.textContent = data.message || 'Erro desconhecido';
                    errorDiv.classList.remove('hidden');
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                    }
                }
            } catch (error) {
                console.error('Erro completo:', error);
                errorDiv.textContent = 'Erro: ' + error.message;
                errorDiv.classList.remove('hidden');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });

        // Verificar DNS
        async function checkDNS(tenantId, domain) {
            document.getElementById('dnsModal').classList.remove('hidden');
            document.getElementById('dns_result').innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-accent-info"></div>
                </div>
            `;
            
            const formData = new FormData();
            formData.append('action', 'check_dns');
            formData.append('domain', domain);
            formData.append('tenant_id', tenantId);
            
            try {
                const response = await fetch('domain-update.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                let resultHTML = '';
                
                if (data.dns_configured) {
                    resultHTML = `
                        <div class="text-center">
                            <div class="w-20 h-20 bg-accent-success/20 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-check-circle text-4xl text-accent-success"></i>
                            </div>
                            <h4 class="text-xl font-bold text-accent-success mb-2">DNS Configurado!</h4>
                            <p class="text-silver-muted mb-4">O domínio está apontando corretamente para este servidor</p>
                            <div class="bg-dark-tertiary rounded-lg p-4 text-left">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-silver-dark">Domínio:</span>
                                    <span class="text-silver font-bold">${domain}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-silver-dark">IP do Servidor:</span>
                                    <span class="text-accent-success font-bold">${data.server_ip}</span>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    resultHTML = `
                        <div class="text-center">
                            <div class="w-20 h-20 bg-accent-warning/20 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-exclamation-triangle text-4xl text-accent-warning"></i>
                            </div>
                            <h4 class="text-xl font-bold text-accent-warning mb-2">DNS Não Configurado</h4>
                            <p class="text-silver-muted mb-4">O domínio não está apontando para este servidor</p>
                            <div class="bg-dark-tertiary rounded-lg p-4 text-left mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-silver-dark">IP do Servidor:</span>
                                    <span class="text-accent-success font-bold">${data.server_ip}</span>
                                </div>
                                ${data.found_ips.length > 0 ? `
                                <div class="flex items-center justify-between">
                                    <span class="text-silver-dark">IPs Encontrados:</span>
                                    <span class="text-accent-danger font-bold">${data.found_ips.join(', ')}</span>
                                </div>
                                ` : `
                                <div class="text-accent-danger text-sm">Nenhum registro DNS encontrado</div>
                                `}
                            </div>
                            <p class="text-sm text-silver-dark">Configure o DNS apontando para o IP do servidor e aguarde a propagação (até 24h)</p>
                        </div>
                    `;
                }
                
                document.getElementById('dns_result').innerHTML = resultHTML;
                
            } catch (error) {
                document.getElementById('dns_result').innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-exclamation-circle text-4xl text-accent-danger mb-4"></i>
                        <p class="text-accent-danger">Erro ao verificar DNS</p>
                    </div>
                `;
            }
        }

        // Remover domínio
        async function removeDomain(tenantId, tenantName) {
            if (!confirm(`Tem certeza que deseja remover o domínio personalizado de "${tenantName}"?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'remove_domain');
            formData.append('tenant_id', tenantId);
            
            try {
                const response = await fetch('domain-update.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('success', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('error', data.message);
                }
            } catch (error) {
                showNotification('error', 'Erro ao remover domínio');
            }
        }

        // Notificação toast
        function showNotification(type, message) {
            const colors = {
                success: 'bg-accent-success',
                error: 'bg-accent-danger',
                warning: 'bg-accent-warning',
                info: 'bg-accent-info'
            };
            
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 ${colors[type]} text-dark px-6 py-4 rounded-lg shadow-glow z-50 animate-fadeIn flex items-center gap-3`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span class="font-semibold">${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                notification.style.transition = 'all 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Fechar modais com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDomainModal();
                closeDnsModal();
            }
        });
    </script>

    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .animate-fadeIn {
            animation: fadeIn 0.2s ease-out;
        }
    </style>
</body>
</html>