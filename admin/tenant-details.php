<?php
/**
 * admin/tenant-details.php
 * Página de detalhes e edição de tenant
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/TenantManager.php';
require_once '../core/TenantMiddleware.php';

session_start();

$db = Database::getInstance();
$auth = new Auth();
$tenantManager = new TenantManager($db);
$tenantMiddleware = new TenantMiddleware($tenantManager, $auth);
$tenantMiddleware->handle();

$GLOBALS['tenantMiddleware'] = $tenantMiddleware;

if (!$tenantMiddleware->isSuperAdmin()) {
    header('Location: index.php');
    exit;
}

$tenantId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$tenantId) {
    header('Location: tenants.php');
    exit;
}

// Processar atualização
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $updateData = [
        'name' => $_POST['name'],
        'email' => $_POST['email'],
        'phone' => $_POST['phone'] ?? '',
        'plan' => $_POST['plan'],
        'status' => $_POST['status'],
        'max_campaigns' => (int)$_POST['max_campaigns'],
        'max_users' => (int)$_POST['max_users'],
        'max_visitors_month' => (int)$_POST['max_visitors_month']
    ];
    
    if ($tenantManager->updateTenant($tenantId, $updateData)) {
        $message = 'Cliente atualizado com sucesso!';
        $messageType = 'success';
        // Recarregar dados
        header("Location: tenant-details.php?id=$tenantId&updated=1");
        exit;
    } else {
        $message = 'Erro ao atualizar cliente';
        $messageType = 'error';
    }
}

if (isset($_GET['updated'])) {
    $message = 'Cliente atualizado com sucesso!';
    $messageType = 'success';
}

$tenant = $tenantManager->getTenant($tenantId);
if (!$tenant) die('Tenant não encontrado');

$stats = $tenantManager->getTenantStats($tenantId) ?: ['campaigns' => 0, 'users' => 0, 'visitors_month' => 0];

// Campanhas
$campaigns = [];
$result = $db->query("SELECT * FROM campaigns WHERE tenant_id = $tenantId ORDER BY created_at DESC");
$campaigns = $result->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Usuários
$users = [];
$result = $db->query("SELECT id, username, email, role, is_tenant_owner, status, last_login, created_at FROM users WHERE tenant_id = $tenantId ORDER BY is_tenant_owner DESC, created_at DESC");
$users = $result->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Atividades
$activities = [];
$result = $db->query("SELECT * FROM tenant_activities WHERE tenant_id = $tenantId ORDER BY created_at DESC LIMIT 20");
$activities = $result->fetchAll(PDO::FETCH_ASSOC) ?: [];

$campaignsUsage = $tenant['max_campaigns'] > 0 ? ($stats['campaigns'] / $tenant['max_campaigns']) * 100 : 0;
$usersUsage = $tenant['max_users'] > 0 ? ($stats['users'] / $tenant['max_users']) * 100 : 0;
$visitorsUsage = $tenant['max_visitors_month'] > 0 ? ($stats['visitors_month'] / $tenant['max_visitors_month']) * 100 : 0;

require_once 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes - <?= htmlspecialchars($tenant['name']) ?></title>
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
        .fade-in { animation: fadeIn 0.5s ease-out; }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #808080; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #c0c0c0; }
        body::before { content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.03) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.03) 0%, transparent 50%); pointer-events: none; z-index: 0; }
        .tab-button.active { border-bottom: 2px solid #60a5fa; color: #60a5fa; }
        .tab-button { color: #808080; }
    </style>
</head>
<body class="bg-dark text-silver">
    
<div class="ml-64 min-h-screen relative z-10">
    <?php 
    $pageTitle = 'Detalhes do Cliente';
    $pageSubtitle = htmlspecialchars($tenant['name']);
    require_once 'header.php'; 
    ?>

    <main class="p-8">
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg fade-in <?= $messageType === 'success' ? 'bg-accent-success/20 border border-accent-success text-accent-success' : 'bg-accent-danger/20 border border-accent-danger text-accent-danger' ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> mr-2"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="mb-6">
            <a href="tenants.php" class="inline-flex items-center gap-2 text-silver-dark hover:text-silver transition-colors">
                <i class="fas fa-arrow-left"></i> Voltar para Clientes
            </a>
        </div>

        <!-- Header -->
        <div class="bg-dark-card border border-[#2a2a2a] rounded-xl p-6 mb-6 hover:shadow-glow transition-all fade-in">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-600 to-purple-600 rounded-xl flex items-center justify-center text-white text-2xl font-bold shadow-glow">
                        <?= strtoupper(substr($tenant['name'], 0, 2)) ?>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-silver"><?= htmlspecialchars($tenant['name']) ?></h2>
                        <p class="text-silver-muted">
                            <i class="fas fa-envelope mr-1"></i> <?= htmlspecialchars($tenant['email']) ?>
                        </p>
                    </div>
                </div>
                <button onclick="switchTab('settings')" class="px-4 py-2 bg-accent-warning hover:bg-yellow-600 rounded-lg text-dark font-semibold transition-all hover:scale-105">
                    <i class="fas fa-edit mr-2"></i> Editar
                </button>
            </div>

            <div class="grid grid-cols-4 gap-4">
                <div class="text-center p-3 bg-dark-tertiary rounded-lg hover:bg-dark-hover transition-all">
                    <p class="text-sm text-silver-dark mb-1">Status</p>
                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-accent-<?= $tenant['status'] === 'active' ? 'success' : ($tenant['status'] === 'trial' ? 'warning' : 'danger') ?>/20 text-accent-<?= $tenant['status'] === 'active' ? 'success' : ($tenant['status'] === 'trial' ? 'warning' : 'danger') ?>">
                        <?= ucfirst($tenant['status']) ?>
                    </span>
                </div>

                <div class="text-center p-3 bg-dark-tertiary rounded-lg hover:bg-dark-hover transition-all">
                    <p class="text-sm text-silver-dark mb-1">Plano</p>
                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-accent-info/20 text-accent-info">
                        <?= strtoupper($tenant['plan']) ?>
                    </span>
                </div>

                <div class="text-center p-3 bg-dark-tertiary rounded-lg hover:bg-dark-hover transition-all">
                    <p class="text-sm text-silver-dark mb-1">Cliente desde</p>
                    <p class="font-bold text-silver"><?= date('d/m/Y', strtotime($tenant['created_at'])) ?></p>
                </div>

                <div class="text-center p-3 bg-dark-tertiary rounded-lg hover:bg-dark-hover transition-all">
                    <p class="text-sm text-silver-dark mb-1">Slug</p>
                    <code class="text-sm text-accent-info"><?= $tenant['slug'] ?></code>
                </div>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-info hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-accent-info opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-rocket"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-accent-info/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-rocket text-2xl text-accent-info"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-silver"><?= $stats['campaigns'] ?></p>
                            <p class="text-sm text-silver-muted">de <?= $tenant['max_campaigns'] ?></p>
                        </div>
                    </div>
                    <p class="text-sm text-silver-dark mb-2">Campanhas Ativas</p>
                    <div class="w-full bg-dark-tertiary rounded-full h-2">
                        <div class="h-2 rounded-full bg-accent-info transition-all shadow-glow" style="width: <?= min(100, $campaignsUsage) ?>%"></div>
                    </div>
                    <p class="text-xs text-right mt-1 text-silver-muted"><?= number_format($campaignsUsage, 1) ?>% usado</p>
                </div>
            </div>

            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-accent-success hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-accent-success opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-users"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-accent-success/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-2xl text-accent-success"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-silver"><?= $stats['users'] ?></p>
                            <p class="text-sm text-silver-muted">de <?= $tenant['max_users'] ?></p>
                        </div>
                    </div>
                    <p class="text-sm text-silver-dark mb-2">Usuários</p>
                    <div class="w-full bg-dark-tertiary rounded-full h-2">
                        <div class="h-2 rounded-full bg-accent-success transition-all shadow-glow" style="width: <?= min(100, $usersUsage) ?>%"></div>
                    </div>
                    <p class="text-xs text-right mt-1 text-silver-muted"><?= number_format($usersUsage, 1) ?>% usado</p>
                </div>
            </div>

            <div class="relative bg-dark-card border border-[#2a2a2a] rounded-xl p-6 hover:border-purple-500 hover:shadow-glow transition-all duration-300 hover:scale-105 fade-in overflow-hidden group">
                <div class="absolute top-4 right-4 text-5xl text-purple-400 opacity-10 group-hover:opacity-20 transition-opacity">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-purple-500/20 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-2xl text-purple-400"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-bold text-silver"><?= number_format($stats['visitors_month']) ?></p>
                            <p class="text-sm text-silver-muted">de <?= number_format($tenant['max_visitors_month']) ?></p>
                        </div>
                    </div>
                    <p class="text-sm text-silver-dark mb-2">Visitantes/Mês</p>
                    <div class="w-full bg-dark-tertiary rounded-full h-2">
                        <div class="h-2 rounded-full <?= $visitorsUsage > 90 ? 'bg-accent-danger' : 'bg-purple-500' ?> transition-all shadow-glow" 
                             style="width: <?= min(100, $visitorsUsage) ?>%"></div>
                    </div>
                    <p class="text-xs text-right mt-1 text-silver-muted"><?= number_format($visitorsUsage, 1) ?>% usado</p>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="bg-dark-card border border-[#2a2a2a] rounded-xl overflow-hidden fade-in">
            <div class="flex border-b border-[#2a2a2a] overflow-x-auto bg-dark-tertiary">
                <button onclick="switchTab('campaigns')" id="tab-campaigns" class="tab-button active px-6 py-4 font-semibold transition-all whitespace-nowrap hover:bg-dark-hover">
                    <i class="fas fa-rocket mr-2"></i> Campanhas (<?= count($campaigns) ?>)
                </button>
                <button onclick="switchTab('users')" id="tab-users" class="tab-button px-6 py-4 font-semibold transition-all whitespace-nowrap hover:bg-dark-hover">
                    <i class="fas fa-users mr-2"></i> Usuários (<?= count($users) ?>)
                </button>
                <button onclick="switchTab('activity')" id="tab-activity" class="tab-button px-6 py-4 font-semibold transition-all whitespace-nowrap hover:bg-dark-hover">
                    <i class="fas fa-history mr-2"></i> Atividades (<?= count($activities) ?>)
                </button>
                <button onclick="switchTab('settings')" id="tab-settings" class="tab-button px-6 py-4 font-semibold transition-all whitespace-nowrap hover:bg-dark-hover">
                    <i class="fas fa-cog mr-2"></i> Configurações
                </button>
            </div>

            <!-- Tab: Campanhas -->
            <div id="content-campaigns" class="tab-content p-6">
                <?php if (empty($campaigns)): ?>
                <div class="text-center py-12 text-silver-dark">
                    <i class="fas fa-rocket text-5xl mb-4 opacity-30"></i>
                    <p>Nenhuma campanha criada ainda</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-[#2a2a2a]">
                            <tr>
                                <th class="text-left py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Campanha</th>
                                <th class="text-left py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Status</th>
                                <th class="text-left py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Modo</th>
                                <th class="text-right py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Visitantes</th>
                                <th class="text-right py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Criada em</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#2a2a2a]">
                            <?php foreach ($campaigns as $camp): ?>
                            <tr class="hover:bg-dark-hover transition-all">
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-silver"><?= htmlspecialchars($camp['name']) ?></div>
                                    <div class="text-xs text-silver-dark"><?= htmlspecialchars($camp['slug']) ?></div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 rounded text-xs font-semibold <?= $camp['status'] === 'active' ? 'bg-accent-success/20 text-accent-success' : 'bg-silver-dark/20 text-silver-dark' ?>">
                                        <?= ucfirst($camp['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-sm text-silver-muted"><?= ucfirst($camp['mode']) ?></td>
                                <td class="py-3 px-4 text-right font-semibold text-silver"><?= number_format($camp['visitors_count'] ?? 0) ?></td>
                                <td class="py-3 px-4 text-right text-sm text-silver-muted"><?= date('d/m/Y', strtotime($camp['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Usuários -->
            <div id="content-users" class="tab-content p-6 hidden">
                <?php if (empty($users)): ?>
                <div class="text-center py-12 text-silver-dark">
                    <i class="fas fa-users text-5xl mb-4 opacity-30"></i>
                    <p>Nenhum usuário cadastrado</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-[#2a2a2a]">
                            <tr>
                                <th class="text-left py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Usuário</th>
                                <th class="text-left py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Role</th>
                                <th class="text-left py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Status</th>
                                <th class="text-right py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Último Login</th>
                                <th class="text-right py-3 px-4 text-sm text-silver-dark font-semibold uppercase">Criado em</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#2a2a2a]">
                            <?php foreach ($users as $userItem): ?>
                            <tr class="hover:bg-dark-hover transition-all">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <?php if ($userItem['is_tenant_owner']): ?>
                                        <i class="fas fa-crown text-accent-warning" title="Dono da conta"></i>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-semibold text-silver"><?= htmlspecialchars($userItem['username']) ?></div>
                                            <div class="text-xs text-silver-dark"><?= htmlspecialchars($userItem['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-sm text-silver-muted"><?= ucfirst($userItem['role']) ?></td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 rounded text-xs font-semibold <?= $userItem['status'] === 'active' ? 'bg-accent-success/20 text-accent-success' : 'bg-accent-danger/20 text-accent-danger' ?>">
                                        <?= ucfirst($userItem['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right text-sm text-silver-muted">
                                    <?= $userItem['last_login'] ? date('d/m/Y H:i', strtotime($userItem['last_login'])) : 'Nunca' ?>
                                </td>
                                <td class="py-3 px-4 text-right text-sm text-silver-muted"><?= date('d/m/Y', strtotime($userItem['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Atividades -->
            <div id="content-activity" class="tab-content p-6 hidden">
                <?php if (empty($activities)): ?>
                <div class="text-center py-12 text-silver-dark">
                    <i class="fas fa-history text-5xl mb-4 opacity-30"></i>
                    <p>Nenhuma atividade registrada</p>
                </div>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($activities as $activity): ?>
                    <div class="flex items-start gap-4 p-4 bg-dark-tertiary rounded-lg hover:bg-dark-hover transition-all">
                        <div class="w-10 h-10 bg-accent-info/20 rounded-lg flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-circle text-accent-info text-xs"></i>
                        </div>
                        <div class="flex-1">
                            <p class="font-semibold text-silver"><?= htmlspecialchars($activity['description']) ?></p>
                            <p class="text-sm text-silver-muted mt-1">
                                <?= date('d/m/Y H:i:s', strtotime($activity['created_at'])) ?>
                                <?php if (!empty($activity['ip_address'])): ?>
                                • IP: <?= htmlspecialchars($activity['ip_address']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Configurações -->
            <div id="content-settings" class="tab-content p-6 hidden">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update">
                    
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-silver mb-2">Nome da Empresa</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($tenant['name']) ?>" required
                                   class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-silver mb-2">Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($tenant['email']) ?>" required
                                   class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-silver mb-2">Telefone</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($tenant['phone'] ?? '') ?>" 
                                   class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-silver mb-2">Plano</label>
                            <select name="plan" required class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                                <option value="free" <?= $tenant['plan'] === 'free' ? 'selected' : '' ?>>Free (5 campanhas)</option>
                                <option value="basic" <?= $tenant['plan'] === 'basic' ? 'selected' : '' ?>>Basic (20 campanhas)</option>
                                <option value="pro" <?= $tenant['plan'] === 'pro' ? 'selected' : '' ?>>Pro (100 campanhas)</option>
                                <option value="enterprise" <?= $tenant['plan'] === 'enterprise' ? 'selected' : '' ?>>Enterprise (Ilimitado)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-silver mb-2">Status</label>
                        <select name="status" required class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                            <option value="active" <?= $tenant['status'] === 'active' ? 'selected' : '' ?>>Ativo</option>
                            <option value="trial" <?= $tenant['status'] === 'trial' ? 'selected' : '' ?>>Trial</option>
                            <option value="suspended" <?= $tenant['status'] === 'suspended' ? 'selected' : '' ?>>Suspenso</option>
                            <option value="cancelled" <?= $tenant['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>

                    <div class="border-t border-[#2a2a2a] pt-6">
                        <h3 class="text-lg font-bold text-silver mb-4">Limites de Recursos</h3>
                        <div class="grid grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-semibold text-silver mb-2">Máx. Campanhas</label>
                                <input type="number" name="max_campaigns" value="<?= $tenant['max_campaigns'] ?>" min="1" required
                                       class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-silver mb-2">Máx. Usuários</label>
                                <input type="number" name="max_users" value="<?= $tenant['max_users'] ?>" min="1" required
                                       class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-silver mb-2">Máx. Visitantes/Mês</label>
                                <input type="number" name="max_visitors_month" value="<?= $tenant['max_visitors_month'] ?>" min="1000" required
                                       class="w-full bg-dark-tertiary border border-[#2a2a2a] rounded-lg px-4 py-2 text-silver focus:border-accent-info focus:outline-none transition-all">
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-4 pt-4">
                        <button type="submit" class="px-6 py-3 bg-gradient-silver text-dark rounded-lg font-semibold transition-all hover:shadow-glow hover:scale-105">
                            <i class="fas fa-save mr-2"></i> Salvar Alterações
                        </button>
                        <a href="tenants.php" class="px-6 py-3 bg-dark-tertiary border border-[#2a2a2a] text-silver rounded-lg font-semibold transition-all hover:bg-dark-hover inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i> Voltar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <?php require_once 'footer.php'; ?>
</div>

<script>
function switchTab(tabName) {
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active', 'border-b-2', 'border-accent-info', 'text-accent-info');
        btn.classList.add('text-silver-dark');
    });
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });

    const button = document.getElementById('tab-' + tabName);
    const content = document.getElementById('content-' + tabName);
    
    button.classList.add('active', 'border-b-2', 'border-accent-info', 'text-accent-info');
    button.classList.remove('text-silver-dark');
    content.classList.remove('hidden');
}
</script>
</body>
</html>