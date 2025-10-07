<?php
/**
 * Cloaker Pro - License
 * Sistema de validação de licença com AutoStacker
 */

class License {
    private $db;
    private $licenseKey;
    private $domain;
    private $cacheFile;
    private $lastCheck = null;
    private $isValid = null;
    private $licenseInfo = null;
    
    // Configurações
    private $cacheTime = 86400; // 24 horas
    private $graceTime = 604800; // 7 dias (caso servidor offline)
    private $apiTimeout = 5; // segundos
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->licenseKey = LICENSE_KEY;
        $this->domain = LICENSE_DOMAIN;
        $this->cacheFile = CACHE_PATH . '/license.cache';
        
        // Carregar cache se existir
        $this->loadCache();
    }
    
    /**
     * Verificar se a licença é válida
     */
    public function isValid() {
        // Modo debug sempre válido
        if (DEBUG_MODE && empty($this->licenseKey)) {
            return true;
        }
        
        // Se não há licença configurada, verificar instalação
        if (empty($this->licenseKey)) {
            $installed = $this->db->selectOne('settings', ['key_name' => 'installed']);
            
            // Permitir acesso durante instalação
            if (!$installed || $installed['value'] === 'false') {
                return true;
            }
            
            // Licença DEMO
            if (strpos($this->licenseKey, 'DEMO-') === 0) {
                return $this->validateDemoLicense();
            }
            
            return false;
        }
        
        // Verificar cache primeiro
        if ($this->isValid !== null && $this->isCacheValid()) {
            return $this->isValid;
        }
        
        // Validar com servidor AutoStacker
        $this->isValid = $this->validateRemote();
        
        // Salvar cache
        $this->saveCache();
        
        // Log de verificação
        $this->logValidation($this->isValid);
        
        return $this->isValid;
    }
    
    /**
     * Validar licença remotamente com AutoStacker
     */
    private function validateRemote() {
        try {
            $data = [
                'key' => $this->licenseKey,
                'domain' => $this->domain,
                'version' => APP_VERSION,
                'php_version' => PHP_VERSION,
                'ip' => $this->getServerIp(),
                'timestamp' => time()
            ];
            
            $ch = curl_init(AUTOSTACKER_API);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->apiTimeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: CloakerPro/' . APP_VERSION,
                    'X-License-Key: ' . $this->licenseKey,
                    'X-Domain: ' . $this->domain
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                throw new Exception("CURL Error: $error");
            }
            
            if ($httpCode !== 200) {
                throw new Exception("HTTP Error: $httpCode");
            }
            
            if (!$response) {
                throw new Exception("Empty response from license server");
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                throw new Exception("Invalid JSON response");
            }
            
            // Salvar informações da licença
            if (isset($result['valid']) && $result['valid'] === true) {
                $this->licenseInfo = [
                    'valid' => true,
                    'expires' => $result['expires'] ?? null,
                    'plan' => $result['plan'] ?? 'unknown',
                    'features' => $result['features'] ?? [],
                    'limits' => $result['limits'] ?? [],
                    'last_check' => time()
                ];
                
                // Atualizar configurações no banco
                $this->updateLicenseSettings($this->licenseInfo);
                
                return true;
            }
            
            // Licença inválida
            $this->licenseInfo = [
                'valid' => false,
                'reason' => $result['reason'] ?? 'Invalid license',
                'expired' => $result['expired'] ?? false,
                'last_check' => time()
            ];
            
            return false;
            
        } catch (Exception $e) {
            error_log('License validation error: ' . $e->getMessage());
            
            // Em caso de erro, usar fallback
            return $this->fallbackValidation();
        }
    }
    
    /**
     * Validação de fallback (servidor offline)
     */
    private function fallbackValidation() {
        // Se tem cache válido recente (grace period)
        if ($this->lastCheck && (time() - $this->lastCheck) < $this->graceTime) {
            // Usar última validação conhecida
            return $this->isValid ?? false;
        }
        
        // Verificar se é primeira execução
        $firstRun = $this->db->selectOne('settings', ['key_name' => 'first_license_check']);
        if (!$firstRun) {
            // Primeira execução - dar grace period
            $this->db->insert('settings', [
                'key_name' => 'first_license_check',
                'value' => time()
            ]);
            return true;
        }
        
        // Sem conexão e sem cache válido = inválido
        return false;
    }
    
    /**
     * Validar licença DEMO
     */
    private function validateDemoLicense() {
        // Licenças DEMO têm limitações
        $demoStart = $this->db->selectOne('settings', ['key_name' => 'demo_start']);
        
        if (!$demoStart) {
            // Iniciar período demo
            $this->db->insert('settings', [
                'key_name' => 'demo_start',
                'value' => time()
            ]);
            $demoStart = ['value' => time()];
        }
        
        // Demo válido por 30 dias
        $demoExpiry = intval($demoStart['value']) + (30 * 24 * 3600);
        
        if (time() > $demoExpiry) {
            $this->licenseInfo = [
                'valid' => false,
                'reason' => 'Demo license expired',
                'expired' => true
            ];
            return false;
        }
        
        $this->licenseInfo = [
            'valid' => true,
            'plan' => 'demo',
            'expires' => date('Y-m-d', $demoExpiry),
            'features' => ['basic'],
            'limits' => [
                'campaigns' => 3,
                'visitors_per_day' => 1000
            ]
        ];
        
        return true;
    }
    
    /**
     * Obter informações da licença
     */
    public function getInfo() {
        if (!$this->isValid()) {
            return null;
        }
        
        return $this->licenseInfo;
    }
    
    /**
     * Obter plano da licença
     */
    public function getPlan() {
        $info = $this->getInfo();
        return $info['plan'] ?? 'unknown';
    }
    
    /**
     * Verificar se tem feature específica
     */
    public function hasFeature($feature) {
        $info = $this->getInfo();
        
        if (!$info || !isset($info['features'])) {
            return false;
        }
        
        // Planos com todas features
        if (in_array($info['plan'], ['enterprise', 'lifetime'])) {
            return true;
        }
        
        return in_array($feature, $info['features']);
    }
    
    /**
     * Obter limite
     */
    public function getLimit($limit) {
        $info = $this->getInfo();
        
        if (!$info || !isset($info['limits'][$limit])) {
            return null;
        }
        
        return $info['limits'][$limit];
    }
    
    /**
     * Verificar se está dentro do limite
     */
    public function checkLimit($limit, $current) {
        $max = $this->getLimit($limit);
        
        if ($max === null) {
            return true; // Sem limite
        }
        
        return $current < $max;
    }
    
    /**
     * Carregar cache
     */
    private function loadCache() {
        if (!file_exists($this->cacheFile)) {
            return;
        }
        
        $cache = @file_get_contents($this->cacheFile);
        if (!$cache) {
            return;
        }
        
        $data = @unserialize($cache);
        if (!$data) {
            return;
        }
        
        $this->lastCheck = $data['last_check'] ?? null;
        $this->isValid = $data['is_valid'] ?? null;
        $this->licenseInfo = $data['license_info'] ?? null;
    }
    
    /**
     * Salvar cache
     */
    private function saveCache() {
        $data = [
            'last_check' => time(),
            'is_valid' => $this->isValid,
            'license_info' => $this->licenseInfo
        ];
        
        @file_put_contents($this->cacheFile, serialize($data), LOCK_EX);
    }
    
    /**
     * Verificar se cache é válido
     */
    private function isCacheValid() {
        if (!$this->lastCheck) {
            return false;
        }
        
        return (time() - $this->lastCheck) < $this->cacheTime;
    }
    
    /**
     * Atualizar configurações da licença no banco
     */
    private function updateLicenseSettings($info) {
        // Atualizar plan
        if (isset($info['plan'])) {
            $this->db->update(
                'settings',
                ['value' => $info['plan']],
                ['key_name' => 'license_plan']
            );
        }
        
        // Atualizar data de expiração
        if (isset($info['expires'])) {
            $this->db->update(
                'settings',
                ['value' => $info['expires']],
                ['key_name' => 'license_expires']
            );
        }
        
        // Atualizar última verificação
        $this->db->update(
            'settings',
            ['value' => time()],
            ['key_name' => 'license_last_check']
        );
    }
    
    /**
     * Log de validação
     */
    private function logValidation($isValid) {
        // Log em arquivo
        $logFile = LOGS_PATH . '/license.log';
        $logEntry = date('Y-m-d H:i:s') . ' | ';
        $logEntry .= $isValid ? 'VALID' : 'INVALID';
        $logEntry .= ' | Key: ' . substr($this->licenseKey, 0, 8) . '...';
        $logEntry .= ' | Domain: ' . $this->domain;
        $logEntry .= ' | IP: ' . $this->getServerIp();
        $logEntry .= PHP_EOL;
        
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Log no banco (opcional)
        if ($this->db) {
            $this->db->insert('system_logs', [
                'level' => $isValid ? 'info' : 'warning',
                'category' => 'license',
                'message' => $isValid ? 'License validated successfully' : 'License validation failed',
                'context' => json_encode([
                    'key' => substr($this->licenseKey, 0, 8) . '...',
                    'domain' => $this->domain,
                    'plan' => $this->licenseInfo['plan'] ?? null,
                    'expires' => $this->licenseInfo['expires'] ?? null
                ]),
                'ip_address' => $this->getServerIp()
            ]);
        }
    }
    
    /**
     * Obter IP do servidor
     */
    private function getServerIp() {
        // Tentar obter IP externo
        $ip = @file_get_contents('https://api.ipify.org');
        
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        // Fallback para IP local
        return $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
    }
    
    /**
     * Ativar licença
     */
    public function activate($licenseKey, $domain = null) {
        $this->licenseKey = $licenseKey;
        $this->domain = $domain ?? $this->domain;
        
        // Limpar cache
        @unlink($this->cacheFile);
        $this->lastCheck = null;
        $this->isValid = null;
        $this->licenseInfo = null;
        
        // Validar
        $isValid = $this->isValid();
        
        if ($isValid) {
            // Salvar no banco
            $this->db->update(
                'settings',
                ['value' => $licenseKey],
                ['key_name' => 'license_key']
            );
            
            $this->db->update(
                'settings',
                ['value' => $this->domain],
                ['key_name' => 'license_domain']
            );
            
            // Atualizar constantes (para a sessão atual)
            if (!defined('LICENSE_KEY')) {
                define('LICENSE_KEY', $licenseKey);
            }
            
            if (!defined('LICENSE_DOMAIN')) {
                define('LICENSE_DOMAIN', $this->domain);
            }
        }
        
        return $isValid;
    }
    
    /**
     * Desativar licença
     */
    public function deactivate() {
        // Fazer chamada de desativação para AutoStacker
        if (!empty($this->licenseKey)) {
            $data = [
                'key' => $this->licenseKey,
                'domain' => $this->domain,
                'action' => 'deactivate'
            ];
            
            $ch = curl_init(AUTOSTACKER_API);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->apiTimeout,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ]
            ]);
            
            curl_exec($ch);
            curl_close($ch);
        }
        
        // Limpar licença local
        $this->db->update(
            'settings',
            ['value' => ''],
            ['key_name' => 'license_key']
        );
        
        $this->db->update(
            'settings',
            ['value' => ''],
            ['key_name' => 'license_domain']
        );
        
        // Limpar cache
        @unlink($this->cacheFile);
        
        $this->licenseKey = '';
        $this->isValid = false;
        $this->licenseInfo = null;
    }
    
    /**
     * Renovar licença (verificar atualizações)
     */
    public function renew() {
        // Forçar nova verificação
        @unlink($this->cacheFile);
        $this->lastCheck = null;
        $this->isValid = null;
        
        return $this->isValid();
    }
}