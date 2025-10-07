<?php
/**
 * TenantManager.php
 * Gerenciador de Multi-Tenancy
 * Versão: 2.0 - Corrigida e melhorada
 */

class TenantManager {
    private $db;
    private $currentTenant = null;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Detectar e carregar tenant atual
     */
    public function detectCurrentTenant($user) {
        if (!$user) {
            return null;
        }
        
        // Super Admin não tem tenant
        if ($user['role'] === 'admin' && empty($user['tenant_id'])) {
            return null;
        }
        
        // Carregar tenant do usuário
        if (!empty($user['tenant_id'])) {
            $this->currentTenant = $this->getTenant($user['tenant_id']);
            return $this->currentTenant;
        }
        
        return null;
    }
    
    /**
     * Verificar se usuário é Super Admin
     */
    public function isSuperAdmin($user) {
        return $user && $user['role'] === 'admin' && empty($user['tenant_id']);
    }
    
    /**
     * Criar novo tenant (cliente)
     */
    public function createTenant($data) {
        // Validar dados obrigatórios
        if (empty($data['name']) || empty($data['email'])) {
            return ['success' => false, 'message' => 'Nome e email são obrigatórios'];
        }
        
        // Validar email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Email inválido'];
        }
        
        // Gerar slug único
        $slug = $this->generateSlug($data['name']);
        
        // Verificar se slug já existe
        if ($this->db->selectOne('tenants', ['slug' => $slug])) {
            $slug .= '-' . substr(uniqid(), -4);
        }
        
