<?php
/**
 * Cloaker Pro - Rate Limiter (Multi-Tenant)
 * Sistema avançado de controle de taxa de requisições
 * Protege contra ataques de força bruta e scraping
 */

class RateLimiter {
    private $db;
    private $config = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadConfig();
    }
    
    /**
     * Obter tenant_id do contexto atual
     */
    private function getTenantId() {
        if (isset($GLOBALS['tenantMiddleware'])) {
            return $GLOBALS['tenantMiddleware']->getTenantId();
        }
        return null;
    }
    
    /**
     * Verificar se é super admin
     */
    private function isSuperAdmin() {
        if (isset($GLOBALS['tenantMiddleware'])) {
            return $GLOBALS['tenantMiddleware']->isSuperAdmin();
        }
        return false;
    }
    
    /**
     * Carregar configurações do banco (globais + tenant)
     */
    private function loadConfig() {
        $tenantId = $this->getTenantId();
        
        // Buscar configs globais e do tenant
        $query = "SELECT config_key, config_value, config_type 
                  FROM antiscraping_config 
                  WHERE category = 'rate_limit'
                  AND (tenant_id IS NULL";
        
        $params = [];
        if ($tenantId !== null) {
            $query .= " OR tenant_id = ?";
            $params[] = $tenantId;
        }
        $query .= ") ORDER BY tenant_id ASC"; // NULL primeiro (globais)
        
        $stmt = $this->db->query($query, $params);
        
        while ($row = $stmt->fetch()) {
            $value = $row['config_value'];
            
            // Converter tipo
            switch ($row['config_type']) {
                case 'bool':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'int':
                    $value = intval($value);
                    break;
            }
            
            // Configs do tenant sobrescrevem globais
            $this->config[$row['config_key']] = $value;
        }
        
        // Fallback para valores padrão
        $defaults = [
            'rate_limit_enabled' => true,
            'rate_limit_requests' => 60,
            'rate_limit_window' => 60,
            'rate_limit_block_duration' => 3600,
            'rate_limit_violations_before_block' => 3
        ];
        
        foreach ($defaults as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }
    }
    
    /**
     * Verificar se está habilitado
     */
    public function isEnabled() {
        return $this->config['rate_limit_enabled'] ?? true;
    }
    
    /**
     * Verificar rate limit
     * 
     * @param string $identifier IP, fingerprint ou user_id
     * @param string $type Tipo: 'ip', 'fingerprint', 'user'
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public function check($identifier, $type = 'ip') {
        if (!$this->isEnabled()) {
            return [
                'allowed' => true,
                'remaining' => 999,
                'reset_at' => time() + 3600,
                'message' => 'Rate limit disabled'
            ];
        }
        
        // Verificar whitelist
        if ($type === 'ip' && $this->isWhitelisted($identifier)) {
            return [
                'allowed' => true,
                'remaining' => 999,
                'reset_at' => time() + 3600,
                'message' => 'IP whitelisted'
            ];
        }
        
        // Verificar blacklist
        if ($type === 'ip' && $this->isBlocked($identifier)) {
            $blockInfo = $this->getBlockInfo($identifier);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => strtotime($blockInfo['expires_at'] ?? '+1 hour'),
                'message' => 'IP blocked: ' . ($blockInfo['reason'] ?? 'Rate limit exceeded'),
                'blocked' => true
            ];
        }
        
        $now = time();
        $window = $this->config['rate_limit_window'];
        $maxRequests = $this->config['rate_limit_requests'];
        
        // Buscar ou criar registro
        $stmt = $this->db->query(
            "SELECT * FROM rate_limits 
             WHERE identifier = ? AND type = ? 
             AND window_end > ?
             ORDER BY window_end DESC LIMIT 1",
            [$identifier, $type, $now]
        );
        
        $record = $stmt->fetch();
        
        if (!$record) {
            // Criar novo registro
            $this->db->insert('rate_limits', [
                'identifier' => $identifier,
                'type' => $type,
                'requests' => 1,
                'window_start' => $now,
                'window_end' => $now + $window,
                'last_request' => $now
            ]);
            
            return [
                'allowed' => true,
                'remaining' => $maxRequests - 1,
                'reset_at' => $now + $window,
                'message' => 'First request in window'
            ];
        }
        
        // Atualizar contador
        $newCount = $record['requests'] + 1;
        
        $this->db->update('rate_limits', [
            'requests' => $newCount,
            'last_request' => $now
        ], ['id' => $record['id']]);
        
        // Verificar limite
        if ($newCount > $maxRequests) {
            // Registrar violação
            $this->recordViolation($identifier, $type, $newCount);
            
            // Verificar se deve bloquear
            $violations = $this->getViolationCount($identifier);
            if ($violations >= $this->config['rate_limit_violations_before_block']) {
                $this->blockIdentifier($identifier, $type, 'Rate limit exceeded multiple times');
            }
            
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $record['window_end'],
                'message' => 'Rate limit exceeded',
                'current' => $newCount,
                'max' => $maxRequests
            ];
        }
        
        return [
            'allowed' => true,
            'remaining' => $maxRequests - $newCount,
            'reset_at' => $record['window_end'],
            'message' => 'Within limits',
            'current' => $newCount,
            'max' => $maxRequests
        ];
    }
    
    /**
     * Registrar violação (com tenant_id)
     */
    private function recordViolation($identifier, $type, $requestCount) {
        $tenantId = $this->getTenantId();
        
        $this->db->insert('scraping_attempts', [
            'tenant_id' => $tenantId,
            'ip_address' => $type === 'ip' ? $identifier : Utils::getRealIP(),
            'fingerprint' => $type === 'fingerprint' ? $identifier : null,
            'detection_type' => 'rate_limit',
            'severity' => 'medium',
            'score' => 60,
            'details' => json_encode([
                'type' => $type,
                'identifier' => $identifier,
                'requests' => $requestCount,
                'window' => $this->config['rate_limit_window'],
                'max_allowed' => $this->config['rate_limit_requests']
            ]),
            'action_taken' => 'logged'
        ]);
    }
    
    /**
     * Obter contagem de violações (filtrada por tenant)
     */
    private function getViolationCount($identifier) {
        $tenantId = $this->getTenantId();
        
        $query = "SELECT COUNT(*) as count FROM scraping_attempts 
                  WHERE (ip_address = ? OR fingerprint = ?)
                  AND detection_type = 'rate_limit'
                  AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $params = [$identifier, $identifier];
        
        // Filtrar por tenant se não for super admin
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $result = $this->db->query($query, $params);
        
        $row = $result->fetch();
        return $row ? intval($row['count']) : 0;
    }
    
    /**
     * Bloquear identifier (com tenant_id)
     */
    private function blockIdentifier($identifier, $type, $reason) {
        $tenantId = $this->getTenantId();
        $duration = $this->config['rate_limit_block_duration'];
        
        if ($type === 'ip') {
            $this->db->query(
                "INSERT INTO blocked_ips 
                (tenant_id, ip_address, reason, severity, block_type, expires_at, attempts) 
                VALUES (?, ?, ?, 'medium', 'temporary', DATE_ADD(NOW(), INTERVAL ? SECOND), 1)
                ON DUPLICATE KEY UPDATE 
                    attempts = attempts + 1,
                    expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    updated_at = NOW()",
                [$tenantId, $identifier, $reason, $duration, $duration]
            );
            
            // Atualizar tentativa
            $updateQuery = "UPDATE scraping_attempts 
                           SET action_taken = 'blocked' 
                           WHERE ip_address = ? 
                           AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
            
            $updateParams = [$identifier];
            
            if ($tenantId !== null) {
                $updateQuery .= " AND tenant_id = ?";
                $updateParams[] = $tenantId;
            }
            
            $this->db->query($updateQuery, $updateParams);
        }
    }
    
    /**
     * Verificar se IP está bloqueado (filtrado por tenant)
     */
    public function isBlocked($ip) {
        $tenantId = $this->getTenantId();
        
        $query = "SELECT COUNT(*) as count FROM blocked_ips 
                  WHERE ip_address = ? 
                  AND (expires_at IS NULL OR expires_at > NOW())";
        
        $params = [$ip];
        
        // Filtrar por tenant se não for super admin
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $result = $this->db->query($query, $params);
        
        $row = $result->fetch();
        return $row && $row['count'] > 0;
    }
    
    /**
     * Obter informações do bloqueio (filtrado por tenant)
     */
    private function getBlockInfo($ip) {
        $tenantId = $this->getTenantId();
        
        $query = "SELECT * FROM blocked_ips WHERE ip_address = ?";
        $params = [$ip];
        
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $query .= " LIMIT 1";
        
        $result = $this->db->query($query, $params);
        
        return $result->fetch() ?: [];
    }
    
    /**
     * Verificar se IP está na whitelist (global OU do tenant)
     */
    public function isWhitelisted($ip) {
        $tenantId = $this->getTenantId();
        
        // Verificar whitelist global (tenant_id NULL) OU do tenant específico
        $query = "SELECT COUNT(*) as count FROM ip_whitelist 
                  WHERE ip_address = ? 
                  AND (expires_at IS NULL OR expires_at > NOW())
                  AND (tenant_id IS NULL";
        
        $params = [$ip];
        
        if ($tenantId !== null) {
            $query .= " OR tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $query .= ")";
        
        $result = $this->db->query($query, $params);
        
        $row = $result->fetch();
        return $row && $row['count'] > 0;
    }
    
    /**
     * Adicionar IP à whitelist (com tenant_id)
     */
    public function addToWhitelist($ip, $description = '', $expiresAt = null) {
        $tenantId = $this->getTenantId();
        
        // Verificar se já existe
        $existing = $this->db->selectOne('ip_whitelist', [
            'ip_address' => $ip,
            'tenant_id' => $tenantId
        ]);
        
        if ($existing) {
            return false; // Já existe
        }
        
        return $this->db->insert('ip_whitelist', [
            'tenant_id' => $tenantId,
            'ip_address' => $ip,
            'description' => $description,
            'expires_at' => $expiresAt
        ]);
    }
    
    /**
     * Remover IP da whitelist (verificando permissão por tenant)
     */
    public function removeFromWhitelist($ip) {
        $tenantId = $this->getTenantId();
        
        $conditions = ['ip_address' => $ip];
        
        // Se não for super admin, só pode remover IPs do seu tenant
        if ($tenantId !== null) {
            $conditions['tenant_id'] = $tenantId;
        }
        
        return $this->db->delete('ip_whitelist', $conditions);
    }
    
    /**
     * Desbloquear IP (verificando permissão por tenant)
     */
    public function unblockIP($ip) {
        $tenantId = $this->getTenantId();
        
        $conditions = ['ip_address' => $ip];
        
        // Se não for super admin, só pode desbloquear IPs do seu tenant
        if ($tenantId !== null) {
            $conditions['tenant_id'] = $tenantId;
        }
        
        return $this->db->delete('blocked_ips', $conditions);
    }
    
    /**
     * Limpar registros expirados (respeitando tenant)
     */
    public function cleanup($tenantId = null) {
        if ($tenantId === null) {
            $tenantId = $this->getTenantId();
        }
        
        // Limpar rate limits antigos
        $this->db->query(
            "DELETE FROM rate_limits WHERE window_end < ?",
            [time() - 86400] // Mais de 24h
        );
        
        // Limpar bloqueios temporários expirados
        $query = "DELETE FROM blocked_ips 
                  WHERE block_type = 'temporary' 
                  AND expires_at < NOW()";
        
        $params = [];
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $this->db->query($query, $params);
        
        // Limpar whitelist expirada
        $query = "DELETE FROM ip_whitelist 
                  WHERE expires_at IS NOT NULL 
                  AND expires_at < NOW()";
        
        $params = [];
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $this->db->query($query, $params);
    }
    
    /**
     * Obter estatísticas (filtradas por tenant)
     */
    public function getStats($tenantId = null) {
        if ($tenantId === null) {
            $tenantId = $this->getTenantId();
        }
        
        $stats = [];
        
        // IPs bloqueados ativos
        $query = "SELECT COUNT(*) as count FROM blocked_ips 
                  WHERE expires_at IS NULL OR expires_at > NOW()";
        $params = [];
        
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $result = $this->db->query($query, $params);
        $row = $result->fetch();
        $stats['blocked_ips'] = $row ? intval($row['count']) : 0;
        
        // Violações na última hora
        $query = "SELECT COUNT(*) as count FROM scraping_attempts 
                  WHERE detection_type = 'rate_limit' 
                  AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $params = [];
        
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $result = $this->db->query($query, $params);
        $row = $result->fetch();
        $stats['violations_last_hour'] = $row ? intval($row['count']) : 0;
        
        // Requisições ativas
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM rate_limits 
             WHERE window_end > ?",
            [time()]
        );
        $row = $result->fetch();
        $stats['active_windows'] = $row ? intval($row['count']) : 0;
        
        return $stats;
    }
    
    /**
     * Obter IPs mais suspeitos (Top Offenders - filtrado por tenant)
     */
    public function getTopOffenders($limit = 10, $tenantId = null) {
        if ($tenantId === null) {
            $tenantId = $this->getTenantId();
        }
        
        $query = "SELECT ip_address, COUNT(*) as violations
                  FROM scraping_attempts
                  WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        $params = [];
        if ($tenantId !== null) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $query .= " GROUP BY ip_address
                    ORDER BY violations DESC
                    LIMIT ?";
        
        $params[] = $limit;
        
        $result = $this->db->query($query, $params);
        
        $offenders = [];
        while ($row = $result->fetch()) {
            $offenders[] = [
                'ip' => $row['ip_address'],
                'violations' => intval($row['violations']),
                'blocked' => $this->isBlocked($row['ip_address'])
            ];
        }
        
        return $offenders;
    }
    
    /**
     * Bloquear IP (público - com tenant_id)
     */
    public function blockIP($ip, $reason, $severity = 'medium', $duration = null) {
        $tenantId = $this->getTenantId();
        
        // Verificar se já está bloqueado
        $existing = $this->db->selectOne('blocked_ips', [
            'ip_address' => $ip,
            'tenant_id' => $tenantId
        ]);
        
        if ($existing) {
            // Atualizar bloqueio existente
            $this->db->update('blocked_ips', [
                'reason' => $reason,
                'severity' => $severity,
                'attempts' => $existing['attempts'] + 1,
                'expires_at' => $duration ? date('Y-m-d H:i:s', time() + $duration) : null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $existing['id']]);
            
            return true;
        }
        
        // Criar novo bloqueio
        return $this->db->insert('blocked_ips', [
            'tenant_id' => $tenantId,
            'ip_address' => $ip,
            'reason' => $reason,
            'severity' => $severity,
            'block_type' => $duration ? 'temporary' : 'permanent',
            'attempts' => 1,
            'expires_at' => $duration ? date('Y-m-d H:i:s', time() + $duration) : null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'blocked_at' => date('Y-m-d H:i:s')
        ]);
    }
}