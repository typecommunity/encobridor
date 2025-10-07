<?php
/**
 * Cloaker Pro - Rules
 * Sistema de avaliação de regras de cloaking
 */

class Rules {
    private $db;
    private $rules = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Avaliar regras para uma campanha e visitante
     */
    public function evaluate($campaignId, $visitor) {
        // Carregar regras ativas da campanha
        $this->loadRules($campaignId);
        
        if (empty($this->rules)) {
            return null;
        }
        
        // Avaliar cada regra em ordem de prioridade
        foreach ($this->rules as $rule) {
            if ($this->matchRule($rule, $visitor)) {
                // Incrementar hits da regra
                $this->db->increment('rules', 'hits', 1, ['id' => $rule['id']]);
                
                return [
                    'action' => $rule['action'],
                    'rule_id' => $rule['id'],
                    'rule_name' => $rule['name'],
                    'redirect_url' => $rule['redirect_url']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Carregar regras da campanha
     */
    private function loadRules($campaignId) {
        $this->rules = $this->db->select(
            'rules',
            [
                'campaign_id' => $campaignId,
                'active' => 1
            ],
            '*',
            'priority DESC, id ASC'
        );
    }
    
    /**
     * Verificar se uma regra corresponde ao visitante
     */
    private function matchRule($rule, $visitor) {
        $field = $this->getFieldValue($rule['type'], $rule['field'], $visitor);
        $ruleValue = $rule['value'];
        $operator = $rule['operator'];
        
        // Se o campo não existe no visitante
        if ($field === null) {
            return false;
        }
        
        // Avaliar baseado no operador
        switch ($operator) {
            case 'equals':
                return $this->checkEquals($field, $ruleValue);
                
            case 'not_equals':
                return !$this->checkEquals($field, $ruleValue);
                
            case 'contains':
                return $this->checkContains($field, $ruleValue);
                
            case 'not_contains':
                return !$this->checkContains($field, $ruleValue);
                
            case 'in':
                return $this->checkIn($field, $ruleValue);
                
            case 'not_in':
                return !$this->checkIn($field, $ruleValue);
                
            case 'starts_with':
                return $this->checkStartsWith($field, $ruleValue);
                
            case 'ends_with':
                return $this->checkEndsWith($field, $ruleValue);
                
            case 'regex':
                return $this->checkRegex($field, $ruleValue);
                
            case 'between':
                return $this->checkBetween($field, $ruleValue);
                
            case 'greater_than':
                return $this->checkGreaterThan($field, $ruleValue);
                
            case 'less_than':
                return $this->checkLessThan($field, $ruleValue);
                
            default:
                return false;
        }
    }
    
    /**
     * Obter valor do campo do visitante
     */
    private function getFieldValue($type, $field, $visitor) {
        switch ($type) {
            case 'geo':
                return $this->getGeoField($field, $visitor);
                
            case 'device':
                return $this->getDeviceField($field, $visitor);
                
            case 'isp':
                return $visitor['isp'] ?? null;
                
            case 'ip':
                return $visitor['ip'] ?? null;
                
            case 'referer':
                return $visitor['referer'] ?? null;
                
            case 'time':
                return $this->getTimeField($field);
                
            case 'bot':
                return $visitor['is_bot'] ? 'true' : 'false';
                
            case 'vpn':
                return $visitor['is_vpn'] ? 'true' : 'false';
                
            case 'proxy':
                return $visitor['is_proxy'] ? 'true' : 'false';
                
            case 'language':
                return $visitor['language'] ?? null;
                
            case 'browser':
                return $visitor['browser'] ?? null;
                
            case 'os':
                return $visitor['os'] ?? null;
                
            case 'custom':
                return $this->getCustomField($field, $visitor);
                
            default:
                return null;
        }
    }
    
    /**
     * Obter campo geográfico
     */
    private function getGeoField($field, $visitor) {
        switch ($field) {
            case 'country':
            case 'country_code':
                return $visitor['country_code'] ?? null;
                
            case 'country_name':
                return $visitor['country_name'] ?? null;
                
            case 'city':
                return $visitor['city'] ?? null;
                
            case 'region':
            case 'state':
                return $visitor['region'] ?? null;
                
            case 'postal_code':
            case 'zip':
                return $visitor['postal_code'] ?? null;
                
            case 'latitude':
                return $visitor['latitude'] ?? null;
                
            case 'longitude':
                return $visitor['longitude'] ?? null;
                
            case 'timezone':
                return $visitor['timezone'] ?? null;
                
            default:
                return null;
        }
    }
    
    /**
     * Obter campo de dispositivo
     */
    private function getDeviceField($field, $visitor) {
        switch ($field) {
            case 'type':
                return $visitor['device_type'] ?? null;
                
            case 'brand':
                return $visitor['device_brand'] ?? null;
                
            case 'model':
                return $visitor['device_model'] ?? null;
                
            case 'is_mobile':
                return $visitor['is_mobile'] ? 'true' : 'false';
                
            case 'is_tablet':
                return $visitor['is_tablet'] ? 'true' : 'false';
                
            case 'is_desktop':
                return $visitor['is_desktop'] ? 'true' : 'false';
                
            default:
                return null;
        }
    }
    
    /**
     * Obter campo de tempo
     */
    private function getTimeField($field) {
        $now = time();
        
        switch ($field) {
            case 'hour':
                return date('H', $now);
                
            case 'day':
                return date('d', $now);
                
            case 'month':
                return date('m', $now);
                
            case 'year':
                return date('Y', $now);
                
            case 'weekday':
                return date('w', $now); // 0 = domingo, 6 = sábado
                
            case 'time':
                return date('H:i', $now);
                
            case 'date':
                return date('Y-m-d', $now);
                
            case 'timestamp':
                return $now;
                
            default:
                return null;
        }
    }
    
    /**
     * Obter campo customizado
     */
    private function getCustomField($field, $visitor) {
        // Campos customizados podem vir de headers, query strings, etc.
        
        // Verificar query string
        if (isset($_GET[$field])) {
            return $_GET[$field];
        }
        
        // Verificar headers
        $headerField = 'HTTP_' . strtoupper(str_replace('-', '_', $field));
        if (isset($visitor['headers'][$headerField])) {
            return $visitor['headers'][$headerField];
        }
        
        // Verificar campos do visitante diretamente
        if (isset($visitor[$field])) {
            return $visitor[$field];
        }
        
        return null;
    }
    
    /**
     * Verificação: equals
     */
    private function checkEquals($field, $value) {
        // Case-insensitive para strings
        if (is_string($field) && is_string($value)) {
            return strcasecmp($field, $value) === 0;
        }
        return $field == $value;
    }
    
    /**
     * Verificação: contains
     */
    private function checkContains($field, $value) {
        if (!is_string($field) || !is_string($value)) {
            return false;
        }
        return stripos($field, $value) !== false;
    }
    
    /**
     * Verificação: in (lista)
     */
    private function checkIn($field, $value) {
        // Value pode ser uma string separada por vírgulas ou JSON array
        $values = $this->parseListValue($value);
        
        foreach ($values as $v) {
            if ($this->checkEquals($field, trim($v))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificação: starts_with
     */
    private function checkStartsWith($field, $value) {
        if (!is_string($field) || !is_string($value)) {
            return false;
        }
        return stripos($field, $value) === 0;
    }
    
    /**
     * Verificação: ends_with
     */
    private function checkEndsWith($field, $value) {
        if (!is_string($field) || !is_string($value)) {
            return false;
        }
        $length = strlen($value);
        if ($length === 0) {
            return true;
        }
        return stripos($field, $value, -$length) !== false;
    }
    
    /**
     * Verificação: regex
     */
    private function checkRegex($field, $pattern) {
        if (!is_string($field) || !is_string($pattern)) {
            return false;
        }
        
        // Adicionar delimitadores se não existirem
        if ($pattern[0] !== '/' && $pattern[0] !== '#' && $pattern[0] !== '~') {
            $pattern = '/' . $pattern . '/i';
        }
        
        return @preg_match($pattern, $field) === 1;
    }
    
    /**
     * Verificação: between
     */
    private function checkBetween($field, $value) {
        // Value deve ser no formato "min,max" ou "min-max"
        $parts = preg_split('/[,\-]/', $value, 2);
        
        if (count($parts) !== 2) {
            return false;
        }
        
        $min = trim($parts[0]);
        $max = trim($parts[1]);
        
        // Comparação numérica se possível
        if (is_numeric($field) && is_numeric($min) && is_numeric($max)) {
            $field = floatval($field);
            $min = floatval($min);
            $max = floatval($max);
            return $field >= $min && $field <= $max;
        }
        
        // Comparação de strings
        return strcmp($field, $min) >= 0 && strcmp($field, $max) <= 0;
    }
    
    /**
     * Verificação: greater_than
     */
    private function checkGreaterThan($field, $value) {
        if (is_numeric($field) && is_numeric($value)) {
            return floatval($field) > floatval($value);
        }
        return strcmp($field, $value) > 0;
    }
    
    /**
     * Verificação: less_than
     */
    private function checkLessThan($field, $value) {
        if (is_numeric($field) && is_numeric($value)) {
            return floatval($field) < floatval($value);
        }
        return strcmp($field, $value) < 0;
    }
    
    /**
     * Parse de valor de lista
     */
    private function parseListValue($value) {
        // Tentar JSON primeiro
        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }
        
        // Separar por vírgulas
        return array_map('trim', explode(',', $value));
    }
    
    /**
     * Criar uma nova regra
     */
    public function create($data) {
        $required = ['campaign_id', 'type', 'operator', 'value', 'action'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field '$field' is required");
            }
        }
        
        $insertData = [
            'campaign_id' => $data['campaign_id'],
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'field' => $data['field'] ?? null,
            'operator' => $data['operator'],
            'value' => $data['value'],
            'action' => $data['action'],
            'redirect_url' => $data['redirect_url'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'active' => $data['active'] ?? 1
        ];
        
        return $this->db->insert('rules', $insertData);
    }
    
    /**
     * Atualizar uma regra
     */
    public function update($id, $data) {
        $updateData = [];
        
        $allowedFields = [
            'name', 'description', 'type', 'field', 'operator',
            'value', 'action', 'redirect_url', 'priority', 'active'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (empty($updateData)) {
            return false;
        }
        
        return $this->db->update('rules', $updateData, ['id' => $id]);
    }
    
    /**
     * Deletar uma regra
     */
    public function delete($id) {
        return $this->db->delete('rules', ['id' => $id]);
    }
    
    /**
     * Obter regras de uma campanha
     */
    public function getRulesByCampaign($campaignId) {
        return $this->db->select(
            'rules',
            ['campaign_id' => $campaignId],
            '*',
            'priority DESC, id ASC'
        );
    }
    
    /**
     * Duplicar regras de uma campanha para outra
     */
    public function duplicateRules($fromCampaignId, $toCampaignId) {
        $rules = $this->getRulesByCampaign($fromCampaignId);
        
        foreach ($rules as $rule) {
            unset($rule['id']);
            unset($rule['hits']);
            unset($rule['created_at']);
            unset($rule['updated_at']);
            
            $rule['campaign_id'] = $toCampaignId;
            
            $this->db->insert('rules', $rule);
        }
        
        return count($rules);
    }
    
    /**
     * Resetar hits das regras
     */
    public function resetHits($campaignId = null) {
        $where = [];
        if ($campaignId) {
            $where['campaign_id'] = $campaignId;
        }
        
        return $this->db->update('rules', ['hits' => 0], $where);
    }
}