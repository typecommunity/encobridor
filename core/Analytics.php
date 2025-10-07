<?php
/**
 * Cloaker Pro - Analytics System
 * Sistema de análise de dados e estatísticas com Multi-Tenancy
 * 
 * @version 2.2.2 - CORRIGIDO: Ambiguidade em created_at com JOIN
 */

class Analytics {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Método track() - Registrar visitante
     */
    public function track($campaignId, $visitor, $decision) {
        try {
            $data = [
                'campaign_id' => $campaignId,
                'ip_address' => $visitor['ip'] ?? null,
                'country' => $visitor['country_code'] ?? null,
                'device_type' => $visitor['device_type'] ?? 'unknown',
                'action' => $decision['action'] ?? 'safe',
                'is_bot' => isset($visitor['is_bot']) ? (int)$visitor['is_bot'] : 0,
                'user_agent' => $visitor['user_agent'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            return $this->db->insert('visitor_logs', $data);
            
        } catch (Exception $e) {
            error_log("Analytics::track error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Registrar visitante com dados completos
     */
    public function logVisitor($data) {
        try {
            if (!isset($data['created_at'])) {
                $data['created_at'] = date('Y-m-d H:i:s');
            }
            
            return $this->db->insert('visitor_logs', $data);
        } catch (Exception $e) {
            error_log("logVisitor error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter total de visitantes no período
     * CORRIGIDO: Prefixo de tabela em getPeriodWhere
     */
    public function getTotalVisitors($period = 'today', $filters = []) {
        try {
            if (isset($filters['tenant_id'])) {
                // Filtrar por tenant - JOIN com prefixo vl
                $periodWhere = $this->getPeriodWhere($period, 'vl');
                $result = $this->db->raw(
                    "SELECT COUNT(*) as total 
                    FROM visitor_logs vl
                    JOIN campaigns c ON vl.campaign_id = c.id
                    WHERE c.tenant_id = ? AND $periodWhere",
                    [$filters['tenant_id']]
                );
            } else {
                // Super admin - sem JOIN, sem prefixo
                $periodWhere = $this->getPeriodWhere($period);
                $result = $this->db->raw(
                    "SELECT COUNT(*) as total FROM visitor_logs WHERE $periodWhere",
                    []
                );
            }
            
            $row = $result->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (Exception $e) {
            error_log("getTotalVisitors error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Obter visitantes únicos (por IP)
     * CORRIGIDO: Prefixo de tabela em getPeriodWhere
     */
    public function getUniqueVisitors($period = 'today', $filters = []) {
        try {
            if (isset($filters['tenant_id'])) {
                $periodWhere = $this->getPeriodWhere($period, 'vl');
                $result = $this->db->raw(
                    "SELECT COUNT(DISTINCT vl.ip_address) as total 
                    FROM visitor_logs vl
                    JOIN campaigns c ON vl.campaign_id = c.id
                    WHERE c.tenant_id = ? AND $periodWhere",
                    [$filters['tenant_id']]
                );
            } else {
                $periodWhere = $this->getPeriodWhere($period);
                $result = $this->db->raw(
                    "SELECT COUNT(DISTINCT ip_address) as total FROM visitor_logs WHERE $periodWhere",
                    []
                );
            }
            
            $row = $result->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (Exception $e) {
            error_log("getUniqueVisitors error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Obter redirecionamentos por tipo (safe/money)
     * CORRIGIDO: Prefixo de tabela em getPeriodWhere
     */
    public function getRedirects($type, $period = 'today', $filters = []) {
        try {
            if (isset($filters['tenant_id'])) {
                $periodWhere = $this->getPeriodWhere($period, 'vl');
                $result = $this->db->raw(
                    "SELECT COUNT(*) as total 
                    FROM visitor_logs vl
                    JOIN campaigns c ON vl.campaign_id = c.id
                    WHERE vl.action = ? AND c.tenant_id = ? AND $periodWhere",
                    [$type, $filters['tenant_id']]
                );
            } else {
                $periodWhere = $this->getPeriodWhere($period);
                $result = $this->db->raw(
                    "SELECT COUNT(*) as total FROM visitor_logs WHERE action = ? AND $periodWhere",
                    [$type]
                );
            }
            
            $row = $result->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (Exception $e) {
            error_log("getRedirects error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Obter total de bots bloqueados
     * CORRIGIDO: Prefixo de tabela em getPeriodWhere
     */
    public function getBotBlocks($period = 'today', $filters = []) {
        try {
            if (isset($filters['tenant_id'])) {
                $periodWhere = $this->getPeriodWhere($period, 'vl');
                $result = $this->db->raw(
                    "SELECT COUNT(*) as total 
                    FROM visitor_logs vl
                    JOIN campaigns c ON vl.campaign_id = c.id
                    WHERE vl.is_bot = 1 AND c.tenant_id = ? AND $periodWhere",
                    [$filters['tenant_id']]
                );
            } else {
                $periodWhere = $this->getPeriodWhere($period);
                $result = $this->db->raw(
                    "SELECT COUNT(*) as total FROM visitor_logs WHERE is_bot = 1 AND $periodWhere",
                    []
                );
            }
            
            $row = $result->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (Exception $e) {
            error_log("getBotBlocks error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Calcular taxa de conversão (money/total)
     */
    public function getConversionRate($period = 'today', $filters = []) {
        $total = $this->getTotalVisitors($period, $filters);
        if ($total == 0) return 0;
        
        $money = $this->getRedirects('money', $period, $filters);
        
        return round(($money / $total) * 100, 2);
    }
    
    /**
     * Obter tráfego por hora do dia
     */
    public function getHourlyTraffic($campaignId = null, $filters = []) {
        $date = date('Y-m-d');
        
        try {
            $where = ["DATE(vl.created_at) = ?"];
            $params = [$date];
            
            if ($campaignId) {
                $where[] = "vl.campaign_id = ?";
                $params[] = $campaignId;
            }
            
            if (isset($filters['tenant_id'])) {
                $where[] = "c.tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $result = $this->db->raw(
                "SELECT 
                    HOUR(vl.created_at) as hour,
                    COUNT(*) as visitors
                FROM visitor_logs vl
                JOIN campaigns c ON vl.campaign_id = c.id
                WHERE $whereClause
                GROUP BY HOUR(vl.created_at)
                ORDER BY hour",
                $params
            );
            
            $data = [];
            for ($i = 0; $i < 24; $i++) {
                $data[$i] = [
                    'hour' => sprintf('%02d:00', $i),
                    'visitors' => 0
                ];
            }
            
            while ($row = $result->fetch()) {
                $hour = (int)$row['hour'];
                $data[$hour]['visitors'] = (int)$row['visitors'];
            }
            
            return array_values($data);
            
        } catch (Exception $e) {
            error_log("getHourlyTraffic error: " . $e->getMessage());
            
            $data = [];
            for ($i = 0; $i < 24; $i++) {
                $data[] = [
                    'hour' => sprintf('%02d:00', $i),
                    'visitors' => 0
                ];
            }
            return $data;
        }
    }
    
    /**
     * Obter top países com mais tráfego
     */
    public function getTopCountries($limit = 10, $campaignId = null, $filters = []) {
        try {
            // Primeiro, obter o total de visitantes
            $totalWhere = ["DATE(vl.created_at) = CURDATE()"];
            $totalParams = [];
            
            if ($campaignId) {
                $totalWhere[] = "vl.campaign_id = ?";
                $totalParams[] = $campaignId;
            }
            
            if (isset($filters['tenant_id'])) {
                $totalWhere[] = "c.tenant_id = ?";
                $totalParams[] = $filters['tenant_id'];
            }
            
            $totalWhereClause = implode(' AND ', $totalWhere);
            
            $totalResult = $this->db->raw(
                "SELECT COUNT(*) as total 
                FROM visitor_logs vl
                JOIN campaigns c ON vl.campaign_id = c.id
                WHERE $totalWhereClause",
                $totalParams
            );
            
            $totalRow = $totalResult->fetch();
            $totalVisitors = $totalRow ? (int)$totalRow['total'] : 0;
            
            // Buscar os países
            $where = ["DATE(vl.created_at) = CURDATE()", "vl.country IS NOT NULL", "vl.country != ''"];
            $params = [];
            
            if ($campaignId) {
                $where[] = "vl.campaign_id = ?";
                $params[] = $campaignId;
            }
            
            if (isset($filters['tenant_id'])) {
                $where[] = "c.tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }
            
            $whereClause = implode(' AND ', $where);
            $params[] = $limit;
            
            $result = $this->db->raw(
                "SELECT 
                    vl.country,
                    COUNT(*) as count
                FROM visitor_logs vl
                JOIN campaigns c ON vl.campaign_id = c.id
                WHERE $whereClause
                GROUP BY vl.country
                ORDER BY count DESC
                LIMIT ?",
                $params
            );
            
            $countries = [];
            
            $flags = [
                'BR' => '🇧🇷', 'US' => '🇺🇸', 'GB' => '🇬🇧', 'DE' => '🇩🇪',
                'FR' => '🇫🇷', 'IT' => '🇮🇹', 'ES' => '🇪🇸', 'PT' => '🇵🇹',
                'AR' => '🇦🇷', 'MX' => '🇲🇽', 'CL' => '🇨🇱', 'CO' => '🇨🇴',
                'CA' => '🇨🇦', 'AU' => '🇦🇺', 'NL' => '🇳🇱', 'BE' => '🇧🇪',
                'CH' => '🇨🇭', 'SE' => '🇸🇪', 'NO' => '🇳🇴', 'DK' => '🇩🇰',
                'FI' => '🇫🇮', 'PL' => '🇵🇱', 'RU' => '🇷🇺', 'JP' => '🇯🇵',
                'CN' => '🇨🇳', 'IN' => '🇮🇳', 'KR' => '🇰🇷', 'ID' => '🇮🇩'
            ];
            
            while ($row = $result->fetch()) {
                $countryCode = strtoupper($row['country']);
                $count = (int)$row['count'];
                $percentage = $totalVisitors > 0 ? round(($count / $totalVisitors) * 100, 1) : 0;
                
                $countries[] = [
                    'code' => $countryCode,
                    'name' => $this->getCountryName($countryCode),
                    'flag' => $flags[$countryCode] ?? '🌍',
                    'count' => $count,
                    'percentage' => $percentage
                ];
            }
            
            return $countries;
            
        } catch (Exception $e) {
            error_log("getTopCountries error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obter distribuição por tipo de dispositivo
     */
    public function getDeviceBreakdown($campaignId = null, $filters = []) {
        try {
            $where = ["DATE(vl.created_at) = CURDATE()"];
            $params = [];
            
            if ($campaignId) {
                $where[] = "vl.campaign_id = ?";
                $params[] = $campaignId;
            }
            
            if (isset($filters['tenant_id'])) {
                $where[] = "c.tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $result = $this->db->raw(
                "SELECT 
                    vl.device_type,
                    COUNT(*) as count
                FROM visitor_logs vl
                JOIN campaigns c ON vl.campaign_id = c.id
                WHERE $whereClause
                GROUP BY vl.device_type",
                $params
            );
            
            $devices = [];
            while ($row = $result->fetch()) {
                $type = $row['device_type'] ?? 'Unknown';
                $devices[] = [
                    'type' => ucfirst($type),
                    'count' => (int)$row['count']
                ];
            }
            
            if (empty($devices)) {
                $devices = [
                    ['type' => 'Desktop', 'count' => 0],
                    ['type' => 'Mobile', 'count' => 0],
                    ['type' => 'Tablet', 'count' => 0]
                ];
            }
            
            return $devices;
            
        } catch (Exception $e) {
            error_log("getDeviceBreakdown error: " . $e->getMessage());
            return [
                ['type' => 'Desktop', 'count' => 0],
                ['type' => 'Mobile', 'count' => 0],
                ['type' => 'Tablet', 'count' => 0]
            ];
        }
    }
    
    /**
     * Obter estatísticas de uma campanha específica
     */
    public function getCampaignStats($campaignId, $period = 'all') {
        $periodWhere = $this->getPeriodWhere($period, 'vl');
        
        try {
            $tableExists = $this->db->raw("SHOW TABLES LIKE 'visitor_logs'")->fetch();
            
            if (!$tableExists) {
                return [
                    'total' => 0,
                    'unique' => 0,
                    'safe' => 0,
                    'money' => 0,
                    'conversions' => 0,
                    'bots' => 0
                ];
            }
            
            $result = $this->db->raw(
                "SELECT 
                    COUNT(*) as total,
                    COUNT(DISTINCT vl.ip_address) as unique_visitors,
                    SUM(CASE WHEN vl.action = 'safe' THEN 1 ELSE 0 END) as safe,
                    SUM(CASE WHEN vl.action = 'money' THEN 1 ELSE 0 END) as money,
                    SUM(CASE WHEN vl.is_bot = 1 THEN 1 ELSE 0 END) as bots
                FROM visitor_logs vl
                WHERE vl.campaign_id = ? AND $periodWhere",
                [$campaignId]
            )->fetch();
            
            return [
                'total' => (int)($result['total'] ?? 0),
                'unique' => (int)($result['unique_visitors'] ?? 0),
                'safe' => (int)($result['safe'] ?? 0),
                'money' => (int)($result['money'] ?? 0),
                'conversions' => (int)($result['money'] ?? 0),
                'bots' => (int)($result['bots'] ?? 0)
            ];
            
        } catch (Exception $e) {
            error_log("getCampaignStats error: " . $e->getMessage());
            return [
                'total' => 0,
                'unique' => 0,
                'safe' => 0,
                'money' => 0,
                'conversions' => 0,
                'bots' => 0
            ];
        }
    }
    
    /**
     * Limpar logs antigos do banco de dados
     */
    public function cleanOldLogs($days = 90) {
        try {
            return $this->db->raw(
                "DELETE FROM visitor_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
        } catch (Exception $e) {
            error_log("cleanOldLogs error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter cláusula WHERE baseada no período
     */
    private function getPeriodWhere($period, $tablePrefix = '') {
        $prefix = $tablePrefix ? $tablePrefix . '.' : '';
        
        switch ($period) {
            case 'today':
                return "DATE({$prefix}created_at) = CURDATE()";
                
            case 'yesterday':
                return "DATE({$prefix}created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                
            case 'week':
                return "{$prefix}created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                
            case 'month':
                return "{$prefix}created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                
            case 'year':
                return "{$prefix}created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
                
            default:
                return "1=1";
        }
    }
    
    /**
     * Obter nome do país por código ISO
     */
    private function getCountryName($code) {
        $countries = [
            'BR' => 'Brasil',
            'US' => 'Estados Unidos',
            'GB' => 'Reino Unido',
            'DE' => 'Alemanha',
            'FR' => 'França',
            'IT' => 'Itália',
            'ES' => 'Espanha',
            'PT' => 'Portugal',
            'AR' => 'Argentina',
            'MX' => 'México',
            'CL' => 'Chile',
            'CO' => 'Colômbia',
            'CA' => 'Canadá',
            'AU' => 'Austrália',
            'NL' => 'Holanda',
            'BE' => 'Bélgica',
            'CH' => 'Suíça',
            'SE' => 'Suécia',
            'NO' => 'Noruega',
            'DK' => 'Dinamarca',
            'FI' => 'Finlândia',
            'PL' => 'Polônia',
            'RU' => 'Rússia',
            'JP' => 'Japão',
            'CN' => 'China',
            'IN' => 'Índia',
            'KR' => 'Coreia do Sul',
            'ID' => 'Indonésia'
        ];
        
        return $countries[$code] ?? $code;
    }
}
?>