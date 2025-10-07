<?php
/**
 * TenantMiddleware.php
 * Middleware para aplicar isolamento de dados por tenant automaticamente
 * Versão: 2.0 - Corrigida e melhorada
 */

class TenantMiddleware {
    private $tenantManager;
    private $auth;
    private $currentUser;
    private $currentTenant;
    
    public function __construct($tenantManager, $auth) {
        $this->tenantManager = $tenantManager;
        $this->auth = $auth;
    }
    
    /**
     * Inicializar middleware
     * Chamar no início de cada request
     */
    public function handle() {
        // Obter usuário atual
        $this->currentUser = $this->auth->getCurrentUser();
        
        if (!$this->currentUser) {
            return; // Não logado
        }
        
        // Detectar tenant
        $this->currentTenant = $this->tenantManager->detectCurrentTenant($this->currentUser);
        
        // Armazenar na sessão para acesso rápido
        $_SESSION['current_tenant_id'] = $this->currentTenant['id'] ?? null;
        $_SESSION['is_super_admin'] = $this->tenantManager->isSuperAdmin($this->currentUser);
    }
    
    /**
     * Obter usuário atual
     */
    public function getCurrentUser() {
        return $this->currentUser;
    }
    
    /**
     * Obter tenant atual
     */
    public function getCurrentTenant() {
        return $this->currentTenant;
    }
    
    /**
     * Verificar se é super admin
     */
    public function isSuperAdmin() {
        return $this->tenantManager->isSuperAdmin($this->currentUser);
    }
    
    /**
     * Obter ID do tenant atual
     */
    public function getTenantId() {
        return $this->currentTenant['id'] ?? null;
    }
    
    /**
     * Aplicar filtro de tenant em WHERE
     * Usar em todas as queries de SELECT
     */
    public function applyFilter(&$where = []) {
        // Super admin vê tudo
        if ($this->isSuperAdmin()) {
            return;
        }
        
        // Aplicar filtro de tenant
        if ($tenantId = $this->getTenantId()) {
            $where['tenant_id'] = $tenantId;
        }
    }
    
    /**
     * Adicionar tenant_id em INSERT
     * MELHORADO: Super admin pode escolher não adicionar tenant_id
     */
    public function addTenantId(&$data) {
        // Super admin pode optar por criar recursos sem tenant
        // (útil para criar dados globais ou recursos de sistema)
        if ($this->isSuperAdmin()) {
            // Se já especificou um tenant_id, mantém
            // Se não especificou, não adiciona (permite criar sem tenant)
            return;
        }
        
        // Para usuários normais, sempre adiciona o tenant_id
        if ($tenantId = $this->getTenantId()) {
            $data['tenant_id'] = $tenantId;
        }
    }
    
    /**
     * Verificar se usuário pode acessar recurso
     */
    public function canAccess($resourceTenantId) {
        // Super admin acessa tudo
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        // Recursos sem tenant (globais) podem ser acessados por todos
        if (empty($resourceTenantId)) {
            return true;
        }
        
        // Verificar se pertence ao mesmo tenant
        return $resourceTenantId == $this->getTenantId();
    }
    
    /**
     * Verificar limites do tenant antes de criar recursos
     */
    public function checkLimit($type) {
        // Super admin não tem limites
        if ($this->isSuperAdmin()) {
            return ['allowed' => true];
        }
        
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return [
                'allowed' => false, 
                'message' => 'Tenant não encontrado'
            ];
        }
        
        $limits = $this->tenantManager->checkLimits($tenantId);
        
        if (in_array($type, $limits['limits'])) {
            $messages = [
                'campaigns' => 'Limite de campanhas atingido',
                'users' => 'Limite de usuários atingido',
                'visitors' => 'Limite de visitantes mensais atingido'
            ];
            
            return [
                'allowed' => false,
                'message' => $messages[$type] ?? "Limite de {$type} atingido",
                'current' => $limits['stats'][$type] ?? 0,
                'max' => $limits['max'][$type] ?? 0,
                'upgrade_required' => true
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Obter informações sobre uso e limites
     */
    public function getUsageInfo() {
        if ($this->isSuperAdmin()) {
            return [
                'is_super_admin' => true,
                'unlimited' => true
            ];
        }
        
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return null;
        }
        
        return $this->tenantManager->checkLimits($tenantId);
    }
}

/**
 * Helper global para usar em qualquer lugar
 */
function tenant() {
    global $tenantMiddleware;
    return $tenantMiddleware;
}

function isSuperAdmin() {
    return tenant()->isSuperAdmin();
}

function currentTenant() {
    return tenant()->getCurrentTenant();
}

function tenantId() {
    return tenant()->getTenantId();
}

/**
 * Verificar se pode criar recurso
 */
function canCreate($type) {
    return tenant()->checkLimit($type);
}