        // Iniciar transação
        try {
            $this->db->raw("START TRANSACTION");
            
            // Criar tenant
            $tenantData = [
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? 'trial',
                'plan' => $data['plan'] ?? 'free',
                'max_campaigns' => $this->getPlanLimit($data['plan'] ?? 'free', 'campaigns'),
                'max_users' => $this->getPlanLimit($data['plan'] ?? 'free', 'users'),
                'max_visitors_month' => $this->getPlanLimit($data['plan'] ?? 'free', 'visitors'),
                'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
                'settings' => json_encode($data['settings'] ?? []),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $tenantId = $this->db->insert('tenants', $tenantData);
            
            if (!$tenantId) {
                throw new Exception('Erro ao criar tenant');
            }
            
            // Criar usuário owner do tenant
            if (!empty($data['owner_username']) && !empty($data['owner_password'])) {
                $userId = $this->createTenantOwner($tenantId, [
                    'username' => $data['owner_username'],
                    'password' => $data['owner_password'],
                    'email' => $data['email'],
                    'display_name' => $data['name']
                ]);
                
                if (!$userId) {
                    throw new Exception('Erro ao criar usuário owner');
                }
            }
            
            // Log
            $this->logTenantActivity($tenantId, null, 'tenant_created', 'Tenant criado');
            
            // Commit
            $this->db->raw("COMMIT");
            
            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'slug' => $slug,
                'message' => 'Cliente criado com sucesso!'
            ];
            
        } catch (Exception $e) {
            // Rollback em caso de erro
            $this->db->raw("ROLLBACK");
            error_log("Error creating tenant: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao criar cliente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Criar usuário owner do tenant
     */
    private function createTenantOwner($tenantId, $data) {
        // Verificar se username já existe
        if ($this->db->selectOne('users', ['username' => $data['username']])) {
            throw new Exception('Username já existe');
        }
        
        $userId = $this->db->insert('users', [
            'tenant_id' => $tenantId,
            'username' => $data['username'],
            'display_name' => $data['display_name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => 'admin', // Admin do tenant (não super admin)
            'is_tenant_owner' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $userId;
    }
    
    /**
     * Listar todos os tenants (só para super admin)
     */
    public function listTenants($filters = []) {
        $where = [];
        
        if (!empty($filters['status'])) {
            $where['status'] = $filters['status'];
        }
        
        if (!empty($filters['plan'])) {
            $where['plan'] = $filters['plan'];
        }
        
        if (!empty($filters['search'])) {
            // Implementar busca por nome/email
            $search = '%' . $filters['search'] . '%';
            $sql = "SELECT * FROM tenants WHERE name LIKE ? OR email LIKE ? ORDER BY created_at DESC";
            return $this->db->raw($sql, [$search, $search])->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $this->db->select('tenants', $where, '*', 'created_at DESC');
    }
    
    /**
     * Obter tenant por ID
     */
    public function getTenant($tenantId) {
        return $this->db->selectOne('tenants', ['id' => $tenantId]);
    }
    
    /**
     * Atualizar tenant
     */
    public function updateTenant($tenantId, $data) {
        // Verificar se tenant existe
        if (!$this->getTenant($tenantId)) {
            return false;
        }
        
        $allowed = ['name', 'email', 'phone', 'status', 'plan', 'max_campaigns', 
                   'max_users', 'max_visitors_month', 'expires_at', 'settings'];
        
        $updates = [];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if ($field === 'settings' && is_array($data[$field])) {
                    $updates[$field] = json_encode($data[$field]);
                } else {
                    $updates[$field] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        // Se mudou o plano, atualizar limites automaticamente
        if (isset($updates['plan'])) {
            $updates['max_campaigns'] = $this->getPlanLimit($updates['plan'], 'campaigns');
            $updates['max_users'] = $this->getPlanLimit($updates['plan'], 'users');
            $updates['max_visitors_month'] = $this->getPlanLimit($updates['plan'], 'visitors');
        }
        
        $result = $this->db->update('tenants', $updates, ['id' => $tenantId]);
        
        if ($result) {
            $this->logTenantActivity($tenantId, null, 'tenant_updated', 'Tenant atualizado');
        }
        
        return $result;
    }
    
    /**
     * Deletar tenant e todos os dados relacionados
     * ATENÇÃO: Operação irreversível!
     */
    public function deleteTenant($tenantId, $currentUserId = null) {
        // Validações de segurança
        $tenant = $this->getTenant($tenantId);
        if (!$tenant) {
            return ['success' => false, 'message' => 'Tenant não encontrado'];
        }
        
        // Verificar se há usuários owner
        $owner = $this->db->selectOne('users', [
            'tenant_id' => $tenantId,
            'is_tenant_owner' => 1
        ]);
        
        // Não permitir que o owner delete seu próprio tenant sem confirmação extra
        if ($owner && $currentUserId && $owner['id'] == $currentUserId) {
            return [
                'success' => false, 
                'message' => 'Owner não pode deletar o próprio tenant. Use Super Admin.'
            ];
        }
        
        try {
            // Iniciar transação
            $this->db->raw("START TRANSACTION");
            
            // Deletar em ordem (respeitando foreign keys)
            // 1. Visitors (tem FK para campaigns)
            $this->db->raw("DELETE FROM visitors WHERE campaign_id IN (SELECT id FROM campaigns WHERE tenant_id = ?)", [$tenantId]);
            
            // 2. Campanhas
            $this->db->delete('campaigns', ['tenant_id' => $tenantId]);
            
            // 3. Usuários
            $this->db->delete('users', ['tenant_id' => $tenantId]);
            
            // 4. Outros dados
            $this->db->delete('blocked_ips', ['tenant_id' => $tenantId]);
            $this->db->delete('ip_whitelist', ['tenant_id' => $tenantId]);
            $this->db->delete('tenant_stats', ['tenant_id' => $tenantId]);
            $this->db->delete('tenant_activities', ['tenant_id' => $tenantId]);
            $this->db->delete('activity_logs', ['tenant_id' => $tenantId]);
            
            // 5. Deletar tenant
            $result = $this->db->delete('tenants', ['id' => $tenantId]);
            
            if (!$result) {
                throw new Exception('Erro ao deletar tenant');
            }
            
            // Commit
            $this->db->raw("COMMIT");
            
            return ['success' => true, 'message' => 'Tenant deletado com sucesso'];
            
        } catch (Exception $e) {
            // Rollback
            $this->db->raw("ROLLBACK");
            error_log("Error deleting tenant: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao deletar tenant: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter estatísticas do tenant
     * CORRIGIDO: Agora conta visitors corretamente
     */
    public function getTenantStats($tenantId) {
        // Contar campanhas
        $campaigns = $this->db->raw(
            "SELECT COUNT(*) as total FROM campaigns WHERE tenant_id = ?", 
            [$tenantId]
        )->fetch();
        
        // Contar usuários
        $users = $this->db->raw(
            "SELECT COUNT(*) as total FROM users WHERE tenant_id = ?", 
            [$tenantId]
        )->fetch();
        
        // Contar visitantes do mês
        $visitorsMonth = $this->getVisitorsThisMonth($tenantId);
        
        // Storage (implementar se necessário)
        $storage = $this->getStorageUsed($tenantId);
        
        return [
            'campaigns' => (int)($campaigns['total'] ?? 0),
            'users' => (int)($users['total'] ?? 0),
            'visitors_month' => $visitorsMonth,
            'storage_used' => $storage
        ];
    }
    
    /**
     * Verificar se tenant atingiu limites
     * MELHORADO: Proteção contra divisão por zero
     */
    public function checkLimits($tenantId) {
        $tenant = $this->getTenant($tenantId);
        if (!$tenant) {
            return [
                'exceeded' => true,
                'limits' => ['tenant_not_found'],
                'stats' => [],
                'max' => []
            ];
        }
        
        $stats = $this->getTenantStats($tenantId);
        $exceeded = [];
        
        // Verificar limites (com proteção contra divisão por zero)
        if ($tenant['max_campaigns'] > 0 && $stats['campaigns'] >= $tenant['max_campaigns']) {
            $exceeded[] = 'campaigns';
        }
        
        if ($tenant['max_users'] > 0 && $stats['users'] >= $tenant['max_users']) {
            $exceeded[] = 'users';
        }
        
        if ($tenant['max_visitors_month'] > 0 && $stats['visitors_month'] >= $tenant['max_visitors_month']) {
            $exceeded[] = 'visitors';
        }
        
        return [
            'exceeded' => !empty($exceeded),
            'limits' => $exceeded,
            'stats' => $stats,
            'max' => [
                'campaigns' => $tenant['max_campaigns'],
                'users' => $tenant['max_users'],
                'visitors' => $tenant['max_visitors_month']
            ],
            'usage_percent' => [
                'campaigns' => $tenant['max_campaigns'] > 0 
                    ? round(($stats['campaigns'] / $tenant['max_campaigns']) * 100, 1) 
                    : 0,
                'users' => $tenant['max_users'] > 0 
                    ? round(($stats['users'] / $tenant['max_users']) * 100, 1) 
                    : 0,
                'visitors' => $tenant['max_visitors_month'] > 0 
                    ? round(($stats['visitors_month'] / $tenant['max_visitors_month']) * 100, 1) 
                    : 0
            ]
        ];
    }
    
    /**
     * Aplicar filtro de tenant em queries
     */
    public function applyTenantFilter($user, &$where) {
        if ($this->isSuperAdmin($user)) {
            // Super admin vê tudo, não aplica filtro
            return;
        }
        
        if (!empty($user['tenant_id'])) {
            $where['tenant_id'] = $user['tenant_id'];
        }
    }
    
    /**
     * Helpers privados
     */
    private function generateSlug($name) {
        // Remove acentos
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        // Remove caracteres especiais
        $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', $slug);
        // Converte para minúsculas
        $slug = strtolower(trim($slug, '-'));
        // Limita tamanho
        return substr($slug, 0, 50);
    }
    
    private function getPlanLimit($plan, $type) {
        $limits = [
            'free' => ['campaigns' => 5, 'users' => 1, 'visitors' => 10000],
            'basic' => ['campaigns' => 20, 'users' => 3, 'visitors' => 50000],
            'pro' => ['campaigns' => 100, 'users' => 10, 'visitors' => 500000],
            'enterprise' => ['campaigns' => 999, 'users' => 50, 'visitors' => 999999999]
        ];
        
        return $limits[$plan][$type] ?? 0;
    }
    
    /**
     * CORRIGIDO: Conta visitantes da tabela visitors, não de campaigns
     */
    private function getVisitorsThisMonth($tenantId) {
        $firstDay = date('Y-m-01 00:00:00');
        
        // Query corrigida: conta na tabela visitors através do JOIN com campaigns
        $sql = "SELECT COUNT(v.id) as total 
                FROM visitors v 
                INNER JOIN campaigns c ON v.campaign_id = c.id 
                WHERE c.tenant_id = ? 
                AND v.created_at >= ?";
                
        $result = $this->db->raw($sql, [$tenantId, $firstDay])->fetch();
        return (int)($result['total'] ?? 0);
    }
    
    private function getStorageUsed($tenantId) {
        // Implementar cálculo de armazenamento se necessário
        // Por enquanto retorna 0
        return 0;
    }
    
    private function logTenantActivity($tenantId, $userId, $action, $description, $metadata = null) {
        $this->db->insert('tenant_activities', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}