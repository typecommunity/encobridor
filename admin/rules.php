<?php
/**
 * Cloaker Pro - Rules Management
 * Gerenciamento de regras globais
 */

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Rules.php';
require_once '../core/Campaign.php';
require_once '../core/Utils.php';

// Verificar autenticação
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $auth->getCurrentUser();
$rules = new Rules();
$campaign = new Campaign();

$message = '';
$error = '';
$editRule = null;

// Processar ações
if (Utils::isPost()) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save':
            $ruleData = [
                'name' => Utils::sanitize($_POST['name'] ?? ''),
                'type' => $_POST['type'] ?? '',
                'condition' => $_POST['condition'] ?? 'equals',
                'value' => $_POST['value'] ?? '',
                'action' => $_POST['action_type'] ?? 'safe',
                'priority' => (int)($_POST['priority'] ?? 0),
                'status' => $_POST['status'] ?? 'active',
                'description' => Utils::sanitize($_POST['description'] ?? ''),
                'apply_to' => $_POST['apply_to'] ?? 'all'
            ];
            
            if (isset($_POST['id']) && $_POST['id']) {
                // Atualizar regra existente
                if ($rules->update($_POST['id'], $ruleData)) {
                    $message = 'Regra atualizada com sucesso!';
                } else {
                    $error = 'Erro ao atualizar regra.';
                }
            } else {
                // Criar nova regra
                if ($rules->create($ruleData)) {
                    $message = 'Regra criada com sucesso!';
                } else {
                    $error = 'Erro ao criar regra.';
                }
            }
            break;
            
        case 'delete':
            if (isset($_POST['id'])) {
                if ($rules->delete($_POST['id'])) {
                    $message = 'Regra excluída com sucesso!';
                } else {
                    $error = 'Erro ao excluir regra.';
                }
            }
            break;
            
        case 'toggle':
            if (isset($_POST['id'])) {
                $rule = $rules->get($_POST['id']);
                if ($rule) {
                    $newStatus = $rule['status'] === 'active' ? 'inactive' : 'active';
                    if ($rules->updateStatus($_POST['id'], $newStatus)) {
                        $message = 'Status da regra atualizado!';
                    }
                }
            }
            break;
    }
}

// Editar regra
if (isset($_GET['edit'])) {
    $editRule = $rules->get($_GET['edit']);
}

// Obter lista de regras
$allRules = $rules->listRules();
$campaigns = $campaign->listCampaigns(1, 100);

// Tipos de regras disponíveis
$ruleTypes = [
    'geo' => ['label' => 'Geolocalização', 'icon' => 'fa-globe'],
    'device' => ['label' => 'Dispositivo', 'icon' => 'fa-mobile-alt'],
    'browser' => ['label' => 'Navegador', 'icon' => 'fa-chrome'],
    'os' => ['label' => 'Sistema Operacional', 'icon' => 'fa-desktop'],
    'ip' => ['label' => 'Endereço IP', 'icon' => 'fa-network-wired'],
    'referrer' => ['label' => 'Referência', 'icon' => 'fa-link'],
    'language' => ['label' => 'Idioma', 'icon' => 'fa-language'],
    'isp' => ['label' => 'Provedor (ISP)', 'icon' => 'fa-server'],
    'bot' => ['label' => 'Detecção de Bot', 'icon' => 'fa-robot'],
    'vpn' => ['label' => 'Detecção de VPN', 'icon' => 'fa-user-secret'],
    'time' => ['label' => 'Horário', 'icon' => 'fa-clock'],
    'url_param' => ['label' => 'Parâmetro URL', 'icon' => 'fa-code'],
    'cookie' => ['label' => 'Cookie', 'icon' => 'fa-cookie'],
    'header' => ['label' => 'Header HTTP', 'icon' => 'fa-file-code']
];

