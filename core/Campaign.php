<?php
/**
 * Cloaker Pro - Campaign Manager
 * Gerenciamento de campanhas de cloaking
 * 
 * @version 2.2.0 - Multi-Tenancy Support
 */

class Campaign {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Contar campanhas
     */
    public function countCampaigns($where = []) {
        return $this->db->count('campaigns', $where);
    }
    
    /**
     * Listar campanhas com filtros (MULTI-TENANT COMPATIBLE)
     */
    public function listCampaigns($page = 1, $perPage = 20, $filters = []) {
        $offset = ($page - 1) * $perPage;
        $where = [];
        $sqlWhere = [];
        $params = [];
        
        // Filtro de status
        if (isset($filters['status'])) {
            $where['status'] = $filters['status'];
            $sqlWhere[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        // CRITICAL: Filtro de tenant_id para multi-tenancy
        if (isset($filters['tenant_id'])) {
            $where['tenant_id'] = $filters['tenant_id'];
            $sqlWhere[] = "tenant_id = ?";
            $params[] = $filters['tenant_id'];
        }
        
        // Busca por nome
        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            
            // Construir WHERE clause para SQL
            $whereClause = "";
            if (!empty($sqlWhere)) {
                $whereClause = " AND " . implode(" AND ", $sqlWhere);
            }
            
            // Query com filtros aplicados
            $searchParams = array_merge(["%$search%", "%$search%"], $params);
            $campaigns = $this->db->raw(
                "SELECT * FROM campaigns 
                 WHERE (name LIKE ? OR slug LIKE ?)
                 $whereClause
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($searchParams, [$perPage, $offset])
            )->fetchAll();
            
            // Count com filtros aplicados
            $totalResult = $this->db->raw(
                "SELECT COUNT(*) as total FROM campaigns 
                 WHERE (name LIKE ? OR slug LIKE ?)
                 $whereClause",
                $searchParams
            )->fetch();
            $total = $totalResult['total'];
        } else {
            $campaigns = $this->db->select(
                'campaigns',
                $where,
                '*',
                'created_at DESC',
                "$perPage OFFSET $offset"
            );
            $total = $this->db->count('campaigns', $where);
        }
        
        return [
            'data' => $campaigns,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Obter campanha por ID
     */
    public function get($id) {
        return $this->db->selectOne('campaigns', ['id' => $id]);
    }
    
    /**
     * Obter campanha por ID (alias)
     */
    public function getCampaign($id) {
        return $this->get($id);
    }
    
    /**
     * Obter campanha por SLUG (método seguro para links públicos)
     */
    public function getBySlug($slug) {
        return $this->db->selectOne('campaigns', ['slug' => $slug]);
    }
    
    /**
     * Criar campanha
     */
    public function create($data) {
        try {
            // Gerar ID único se não fornecido
            if (empty($data['id'])) {
                $data['id'] = $this->generateUniqueId();
            }
            
            // Gerar slug SEGURO se não fornecido
            if (empty($data['slug'])) {
                $data['slug'] = $this->generateSlug($data['name'] ?? null);
            }
            
            // Garantir que visitors_count existe
            if (!isset($data['visitors_count'])) {
                $data['visitors_count'] = 0;
            }
            
            // MULTI-TENANCY: Garantir tenant_id
            if (!isset($data['tenant_id']) && isset($GLOBALS['tenantMiddleware'])) {
                $tenantId = $GLOBALS['tenantMiddleware']->getTenantId();
                if ($tenantId) {
                    $data['tenant_id'] = $tenantId;
                }
            }
            
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
            
            error_log("Campaign::create - Data before insert: " . print_r($data, true));
            
            $result = $this->db->insert('campaigns', $data);
            
            error_log("Campaign::create - Insert result: " . var_export($result, true));
            
            if ($result !== false) {
                return $data['id'];
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Campaign::create - Exception: " . $e->getMessage());
            error_log("Campaign::create - Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Gerar ID único para campanha
     */
    private function generateUniqueId() {
        do {
            $id = uniqid('camp_', true);
            $exists = $this->db->selectOne('campaigns', ['id' => $id]);
        } while ($exists);
        
        return $id;
    }
    
    /**
     * Criar campanha (alias)
     */
    public function createCampaign($data) {
        return $this->create($data);
    }
    
    /**
     * Atualizar campanha
     */
    public function update($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        return $this->db->update('campaigns', $data, ['id' => $id]);
    }
    
    /**
     * Atualizar campanha (alias)
     */
    public function updateCampaign($id, $data) {
        return $this->update($id, $data);
    }
    
    /**
     * Atualizar status da campanha
     */
    public function updateStatus($id, $status) {
        return $this->update($id, ['status' => $status]);
    }
    
    /**
     * Deletar campanha
     */
    public function delete($id) {
        // Também deletar logs relacionados
        $this->db->delete('visitor_logs', ['campaign_id' => $id]);
        
        return $this->db->delete('campaigns', ['id' => $id]);
    }
    
    /**
     * Deletar campanha (alias)
     */
    public function deleteCampaign($id) {
        return $this->delete($id);
    }
    
    /**
     * Duplicar campanha
     */
    public function duplicate($id) {
        $campaign = $this->get($id);
        if (!$campaign) return false;
        
        unset($campaign['id']);
        $campaign['name'] = $campaign['name'] . ' (Cópia)';
        $campaign['slug'] = $this->generateSlug($campaign['name']);
        $campaign['status'] = 'draft';
        $campaign['created_at'] = date('Y-m-d H:i:s');
        $campaign['updated_at'] = date('Y-m-d H:i:s');
        
        return $this->create($campaign);
    }
    
    /**
     * Ativar/Desativar campanha
     */
    public function toggleStatus($id) {
        $campaign = $this->get($id);
        if (!$campaign) return false;
        
        $newStatus = $campaign['status'] === 'active' ? 'paused' : 'active';
        
        return $this->updateStatus($id, $newStatus);
    }
    
    /**
     * Gerar slug único e IMPOSSÍVEL DE ADIVINHAR
     * 
     * Gera hash aleatório com 281 trilhões de combinações possíveis (16^12)
     */
    private function generateSlug($name = null) {
        do {
            // Gerar parte aleatória com 12 caracteres hexadecimais
            $randomPart = bin2hex(random_bytes(6));
            
            // Opcionalmente adicionar prefixo curto do nome
            if ($name) {
                $cleanName = preg_replace('/[^a-z0-9]/', '', strtolower($name));
                $prefix = substr($cleanName, 0, 4);
                
                if (!empty($prefix)) {
                    $slug = $prefix . '-' . $randomPart;
                } else {
                    $slug = $randomPart;
                }
            } else {
                $slug = $randomPart;
            }
            
            $exists = $this->db->selectOne('campaigns', ['slug' => $slug]);
            
        } while ($exists);
        
        return $slug;
    }
    
    /**
     * Regenerar slug de uma campanha
     */
    public function regenerateSlug($id) {
        $campaign = $this->get($id);
        
        if (!$campaign) {
            error_log("Campaign::regenerateSlug - Campaign not found: $id");
            return false;
        }
        
        $newSlug = $this->generateSlug($campaign['name']);
        $result = $this->update($id, ['slug' => $newSlug]);
        
        if ($result) {
            error_log("Campaign::regenerateSlug - Success! Old: {$campaign['slug']}, New: $newSlug");
        } else {
            error_log("Campaign::regenerateSlug - Failed to update slug for campaign: $id");
        }
        
        return $result;
    }
    
    /**
     * CORRIGIDO: Incrementar contador de hits/visitantes
     * Este método é chamado pelo Engine.php
     */
    public function incrementHits($id) {
        try {
            $sql = "UPDATE campaigns SET visitors_count = visitors_count + 1 WHERE id = ?";
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log("Campaign::incrementHits error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Incrementar contador de visitantes (alias)
     */
    public function incrementVisitors($id) {
        return $this->incrementHits($id);
    }
    
    /**
     * Obter estatísticas da campanha
     */
    public function getStats($id, $period = 'all') {
        $periodWhere = $this->getPeriodWhere($period);
        
        $tableExists = $this->db->raw("SHOW TABLES LIKE 'visitor_logs'")->fetch();
        
        if (!$tableExists) {
            return [
                'total' => 0,
                'safe' => 0,
                'money' => 0,
                'bots' => 0,
                'conversions' => 0
            ];
        }
        
        $result = $this->db->raw(
            "SELECT 
                COUNT(*) as total_visitors,
                SUM(CASE WHEN action = 'safe' THEN 1 ELSE 0 END) as safe_redirects,
                SUM(CASE WHEN action = 'money' THEN 1 ELSE 0 END) as money_redirects,
                SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_blocks
            FROM visitor_logs 
            WHERE campaign_id = ? $periodWhere",
            [$id]
        )->fetch();
        
        return [
            'total' => (int)($result['total_visitors'] ?? 0),
            'safe' => (int)($result['safe_redirects'] ?? 0),
            'money' => (int)($result['money_redirects'] ?? 0),
            'bots' => (int)($result['bot_blocks'] ?? 0),
            'conversions' => (int)($result['money_redirects'] ?? 0)
        ];
    }
    
    /**
     * Obter cláusula WHERE baseada no período
     */
    private function getPeriodWhere($period) {
        switch ($period) {
            case 'today':
                return " AND DATE(created_at) = CURDATE()";
            case 'yesterday':
                return " AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            case 'week':
                return " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            case 'month':
                return " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            default:
                return "";
        }
    }
    
    /**
     * Verificar se um slug é seguro (aleatório)
     */
    public function isSlugSecure($slug) {
        return preg_match('/[a-f0-9]{8,}/', $slug) === 1;
    }
    
    /**
     * Obter campanhas com slugs inseguros (para migração)
     */
    public function getCampaignsWithInsecureSlugs() {
        $campaigns = $this->db->select('campaigns', [], '*');
        $insecure = [];
        
        foreach ($campaigns as $campaign) {
            if (!$this->isSlugSecure($campaign['slug'])) {
                $insecure[] = $campaign;
            }
        }
        
        return $insecure;
    }
    
    /**
     * Obter URL pública segura da campanha
     */
    public function getPublicUrl($id) {
        $campaign = $this->get($id);
        
        if (!$campaign) {
            return null;
        }
        
        if (!defined('BASE_URL')) {
            error_log("Campaign::getPublicUrl - BASE_URL not defined!");
            return null;
        }
        
        return rtrim(BASE_URL, '/') . '/c/' . $campaign['slug'];
    }
    
    /**
     * Obter estatísticas de segurança do sistema
     */
    public function getSecurityStats() {
        $allCampaigns = $this->db->select('campaigns', [], '*');
        $total = count($allCampaigns);
        $secure = 0;
        $insecure = 0;
        
        foreach ($allCampaigns as $campaign) {
            if ($this->isSlugSecure($campaign['slug'])) {
                $secure++;
            } else {
                $insecure++;
            }
        }
        
        return [
            'total_campaigns' => $total,
            'secure_slugs' => $secure,
            'insecure_slugs' => $insecure,
            'security_percentage' => $total > 0 ? round(($secure / $total) * 100, 2) : 0
        ];
    }
}
?>