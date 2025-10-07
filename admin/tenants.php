<?php
/**
 * admin/tenants.php
 * Interface de Gerenciamento de Clientes (apenas Super Admin)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/TenantManager.php';
require_once '../core/TenantMiddleware.php';
require_once '../core/Utils.php';

session_start();

$db = Database::getInstance();
$auth = new Auth();
$tenantManager = new TenantManager($db);
$tenantMiddleware = new TenantMiddleware($tenantManager, $auth);
$tenantMiddleware->handle();

// Tornar disponível globalmente
$GLOBALS['tenantMiddleware'] = $tenantMiddleware;

// Verificar se é super admin
if (!$tenantMiddleware->isSuperAdmin()) {
    header('Location: index.php');
    exit;
}

$user = $auth->getCurrentUser();

// Processar ações
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $result = $tenantManager->createTenant([
                'name' => $_POST['name'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'] ?? '',
                'plan' => $_POST['plan'],
                'status' => 'active',
                'owner_username' => $_POST['owner_username'],
                'owner_password' => $_POST['owner_password']
            ]);
            
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'error';
            break;
            
        case 'update':
            $tenantId = $_POST['tenant_id'];
            
            // Preparar dados de atualização
            $updateData = [
                'name' => $_POST['name'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'] ?? '',
                'plan' => $_POST['plan'],
                'status' => $_POST['status']
            ];
            
            // Adicionar limites se fornecidos
            if (isset($_POST['max_campaigns'])) {
                $updateData['max_campaigns'] = (int)$_POST['max_campaigns'];
            }
            if (isset($_POST['max_users'])) {
                $updateData['max_users'] = (int)$_POST['max_users'];
            }
            if (isset($_POST['max_visitors_month'])) {
                $updateData['max_visitors_month'] = (int)$_POST['max_visitors_month'];
            }
            
            $result = $tenantManager->updateTenant($tenantId, $updateData);
            
            $message = $result ? 'Cliente atualizado!' : 'Erro ao atualizar';
            $messageType = $result ? 'success' : 'error';
            break;
            
        case 'delete':
            $tenantId = $_POST['tenant_id'];
            $result = $tenantManager->deleteTenant($tenantId);
            
            $message = $result ? 'Cliente deletado!' : 'Erro ao deletar';
            $messageType = $result ? 'success' : 'error';
            break;
    }
}

// Listar tenants
$tenants = $tenantManager->listTenants();

require_once 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Clientes - Cloaker Pro</title>
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
        .pulse-glow { animation: pulse 2s ease-in-out infinite; }
        
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
        
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
    </style>
</head>
<body class="bg-dark text-silver">

<div class="ml-64 min-h-screen relative z-10">
    <?php 
    $pageTitle = 'Gerenciar Clientes';
    $pageSubtitle = 'Controle total sobre todos os clientes';
    require_once 'header.php'; 
    ?>

    <main class="p-8">
        <!-- Mensagem -->
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg fade-in <?= $messageType === 'success' ? 'bg-accent-success/20 border border-accent-success text-accent-success' : 'bg-accent-danger/20 border border-accent-danger text-accent-danger' ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> mr-2"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Button Criar -->
        <div class="mb-6 flex justify-end">
            <button onclick="openCreateModal()" class="bg-gradient-silver text-dark px-6 py-2 rounded-lg flex items-center gap-2 font-semibold hover:shadow-glow transition-all hover:scale-105">
                <i class="fas fa-plus"></i>
                Novo Cliente
            </button>
        </div>

        <!-- Cards de Resumo -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-info hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-accent-info opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-building"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center gap-4 mb-2">
                        <div class="w-12 h-12 rounded-lg bg-accent-info/20 flex items-center justify-center">
                            <i class="fas fa-building text-2xl text-accent-info"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-silver"><?= count($tenants) ?></div>
                            <div class="text-sm text-silver-dark">Total Clientes</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-success hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-accent-success opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center gap-4 mb-2">
                        <div class="w-12 h-12 rounded-lg bg-accent-success/20 flex items-center justify-center">
                            <i class="fas fa-check-circle text-2xl text-accent-success"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-silver"><?= count(array_filter($tenants, fn($t) => $t['status'] === 'active')) ?></div>
                            <div class="text-sm text-silver-dark">Ativos</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-warning hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-accent-warning opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center gap-4 mb-2">
                        <div class="w-12 h-12 rounded-lg bg-accent-warning/20 flex items-center justify-center">
                            <i class="fas fa-clock text-2xl text-accent-warning"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-silver"><?= count(array_filter($tenants, fn($t) => $t['status'] === 'trial')) ?></div>
                            <div class="text-sm text-silver-dark">Em Trial</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-danger hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-accent-danger opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-pause-circle"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center gap-4 mb-2">
                        <div class="w-12 h-12 rounded-lg bg-accent-danger/20 flex items-center justify-center">
                            <i class="fas fa-pause-circle text-2xl text-accent-danger"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-silver"><?= count(array_filter($tenants, fn($t) => $t['status'] === 'suspended')) ?></div>
                            <div class="text-sm text-silver-dark">Suspensos</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabela de Clientes -->
        <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-dark-tertiary border-b border-[#2a2a2a]">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-silver-dark uppercase">Cliente</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-silver-dark uppercase">Plano</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-silver-dark uppercase">Status</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-silver-dark uppercase">Limites</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-silver-dark uppercase">Criado em</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-silver-dark uppercase">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2a2a2a]">
                        <?php if (empty($tenants)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-silver-dark">
                                <i class="fas fa-inbox text-5xl mb-4 opacity-30"></i>
                                <p>Nenhum cliente cadastrado ainda</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($tenants as $tenant): 
                            $stats = $tenantManager->getTenantStats($tenant['id']);
                        ?>
                        <tr class="hover:bg-dark-hover transition-all">
                            <td class="px-6 py-4">
                                <div>
                                    <div class="font-semibold text-silver"><?= htmlspecialchars($tenant['name']) ?></div>
                                    <div class="text-sm text-silver-muted"><?= htmlspecialchars($tenant['email']) ?></div>
                                    <div class="text-xs text-silver-dark mt-1">
                                        <code class="bg-dark-tertiary px-2 py-0.5 rounded"><?= $tenant['slug'] ?></code>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                    <?php
                                    switch($tenant['plan']) {
                                        case 'free': echo 'bg-silver-dark/20 text-silver-dark'; break;
                                        case 'basic': echo 'bg-accent-info/20 text-accent-info'; break;
                                        case 'pro': echo 'bg-purple-500/20 text-purple-400'; break;
                                        case 'enterprise': echo 'bg-accent-warning/20 text-accent-warning'; break;
                                    }
                                    ?>">
                                    <?= strtoupper($tenant['plan']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    switch($tenant['status']) {
                                        case 'active': echo 'bg-accent-success/20 text-accent-success'; break;
                                        case 'trial': echo 'bg-accent-warning/20 text-accent-warning'; break;
                                        case 'suspended': echo 'bg-accent-danger/20 text-accent-danger'; break;
                                        case 'cancelled': echo 'bg-silver-dark/20 text-silver-dark'; break;
                                    }
                                    ?>">
                                    <?= ucfirst($tenant['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm space-y-1">
                                    <div class="text-silver-muted">
                                        Campanhas: <span class="text-silver font-semibold"><?= $stats['campaigns'] ?>/<?= $tenant['max_campaigns'] ?></span>
                                    </div>
                                    <div class="text-silver-muted">
                                        Usuários: <span class="text-silver font-semibold"><?= $stats['users'] ?>/<?= $tenant['max_users'] ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-silver-muted">
                                <?= date('d/m/Y', strtotime($tenant['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end gap-2">
                                    <button onclick="viewTenant(<?= $tenant['id'] ?>)" 
                                            class="px-3 py-1 bg-accent-info/20 text-accent-info rounded hover:bg-accent-info/30 transition-all"
                                            title="Ver detalhes">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button onclick="deleteTenant(<?= $tenant['id'] ?>, '<?= htmlspecialchars($tenant['name'], ENT_QUOTES) ?>')" 
                                            class="px-3 py-1 bg-accent-danger/20 text-accent-danger rounded hover:bg-accent-danger/30 transition-all"
                                            title="Deletar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php require_once 'footer.php'; ?>
</div>

<!-- Modal Criar Cliente -->
<div id="createModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm flex items-center justify-center z-50">
    <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-8 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto shadow-glow-lg">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-silver">Novo Cliente</h2>
            <button onclick="closeCreateModal()" class="text-silver-dark hover:text-silver transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-silver mb-2 text-sm font-semibold">Nome da Empresa *</label>
                    <input type="text" name="name" required 
                           class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                </div>
                <div>
                    <label class="block text-silver mb-2 text-sm font-semibold">Email *</label>
                    <input type="email" name="email" required 
                           class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-silver mb-2 text-sm font-semibold">Telefone</label>
                    <input type="text" name="phone" 
                           class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                </div>
                <div>
                    <label class="block text-silver mb-2 text-sm font-semibold">Plano *</label>
                    <select name="plan" required 
                            class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                        <option value="free">Free (5 campanhas)</option>
                        <option value="basic">Basic (20 campanhas)</option>
                        <option value="pro">Pro (100 campanhas)</option>
                        <option value="enterprise">Enterprise (Ilimitado)</option>
                    </select>
                </div>
            </div>

            <hr class="border-[#2a2a2a]">

            <h3 class="text-lg font-semibold text-silver">Dados do Administrador</h3>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-silver mb-2 text-sm font-semibold">Username *</label>
                    <input type="text" name="owner_username" required 
                           class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                </div>
                <div>
                    <label class="block text-silver mb-2 text-sm font-semibold">Senha *</label>
                    <input type="password" name="owner_password" required 
                           class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                </div>
            </div>

            <div class="flex gap-4 pt-4">
                <button type="submit" class="flex-1 bg-gradient-silver text-dark px-6 py-3 rounded-lg font-semibold hover:shadow-glow transition-all hover:scale-105">
                    <i class="fas fa-check mr-2"></i>
                    Criar Cliente
                </button>
                <button type="button" onclick="closeCreateModal()" 
                        class="px-6 py-3 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg hover:bg-dark-hover transition-all">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
}

function viewTenant(id) {
    window.location.href = 'tenant-details.php?id=' + id;
}

function deleteTenant(id, name) {
    if (confirm(`Tem certeza que deseja deletar o cliente "${name}"?\n\nTodos os dados (campanhas, usuários, etc) serão permanentemente removidos!`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tenant_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

</body>
</html>