// Condições disponíveis por tipo
$conditions = [
    'equals' => 'Igual a',
    'not_equals' => 'Diferente de',
    'contains' => 'Contém',
    'not_contains' => 'Não contém',
    'starts_with' => 'Começa com',
    'ends_with' => 'Termina com',
    'matches' => 'Corresponde (regex)',
    'greater_than' => 'Maior que',
    'less_than' => 'Menor que',
    'between' => 'Entre',
    'in_list' => 'Na lista'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regras - Cloaker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .rule-card {
            transition: all 0.3s ease;
        }
        .rule-card:hover {
            transform: translateX(5px);
        }
        .drag-handle {
            cursor: move;
        }
        .dragging {
            opacity: 0.5;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Sidebar -->
    <div class="fixed inset-y-0 left-0 w-64 bg-gray-900">
        <div class="flex items-center justify-center h-16 bg-purple-700">
            <span class="text-white text-xl font-bold">🛡️ Cloaker Pro</span>
        </div>
        <nav class="mt-8">
            <a href="index.php" class="flex items-center px-4 py-3 text-gray-300 hover:text-white hover:bg-gray-800">
                <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
            </a>
            <a href="campaigns.php" class="flex items-center px-4 py-3 text-gray-300 hover:text-white hover:bg-gray-800">
                <i class="fas fa-rocket mr-3"></i> Campanhas
            </a>
            <a href="rules.php" class="flex items-center px-4 py-3 text-gray-100 bg-purple-600">
                <i class="fas fa-shield-alt mr-3"></i> Regras
            </a>
            <a href="analytics.php" class="flex items-center px-4 py-3 text-gray-300 hover:text-white hover:bg-gray-800">
                <i class="fas fa-chart-line mr-3"></i> Analytics
            </a>
            <a href="settings.php" class="flex items-center px-4 py-3 text-gray-300 hover:text-white hover:bg-gray-800">
                <i class="fas fa-cog mr-3"></i> Configurações
            </a>
            <div class="border-t border-gray-700 my-4"></div>
            <a href="logout.php" class="flex items-center px-4 py-3 text-gray-300 hover:text-white hover:bg-gray-800">
                <i class="fas fa-sign-out-alt mr-3"></i> Sair
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between px-8 py-4">
                <h1 class="text-2xl font-semibold text-gray-800">Regras Globais</h1>
                <button onclick="openRuleModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200">
                    <i class="fas fa-plus mr-2"></i>Nova Regra
                </button>
            </div>
        </header>

        <!-- Content -->
        <main class="p-8">
            <!-- Messages -->
            <?php if ($message): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Info Box -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex">
                    <i class="fas fa-info-circle text-blue-600 mr-3 mt-1"></i>
                    <div>
                        <h3 class="font-semibold text-blue-800">Sobre as Regras Globais</h3>
                        <p class="text-blue-700 text-sm mt-1">
                            As regras globais são aplicadas a todas as campanhas (ou campanhas selecionadas) automaticamente. 
                            Elas são processadas em ordem de prioridade antes das regras específicas de cada campanha.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Rules Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Active Rules -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>Regras Ativas
                    </h3>
                    <div class="space-y-3" id="activeRules">
                        <?php foreach ($allRules as $rule): ?>
                        <?php if ($rule['status'] === 'active'): ?>
                        <div class="rule-card bg-white rounded-lg shadow-sm p-4 border-l-4 border-green-500" data-rule-id="<?php echo $rule['id']; ?>">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start gap-3 flex-1">
                                    <div class="drag-handle text-gray-400 mt-1">
                                        <i class="fas fa-grip-vertical"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <i class="fas <?php echo $ruleTypes[$rule['type']]['icon'] ?? 'fa-cog'; ?> text-purple-600"></i>
                                            <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($rule['name']); ?></span>
                                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">
                                                Prioridade: <?php echo $rule['priority']; ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium"><?php echo $ruleTypes[$rule['type']]['label'] ?? $rule['type']; ?></span>
                                            <?php echo $conditions[$rule['condition']] ?? $rule['condition']; ?>
                                            <code class="bg-gray-100 px-1 rounded"><?php echo htmlspecialchars($rule['value']); ?></code>
                                        </p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            Ação: <span class="font-medium <?php echo $rule['action'] === 'safe' ? 'text-yellow-600' : 'text-green-600'; ?>">
                                                <?php echo $rule['action'] === 'safe' ? 'Safe Page' : 'Money Page'; ?>
                                            </span>
                                        </p>
                                        <?php if ($rule['description']): ?>
                                        <p class="text-xs text-gray-500 mt-2"><?php echo htmlspecialchars($rule['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button onclick="editRule(<?php echo $rule['id']; ?>)" 
                                            class="text-purple-600 hover:text-purple-700">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Deseja desativar esta regra?')">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                        <button type="submit" class="text-yellow-600 hover:text-yellow-700">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta regra?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-700">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Inactive Rules -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-pause-circle text-gray-600 mr-2"></i>Regras Inativas
                    </h3>
                    <div class="space-y-3">
                        <?php foreach ($allRules as $rule): ?>
                        <?php if ($rule['status'] !== 'active'): ?>
                        <div class="rule-card bg-white rounded-lg shadow-sm p-4 border-l-4 border-gray-300 opacity-60">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i class="fas <?php echo $ruleTypes[$rule['type']]['icon'] ?? 'fa-cog'; ?> text-gray-500"></i>
                                        <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($rule['name']); ?></span>
                                    </div>
                                    <p class="text-sm text-gray-600">
                                        <?php echo $ruleTypes[$rule['type']]['label'] ?? $rule['type']; ?>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-700">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta regra?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-700">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Predefined Rules Templates -->
            <div class="mt-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-magic text-purple-600 mr-2"></i>Templates de Regras
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button onclick="applyTemplate('block_bots')" 
                            class="bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition text-left">
                        <i class="fas fa-robot text-red-600 text-2xl mb-2"></i>
                        <h4 class="font-semibold text-gray-800">Bloquear Bots</h4>
                        <p class="text-sm text-gray-600 mt-1">Redireciona todos os bots conhecidos para safe page</p>
                    </button>
                    
                    <button onclick="applyTemplate('block_vpn')" 
                            class="bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition text-left">
                        <i class="fas fa-user-secret text-yellow-600 text-2xl mb-2"></i>
                        <h4 class="font-semibold text-gray-800">Bloquear VPN/Proxy</h4>
                        <p class="text-sm text-gray-600 mt-1">Detecta e bloqueia conexões VPN e proxy</p>
                    </button>
                    
                    <button onclick="applyTemplate('geo_targeting')" 
                            class="bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition text-left">
                        <i class="fas fa-globe text-blue-600 text-2xl mb-2"></i>
                        <h4 class="font-semibold text-gray-800">Geo-Targeting</h4>
                        <p class="text-sm text-gray-600 mt-1">Permite apenas países específicos</p>
                    </button>
                </div>
            </div>
        </main>
    </div>

    <!-- Rule Modal -->
    <div id="ruleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <form method="POST" id="ruleForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="ruleId" value="">
                
                <div class="p-6 border-b">
                    <h3 class="text-xl font-semibold text-gray-800">
                        <i class="fas fa-shield-alt text-purple-600 mr-2"></i>
                        <span id="modalTitle">Nova Regra</span>
                    </h3>
                </div>
                
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nome da Regra</label>
                        <input type="text" name="name" id="ruleName" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tipo</label>
                            <select name="type" id="ruleType" required onchange="updateConditions()"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                                <?php foreach ($ruleTypes as $key => $type): ?>
                                <option value="<?php echo $key; ?>"><?php echo $type['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Condição</label>
                            <select name="condition" id="ruleCondition" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                                <?php foreach ($conditions as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Valor</label>
                        <input type="text" name="value" id="ruleValue" required
                               placeholder="Ex: BR,US,UK para países ou Googlebot para bots"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Ação</label>
                            <select name="action_type" id="ruleAction" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                                <option value="safe">Redirecionar para Safe Page</option>
                                <option value="money">Redirecionar para Money Page</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Prioridade</label>
                            <input type="number" name="priority" id="rulePriority" value="0" min="0" max="999"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Aplicar a</label>
                        <select name="apply_to" id="ruleApplyTo"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            <option value="all">Todas as Campanhas</option>
                            <option value="selected">Campanhas Selecionadas</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Descrição (opcional)</label>
                        <textarea name="description" id="ruleDescription" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" id="ruleStatus"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                            <option value="active">Ativa</option>
                            <option value="inactive">Inativa</option>
                        </select>
                    </div>
                </div>
                
                <div class="p-6 border-t flex justify-end gap-3">
                    <button type="button" onclick="closeRuleModal()"
                            class="px-4 py-2 text-gray-700 bg-gray-200 hover:bg-gray-300 rounded-lg transition">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Salvar Regra
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Gerenciamento de modal
        function openRuleModal() {
            document.getElementById('ruleModal').classList.remove('hidden');
            document.getElementById('modalTitle').textContent = 'Nova Regra';
            document.getElementById('ruleForm').reset();
            document.getElementById('ruleId').value = '';
        }
        
        function closeRuleModal() {
            document.getElementById('ruleModal').classList.remove('hidden');
        }
        
        function editRule(id) {
            // Fazer requisição AJAX para obter dados da regra
            fetch(`ajax/get-rule.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('modalTitle').textContent = 'Editar Regra';
                    document.getElementById('ruleId').value = data.id;
                    document.getElementById('ruleName').value = data.name;
                    document.getElementById('ruleType').value = data.type;
                    document.getElementById('ruleCondition').value = data.condition;
                    document.getElementById('ruleValue').value = data.value;
                    document.getElementById('ruleAction').value = data.action;
                    document.getElementById('rulePriority').value = data.priority;
                    document.getElementById('ruleDescription').value = data.description || '';
                    document.getElementById('ruleStatus').value = data.status;
                    openRuleModal();
                });
        }
        
        function applyTemplate(template) {
            const templates = {
                'block_bots': {
                    name: 'Bloquear Bots',
                    type: 'bot',
                    condition: 'equals',
                    value: 'true',
                    action: 'safe',
                    description: 'Bloqueia todos os bots detectados'
                },
                'block_vpn': {
                    name: 'Bloquear VPN/Proxy',
                    type: 'vpn',
                    condition: 'equals',
                    value: 'true',
                    action: 'safe',
                    description: 'Bloqueia conexões VPN e proxy'
                },
                'geo_targeting': {
                    name: 'Geo-targeting Brasil',
                    type: 'geo',
                    condition: 'not_equals',
                    value: 'BR',
                    action: 'safe',
                    description: 'Permite apenas visitantes do Brasil'
                }
            };
            
            const tpl = templates[template];
            if (tpl) {
                document.getElementById('ruleName').value = tpl.name;
                document.getElementById('ruleType').value = tpl.type;
                document.getElementById('ruleCondition').value = tpl.condition;
                document.getElementById('ruleValue').value = tpl.value;
                document.getElementById('ruleAction').value = tpl.action;
                document.getElementById('ruleDescription').value = tpl.description;
                openRuleModal();
            }
        }
        
        function updateConditions() {
            const type = document.getElementById('ruleType').value;
            // Lógica para atualizar condições baseado no tipo
        }
        
        // Drag and drop para reordenar prioridades
        let draggedElement = null;
        
        document.querySelectorAll('.drag-handle').forEach(handle => {
            handle.addEventListener('dragstart', (e) => {
                draggedElement = e.target.closest('.rule-card');
                draggedElement.classList.add('dragging');
            });
            
            handle.addEventListener('dragend', (e) => {
                draggedElement.classList.remove('dragging');
            });
        });
    </script>
</body>
</html>