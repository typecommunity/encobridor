<?php
/**
 * Cloaker Pro - ScraperDetector
 * Sistema de detecção de scrapers e bots com Multi-Tenancy
 */

class ScraperDetector {
    private $db;
    private $rateLimiter;
    private $ip;
    private $userAgent;
    private $config;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->rateLimiter = new RateLimiter();
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Carregar configurações
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
     * Carregar configurações (globais ou do tenant)
     */
    private function loadConfig() {
        $tenantId = $this->getTenantId();
        
        $this->config = [
            'rate_limit_enabled' => true,
            'rate_limit_requests' => 60,
            'rate_limit_window' => 60,
            'scraper_detection_enabled' => true,
            'detect_headless_browsers' => true,
            'detect_automation_tools' => true,
            'suspicious_score_threshold' => 50,
            'auto_block_scrapers' => true,
            'captcha_enabled' => false,
            'captcha_threshold' => 70,
            'operation_mode' => 'active'
        ];
        
        // Buscar configs do banco: primeiro globais, depois específicas do tenant
        $query = "SELECT config_key, config_value FROM antiscraping_config 
                  WHERE tenant_id IS NULL";
        
        if ($tenantId) {
            $query .= " OR tenant_id = " . intval($tenantId);
        }
        
        $result = $this->db->query($query);
        
        if ($result) {
            while ($row = $result->fetch()) {
                $key = $row['config_key'];
                $value = $row['config_value'];
                
                // Converter tipo
                if (in_array($key, ['rate_limit_enabled', 'scraper_detection_enabled', 'detect_headless_browsers', 
                                    'detect_automation_tools', 'auto_block_scrapers', 'captcha_enabled'])) {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif (in_array($key, ['rate_limit_requests', 'rate_limit_window', 'suspicious_score_threshold', 
                                          'captcha_threshold'])) {
                    $value = intval($value);
                }
                
                $this->config[$key] = $value;
            }
        }
    }
    
    /**
     * Detectar se é scraper
     */
    public function detect() {
        $tenantId = $this->getTenantId();
        
        // Verificar whitelist primeiro
        if ($this->rateLimiter->isWhitelisted($this->ip)) {
            return [
                'action' => 'allow',
                'score' => 0,
                'reason' => 'whitelisted'
            ];
        }
        
        // Verificar se já está bloqueado
        if ($this->rateLimiter->isBlocked($this->ip)) {
            $this->logAttempt('ip_blocked', 100, 'blocked');
            return [
                'action' => 'block',
                'score' => 100,
                'reason' => 'ip_blocked'
            ];
        }
        
        $score = 0;
        $detections = [];
        
        // 1. Verificar rate limit
        if ($this->config['rate_limit_enabled']) {
            if (!$this->rateLimiter->checkRateLimit(
                $this->ip, 
                $this->config['rate_limit_requests'], 
                $this->config['rate_limit_window']
            )) {
                $score += 40;
                $detections[] = 'rate_limit_exceeded';
            }
        }
        
        // 2. Detectar scrapers conhecidos
        if ($this->config['scraper_detection_enabled']) {
            if ($this->detectKnownScrapers()) {
                $score += 50;
                $detections[] = 'known_scraper';
            }
        }
        
        // 3. Detectar navegadores headless
        if ($this->config['detect_headless_browsers']) {
            if ($this->detectHeadless()) {
                $score += 30;
                $detections[] = 'headless_browser';
            }
        }
        
        // 4. Detectar ferramentas de automação
        if ($this->config['detect_automation_tools']) {
            if ($this->detectAutomationTools()) {
                $score += 40;
                $detections[] = 'automation_tool';
            }
        }
        
        // 5. Análise comportamental
        $behaviorScore = $this->analyzeBehavior();
        $score += $behaviorScore;
        if ($behaviorScore > 0) {
            $detections[] = 'suspicious_behavior';
        }
        
        // Determinar ação
        $action = 'allow';
        $reason = 'legitimate_visitor';
        
        if ($score >= $this->config['suspicious_score_threshold']) {
            if ($this->config['captcha_enabled'] && $score >= $this->config['captcha_threshold']) {
                $action = 'captcha';
                $reason = 'high_suspicion_score';
            } elseif ($this->config['auto_block_scrapers'] && $score >= 70) {
                $action = 'block';
                $reason = 'critical_suspicion_score';
                
                // Bloquear IP
                $this->rateLimiter->blockIP(
                    $this->ip,
                    'Score de suspeiçã: ' . $score . ' - ' . implode(', ', $detections),
                    'high',
                    3600 // 1 hora
                );
            } else {
                $action = 'log';
                $reason = 'moderate_suspicion_score';
            }
        }
        
        // Log da tentativa
        if ($action !== 'allow' || $score > 0) {
            $this->logAttempt(implode(',', $detections) ?: 'general_check', $score, $action);
        }
        
        return [
            'action' => $action,
            'score' => $score,
            'reason' => $reason,
            'detections' => $detections
        ];
    }
    
    /**
     * Detectar scrapers conhecidos via User-Agent
     */
    private function detectKnownScrapers() {
        $scrapersPatterns = [
            'scrapy', 'selenium', 'puppeteer', 'playwright', 'phantomjs',
            'curl', 'wget', 'python-requests', 'axios', 'okhttp',
            'bot', 'crawler', 'spider', 'scraper'
        ];
        
        $ua = strtolower($this->userAgent);
        
        foreach ($scrapersPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return true;
            }
        }
        
        // Verificar contra banco de dados de bots
        $botAgents = $this->db->select('bot_agents', ['active' => 1]);
        foreach ($botAgents as $bot) {
            if (stripos($ua, $bot['pattern']) !== false) {
                // Incrementar contador
                $this->db->update('bot_agents', 
                    ['hits' => $bot['hits'] + 1], 
                    ['id' => $bot['id']]
                );
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detectar navegadores headless
     */
    private function detectHeadless() {
        $headlessIndicators = [
            'headless',
            'phantom',
            'selenium',
            'webdriver'
        ];
        
        $ua = strtolower($this->userAgent);
        
        foreach ($headlessIndicators as $indicator) {
            if (strpos($ua, $indicator) !== false) {
                return true;
            }
        }
        
        // Verificar headers suspeitos
        $headers = getallheaders();
        
        // User-Agent vazio ou muito curto
        if (empty($this->userAgent) || strlen($this->userAgent) < 20) {
            return true;
        }
        
        // Falta de headers comuns
        $requiredHeaders = ['Accept', 'Accept-Language', 'Accept-Encoding'];
        $missingHeaders = 0;
        
        foreach ($requiredHeaders as $header) {
            if (!isset($headers[$header])) {
                $missingHeaders++;
            }
        }
        
        return $missingHeaders >= 2;
    }
    
    /**
     * Detectar ferramentas de automação
     */
    private function detectAutomationTools() {
        $automationPatterns = [
            'automation', 'webdriver', 'selenium', 'cypress',
            'testcafe', 'nightwatch', 'casperjs'
        ];
        
        $ua = strtolower($this->userAgent);
        
        foreach ($automationPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Análise comportamental
     */
    private function analyzeBehavior() {
        $score = 0;
        
        // Verificar fingerprint se disponível
        $fingerprint = $_COOKIE['fp'] ?? null;
        
        if ($fingerprint) {
            $fpData = $this->db->selectOne('fingerprints', [
                'fingerprint_hash' => $fingerprint
            ]);
            
            if ($fpData) {
                // Verificar se é suspeito
                if ($fpData['is_suspicious']) {
                    $score += 20;
                }
                
                // Verificar trust score
                if ($fpData['trust_score'] < 30) {
                    $score += 15;
                }
                
                // Visitas muito frequentes
                if ($fpData['visit_count'] > 100) {
                    $score += 10;
                }
            }
        }
        
        // Verificar histórico de tentativas do IP
        $tenantId = $this->getTenantId();
        
        $conditions = [
            'ip_address' => $this->ip,
            'created_at >' => date('Y-m-d H:i:s', strtotime('-24 hours'))
        ];
        
        if ($tenantId !== null) {
            $conditions['tenant_id'] = $tenantId;
        }
        
        $recentAttempts = $this->db->count('scraping_attempts', $conditions);
        
        if ($recentAttempts > 10) {
            $score += min(30, $recentAttempts * 2);
        }
        
        return $score;
    }
    
    /**
     * Logar tentativa de scraping
     */
    private function logAttempt($detectionType, $score, $action) {
        $tenantId = $this->getTenantId();
        
        // Determinar severidade
        $severity = 'low';
        if ($score >= 70) {
            $severity = 'critical';
        } elseif ($score >= 50) {
            $severity = 'high';
        } elseif ($score >= 30) {
            $severity = 'medium';
        }
        
        $this->db->insert('scraping_attempts', [
            'tenant_id' => $tenantId,
            'ip_address' => $this->ip,
            'user_agent' => $this->userAgent,
            'detection_type' => $detectionType,
            'severity' => $severity,
            'score' => $score,
            'details' => json_encode([
                'headers' => getallheaders(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? ''
            ]),
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'action_taken' => $action,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Verificar se deve mostrar captcha
     */
    public function shouldShowCaptcha() {
        $result = $this->detect();
        return $result['action'] === 'captcha';
    }
    
    /**
     * Verificar se deve bloquear
     */
    public function shouldBlock() {
        $result = $this->detect();
        return $result['action'] === 'block';
    }
}