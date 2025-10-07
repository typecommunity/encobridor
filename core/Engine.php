<?php
/**
 * Cloaker Pro - Engine v2.2 CORRIGIDO
 * Motor principal do sistema de cloaking
 * 
 * CORREÇÕES v2.2:
 * - Rate Limiting corrigido (getPdo -> getConnection, fetch adicionado)
 * - Scraper Detection específico
 * - failsAdvancedChecks corrigido para não quebrar sem fingerprint
 * - Respeita settings de fingerprinting desabilitado
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Detector.php';
require_once __DIR__ . '/Rules.php';
require_once __DIR__ . '/Campaign.php';
require_once __DIR__ . '/Analytics.php';

class Engine {
    private $db;
    private $detector;
    private $rules;
    private $campaign;
    private $analytics;
    private $startTime;
    
    public function __construct() {
        $this->startTime = microtime(true);
        $this->db = Database::getInstance();
        $this->detector = new Detector();
        $this->rules = new Rules();
        $this->campaign = new Campaign();
        $this->analytics = new Analytics();
    }
    
    /**
     * Processar visitante e retornar decisão
     */
    public function process($campaignId, $request) {
        try {
            // Obter dados da campanha
            $campaign = $this->campaign->get($campaignId);
            if (!$campaign) {
                throw new Exception("Campaign not found: $campaignId");
            }
            
            if ($campaign['status'] !== 'active') {
                throw new Exception("Campaign is inactive: $campaignId");
            }
            
            // Decodificar settings e rules
            $settings = json_decode($campaign['settings'] ?? '{}', true) ?: [];
            $rules = json_decode($campaign['rules'] ?? '{}', true) ?: [];
            
            // Analisar visitante
            $visitor = $this->detector->analyze($request);
            $visitor['campaign_id'] = $campaignId;
            
            // VERIFICAR RATE LIMIT (CORRIGIDO)
            if (!empty($settings['enable_rate_limit'])) {
                if (!$this->checkRateLimit($visitor['ip'], $campaignId, $settings)) {
                    return $this->createDecision('safe', $campaign, 'rate_limit_exceeded');
                }
            }
            
            // Verificar cache
            if (CACHE_ENABLED) {
                $cacheKey = $this->generateCacheKey($campaignId, $visitor);
                $cached = $this->getFromCache($cacheKey);
                if ($cached !== false) {
                    $this->logVisitor($visitor, $cached, 'cache_hit');
                    return $cached;
                }
            }
            
            // Avaliar decisão com TODOS os filtros
            $decision = $this->evaluateDecision($campaign, $settings, $rules, $visitor);
            
            // Adicionar tempo de processamento
            $decision['response_time'] = round((microtime(true) - $this->startTime) * 1000);
            
            // Registrar visitante
            $this->logVisitor($visitor, $decision);
            
            // Incrementar hits
            $this->campaign->incrementHits($campaignId);
            
            // Salvar analytics
            if ($settings['track_analytics'] ?? true) {
                $this->analytics->track($campaignId, $visitor, $decision);
            }
            
            // Cachear decisão
            if (CACHE_ENABLED) {
                $this->saveToCache($cacheKey, $decision);
            }
            
            return $decision;
            
        } catch (Exception $e) {
            error_log('Cloaker Engine Error: ' . $e->getMessage());
            
            return [
                'action' => 'safe',
                'url' => $campaign['safe_page'] ?? 'https://google.com',
                'reason' => 'error',
                'error' => DEBUG_MODE ? $e->getMessage() : 'System error',
                'response_time' => round((microtime(true) - $this->startTime) * 1000)
            ];
        }
    }
    
    /**
     * RATE LIMITING - CORRIGIDO
     * Limitar número de requisições por IP
     */
    private function checkRateLimit($ip, $campaignId, $settings) {
        // Configurações padrão
        $maxRequests = $settings['rate_limit_max'] ?? 10; // requisições
        $timeWindow = $settings['rate_limit_window'] ?? 60; // segundos
        
        $cacheKey = "rate_limit_{$campaignId}_{$ip}";
        
        // Tentar usar Redis se disponível
        if (CACHE_DRIVER === 'redis' && class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redis->connect(REDIS_HOST, REDIS_PORT);
                if (REDIS_PASSWORD) {
                    $redis->auth(REDIS_PASSWORD);
                }
                
                $current = $redis->get($cacheKey);
                
                if ($current === false) {
                    // Primeira requisição
                    $redis->setex($cacheKey, $timeWindow, 1);
                    return true;
                } elseif ($current < $maxRequests) {
                    // Incrementar contador
                    $redis->incr($cacheKey);
                    return true;
                } else {
                    // Limite excedido
                    return false;
                }
            } catch (Exception $e) {
                error_log("Rate limit Redis error: " . $e->getMessage());
                // Fallback para DB
            }
        }
        
        // Fallback: usar banco de dados
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM visitors 
                    WHERE campaign_id = ? 
                    AND ip = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
            
            // CORREÇÃO 1: usar getConnection() ao invés de getPdo()
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$campaignId, $ip, $timeWindow]);
            
            // CORREÇÃO 2: fazer o fetch do resultado
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Retornar se está dentro do limite
            return ($result && $result['count'] < $maxRequests);
            
        } catch (Exception $e) {
            error_log("Rate limit DB error: " . $e->getMessage());
            // Em caso de erro, permitir (fail-open)
            return true;
        }
    }
    
    /**
     * Avaliar e decidir ação para o visitante
     * COM TODOS OS FILTROS IMPLEMENTADOS
     */
    private function evaluateDecision($campaign, $settings, $rules, $visitor) {
        $reasons = [];
        
        // 1. VERIFICAR WHITELIST (sempre permite)
        if ($this->isWhitelisted($campaign['id'], $visitor)) {
            return $this->createDecision('money', $campaign, 'whitelisted');
        }
        
        // 2. VERIFICAR BLACKLIST (sempre bloqueia)
        if ($this->isBlacklisted($campaign['id'], $visitor)) {
            return $this->createDecision('safe', $campaign, 'blacklisted');
        }
        
        // 3. VERIFICAR AGENDAMENTO DE HORÁRIOS
        if (!empty($rules['schedule_enabled'])) {
            if (!$this->isWithinSchedule($rules, $visitor)) {
                return $this->createDecision('safe', $campaign, 'outside_schedule');
            }
        }
        
        // 4. DETECÇÕES DE SEGURANÇA
        if ($this->failsSecurityChecks($settings, $visitor, $reasons)) {
            return $this->createDecision('safe', $campaign, 'security_' . implode('_', $reasons));
        }
        
        // 5. FILTROS GEOGRÁFICOS
        if ($this->failsGeoFilters($rules, $visitor, $reasons)) {
            return $this->createDecision('safe', $campaign, 'geo_' . implode('_', $reasons));
        }
        
        // 6. FILTROS DE DISPOSITIVO
        if ($this->failsDeviceFilters($rules, $visitor, $reasons)) {
            return $this->createDecision('safe', $campaign, 'device_' . implode('_', $reasons));
        }
        
        // 7. FILTROS DE COMPORTAMENTO
        if ($this->failsBehaviorFilters($rules, $visitor, $reasons)) {
            return $this->createDecision('safe', $campaign, 'behavior_' . implode('_', $reasons));
        }
        
        // 8. VERIFICAÇÕES AVANÇADAS (CORRIGIDO)
        if ($this->failsAdvancedChecks($settings, $visitor, $reasons)) {
            return $this->createDecision('safe', $campaign, 'advanced_' . implode('_', $reasons));
        }
        
        // 9. AVALIAR REGRAS CUSTOMIZADAS
        $ruleResult = $this->rules->evaluate($campaign['id'], $visitor);
        if ($ruleResult !== null) {
            return $this->createDecision(
                $ruleResult['action'],
                $campaign,
                'rule_' . $ruleResult['rule_id'],
                $ruleResult['rule_id']
            );
        }
        
        // 10. PASSOU POR TODOS OS FILTROS - DECIDIR COM A/B TESTING
        if (!empty($settings['enable_ab_testing'])) {
            $percentage = (int)($settings['ab_test_percentage'] ?? 50);
            $random = mt_rand(1, 100);
            
            if ($random > $percentage) {
                return $this->createDecision('safe', $campaign, 'ab_test_group_b');
            }
        }
        
        // VISITANTE QUALIFICADO - MONEY PAGE!
        return $this->createDecision('money', $campaign, 'qualified_visitor');
    }
    
    /**
     * Verificar se está dentro do horário agendado
     */
    private function isWithinSchedule($rules, $visitor) {
        $timezone = new DateTimeZone($rules['schedule_timezone'] ?? 'America/Sao_Paulo');
        $now = new DateTime('now', $timezone);
        
        // Verificar dia da semana
        if (!empty($rules['schedule_days'])) {
            $currentDay = $now->format('w'); // 0=domingo, 6=sábado
            if (!in_array($currentDay, $rules['schedule_days'])) {
                return false;
            }
        }
        
        // Verificar horário
        if (!empty($rules['schedule_start_time']) && !empty($rules['schedule_end_time'])) {
            $currentTime = $now->format('H:i');
            $startTime = $rules['schedule_start_time'];
            $endTime = $rules['schedule_end_time'];
            
            if ($currentTime < $startTime || $currentTime > $endTime) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Verificar detecções de segurança
     */
    private function failsSecurityChecks($settings, $visitor, &$reasons) {
        // Bot detection
        if (!empty($settings['detect_bots']) && $visitor['is_bot']) {
            $reasons[] = 'bot';
            return true;
        }
        
        // VPN detection
        if (!empty($settings['detect_vpn']) && $visitor['is_vpn']) {
            $reasons[] = 'vpn';
            return true;
        }
        
        // Proxy detection (separado de VPN)
        if (!empty($settings['detect_proxy']) && $visitor['is_proxy']) {
            $reasons[] = 'proxy';
            return true;
        }
        
        // TOR detection
        if (!empty($settings['detect_tor']) && $visitor['is_tor']) {
            $reasons[] = 'tor';
            return true;
        }
        
        // Datacenter IP detection
        if (!empty($settings['detect_datacenter']) && $visitor['ip_type'] === 'datacenter') {
            $reasons[] = 'datacenter';
            return true;
        }
        
        // Headless browser detection
        if (!empty($settings['detect_headless']) && $visitor['is_headless']) {
            $reasons[] = 'headless';
            return true;
        }
        
        // SCRAPER DETECTION
        if (!empty($settings['enable_scraper_detection']) && ($visitor['is_scraper'] ?? false)) {
            $reasons[] = 'scraper';
            return true;
        }
        
        return false;
    }
    
    /**
     * Verificar filtros geográficos
     */
    private function failsGeoFilters($rules, $visitor, &$reasons) {
        $country = strtoupper($visitor['country_code'] ?? '');
        $city = $visitor['city'] ?? '';
        $region = $visitor['region'] ?? '';
        $isp = strtolower($visitor['isp'] ?? '');
        
        // Países bloqueados (tem prioridade)
        if (!empty($rules['blocked_countries'])) {
            $blocked = array_map('strtoupper', array_map('trim', explode(',', $rules['blocked_countries'])));
            if (in_array($country, $blocked)) {
                $reasons[] = 'blocked_country';
                return true;
            }
        }
        
        // Países permitidos
        if (!empty($rules['allowed_countries'])) {
            $allowed = array_map('strtoupper', array_map('trim', explode(',', $rules['allowed_countries'])));
            if (!in_array($country, $allowed)) {
                $reasons[] = 'country_not_allowed';
                return true;
            }
        }
        
        // Cidades bloqueadas
        if (!empty($rules['blocked_cities'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_cities'])));
            if (in_array(strtolower($city), $blocked)) {
                $reasons[] = 'blocked_city';
                return true;
            }
        }
        
        // Cidades permitidas
        if (!empty($rules['allowed_cities'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_cities'])));
            if (!in_array(strtolower($city), $allowed)) {
                $reasons[] = 'city_not_allowed';
                return true;
            }
        }
        
        // Regiões permitidas
        if (!empty($rules['allowed_regions'])) {
            $allowed = array_map('strtoupper', array_map('trim', explode(',', $rules['allowed_regions'])));
            if (!in_array(strtoupper($region), $allowed)) {
                $reasons[] = 'region_not_allowed';
                return true;
            }
        }
        
        // Timezones permitidos
        if (!empty($rules['allowed_timezones'])) {
            $allowed = array_map('trim', explode(',', $rules['allowed_timezones']));
            if (!in_array($visitor['timezone'] ?? '', $allowed)) {
                $reasons[] = 'timezone_not_allowed';
                return true;
            }
        }
        
        // ISPs bloqueados (tem prioridade)
        if (!empty($rules['blocked_isps'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_isps'])));
            foreach ($blocked as $blockedIsp) {
                if (strpos($isp, $blockedIsp) !== false) {
                    $reasons[] = 'blocked_isp';
                    return true;
                }
            }
        }
        
        // ISPs permitidos
        if (!empty($rules['allowed_isps'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_isps'])));
            $found = false;
            foreach ($allowed as $allowedIsp) {
                if (strpos($isp, $allowedIsp) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $reasons[] = 'isp_not_allowed';
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar filtros de dispositivo
     */
    private function failsDeviceFilters($rules, $visitor, &$reasons) {
        $deviceType = strtolower($visitor['device_type'] ?? '');
        $os = strtolower($visitor['os'] ?? '');
        $browser = strtolower($visitor['browser'] ?? '');
        
        // Dispositivos permitidos
        if (!empty($rules['allowed_devices']) && is_array($rules['allowed_devices'])) {
            if (!in_array($deviceType, array_map('strtolower', $rules['allowed_devices']))) {
                $reasons[] = 'device_not_allowed';
                return true;
            }
        }
        
        // Sistemas operacionais permitidos
        if (!empty($rules['allowed_os']) && is_array($rules['allowed_os'])) {
            $found = false;
            foreach ($rules['allowed_os'] as $allowedOs) {
                if (strpos($os, strtolower($allowedOs)) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $reasons[] = 'os_not_allowed';
                return true;
            }
        }
        
        // Navegadores bloqueados (tem prioridade)
        if (!empty($rules['blocked_browsers'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_browsers'])));
            foreach ($blocked as $blockedBrowser) {
                if (strpos($browser, $blockedBrowser) !== false) {
                    $reasons[] = 'blocked_browser';
                    return true;
                }
            }
        }
        
        // Navegadores permitidos
        if (!empty($rules['allowed_browsers']) && is_array($rules['allowed_browsers'])) {
            $found = false;
            foreach ($rules['allowed_browsers'] as $allowedBrowser) {
                if (strpos($browser, strtolower($allowedBrowser)) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $reasons[] = 'browser_not_allowed';
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar filtros de comportamento
     */
    private function failsBehaviorFilters($rules, $visitor, &$reasons) {
        $language = strtolower($visitor['language'] ?? '');
        $referer = strtolower($visitor['referer'] ?? '');
        
        // Idiomas permitidos
        if (!empty($rules['allowed_languages'])) {
            $allowed = array_map('strtolower', array_map('trim', explode(',', $rules['allowed_languages'])));
            $found = false;
            foreach ($allowed as $allowedLang) {
                if (strpos($language, $allowedLang) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $reasons[] = 'language_not_allowed';
                return true;
            }
        }
        
        // Referrers bloqueados (tem prioridade)
        if (!empty($rules['blocked_referrers'])) {
            $blocked = array_map('strtolower', array_map('trim', explode(',', $rules['blocked_referrers'])));
            foreach ($blocked as $blockedRef) {
                if (strpos($referer, $blockedRef) !== false) {
                    $reasons[] = 'blocked_referrer';
                    return true;
                }
            }
        }
        
        // Referrer obrigatório
        if (!empty($rules['required_referrer'])) {
            if (empty($referer)) {
                $reasons[] = 'no_referrer';
                return true;
            }
            
            $required = array_map('strtolower', array_map('trim', explode(',', $rules['required_referrer'])));
            $found = false;
            foreach ($required as $requiredRef) {
                if (strpos($referer, $requiredRef) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $reasons[] = 'referrer_not_required';
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar configurações avançadas - CORRIGIDO
     */
    private function failsAdvancedChecks($settings, $visitor, &$reasons) {
        // Exigir JavaScript (CORRIGIDO - com fallback)
        if (!empty($settings['require_javascript'])) {
            // Se não tem has_javascript OU é false, bloquear
            if (!isset($visitor['has_javascript']) || !$visitor['has_javascript']) {
                $reasons[] = 'no_javascript';
                return true;
            }
        }
        
        // Exigir Cookies (CORRIGIDO - com fallback)
        if (!empty($settings['require_cookies'])) {
            // Se não tem has_cookies OU é false, bloquear
            if (!isset($visitor['has_cookies']) || !$visitor['has_cookies']) {
                $reasons[] = 'no_cookies';
                return true;
            }
        }
        
        // Verificar features do browser (CORRIGIDO - não quebra sem fingerprint)
        if (!empty($settings['check_browser_features'])) {
            // Se não tem browser_features (fingerprint não disponível), bloquear
            if (!isset($visitor['browser_features']) || empty($visitor['browser_features'])) {
                $reasons[] = 'no_fingerprint_features';
                return true;
            }
            
            // Verificar cada feature específica
            $requiredFeatures = ['canvas', 'webgl', 'audio'];
            foreach ($requiredFeatures as $feature) {
                if (empty($visitor['browser_features'][$feature])) {
                    $reasons[] = 'missing_' . $feature;
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Criar decisão padronizada
     */
    private function createDecision($action, $campaign, $reason, $ruleId = null) {
        $settings = json_decode($campaign['settings'] ?? '{}', true) ?: [];
        
        $decision = [
            'action' => $action,
            'url' => $this->getUrlForAction($campaign, $action),
            'reason' => $reason,
            'rule_id' => $ruleId,
            'pixels' => null,
            'delay' => 0
        ];
        
        // Adicionar pixels apenas se for money page
        if ($action === 'money') {
            $decision['pixels'] = $this->getPixels($campaign, $settings);
        }
        
        // Adicionar delay de redirecionamento
        if (!empty($settings['enable_redirect_delay'])) {
            $decision['delay'] = (int)($settings['redirect_delay'] ?? 0);
        }
        
        // Adicionar página de cloaking intermediária
        if (!empty($settings['enable_cloaking_page'])) {
            $decision['use_cloaking_page'] = true;
        }
        
        return $decision;
    }
    
    /**
     * Obter URL para a ação
     */
    private function getUrlForAction($campaign, $action) {
        switch ($action) {
            case 'money':
                return $campaign['money_page'];
            case 'safe':
                return $campaign['safe_page'];
            case 'block':
                return null;
            default:
                return $campaign['safe_page'];
        }
    }
    
    /**
     * Obter pixels de tracking
     */
    private function getPixels($campaign, $settings) {
        $pixels = [];
        
        // Facebook Pixel
        if (!empty($settings['facebook_pixel'])) {
            $pixels['facebook'] = $settings['facebook_pixel'];
        }
        
        // Google Analytics
        if (!empty($settings['google_analytics'])) {
            $pixels['google'] = $settings['google_analytics'];
        }
        
        // TikTok Pixel
        if (!empty($settings['tiktok_pixel'])) {
            $pixels['tiktok'] = $settings['tiktok_pixel'];
        }
        
        // Código customizado
        if (!empty($settings['custom_code'])) {
            $pixels['custom'] = $settings['custom_code'];
        }
        
        return !empty($pixels) ? $pixels : null;
    }
    
    /**
     * Verificar whitelist
     */
    private function isWhitelisted($campaignId, $visitor) {
        $ip = $visitor['ip'];
        $ipBinary = inet_pton($ip);
        
        $sql = "SELECT id FROM ip_lists 
                WHERE campaign_id = ? 
                AND type = 'whitelist' 
                AND active = 1 
                AND ? >= ip_start 
                AND ? <= ip_end";
        
        $stmt = $this->db->query($sql, [$campaignId, $ipBinary, $ipBinary]);
        
        return $stmt && $stmt->fetch();
    }
    
    /**
     * Verificar blacklist
     */
    private function isBlacklisted($campaignId, $visitor) {
        $ip = $visitor['ip'];
        $ipBinary = inet_pton($ip);
        
        $sql = "SELECT id FROM ip_lists 
                WHERE campaign_id = ? 
                AND type = 'blacklist' 
                AND active = 1 
                AND ? >= ip_start 
                AND ? <= ip_end";
        
        $stmt = $this->db->query($sql, [$campaignId, $ipBinary, $ipBinary]);
        
        return $stmt && $stmt->fetch();
    }
    
    /**
     * Registrar visitante no banco
     */
    private function logVisitor($visitor, $decision, $note = null) {
        $data = [
            'campaign_id' => $visitor['campaign_id'],
            'visitor_id' => $visitor['visitor_id'] ?? $visitor['fingerprint'],
            'session_id' => $visitor['session_id'] ?? session_id(),
            'ip' => $visitor['ip'],
            'ip_type' => $visitor['ip_type'] ?? 'unknown',
            'country_code' => $visitor['country_code'] ?? null,
            'country_name' => $visitor['country_name'] ?? null,
            'city' => $visitor['city'] ?? null,
            'region' => $visitor['region'] ?? null,
            'postal_code' => $visitor['postal_code'] ?? null,
            'latitude' => $visitor['latitude'] ?? null,
            'longitude' => $visitor['longitude'] ?? null,
            'timezone' => $visitor['timezone'] ?? null,
            'isp' => $visitor['isp'] ?? null,
            'organization' => $visitor['organization'] ?? null,
            'asn' => $visitor['asn'] ?? null,
            'user_agent' => $visitor['user_agent'] ?? null,
            'device_type' => $visitor['device_type'] ?? 'unknown',
            'device_brand' => $visitor['device_brand'] ?? null,
            'device_model' => $visitor['device_model'] ?? null,
            'os' => $visitor['os'] ?? null,
            'os_version' => $visitor['os_version'] ?? null,
            'browser' => $visitor['browser'] ?? null,
            'browser_version' => $visitor['browser_version'] ?? null,
            'browser_language' => $visitor['language'] ?? null,
            'referer' => $visitor['referer'] ?? null,
            'landing_page' => $_SERVER['REQUEST_URI'] ?? null,
            'query_string' => $_SERVER['QUERY_STRING'] ?? null,
            'decision' => $decision['action'] === 'block' ? 'blocked' : $decision['action'],
            'decision_reason' => $decision['reason'] ?? null,
            'decision_rule_id' => $decision['rule_id'] ?? null,
            'fingerprint' => $visitor['fingerprint'] ?? null,
            'is_bot' => $visitor['is_bot'] ? 1 : 0,
            'is_vpn' => $visitor['is_vpn'] ? 1 : 0,
            'is_proxy' => $visitor['is_proxy'] ? 1 : 0,
            'is_tor' => $visitor['is_tor'] ? 1 : 0,
            'is_headless' => $visitor['is_headless'] ? 1 : 0,
            'bot_probability' => $visitor['bot_probability'] ?? null,
            'response_time' => $decision['response_time'] ?? null
        ];
        
        try {
            $this->db->insert('visitors', $data);
        } catch (Exception $e) {
            error_log('Failed to log visitor: ' . $e->getMessage());
        }
    }
    
    /**
     * Gerar chave de cache
     */
    private function generateCacheKey($campaignId, $visitor) {
        return 'cloaker_' . $campaignId . '_' . md5(
            $visitor['ip'] . 
            $visitor['user_agent'] . 
            ($visitor['fingerprint'] ?? '') .
            ($visitor['referer'] ?? '')
        );
    }
    
    /**
     * Obter do cache
     */
    private function getFromCache($key) {
        if (CACHE_DRIVER === 'redis' && class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redis->connect(REDIS_HOST, REDIS_PORT);
                if (REDIS_PASSWORD) {
                    $redis->auth(REDIS_PASSWORD);
                }
                
                $data = $redis->get($key);
                if ($data !== false) {
                    return json_decode($data, true);
                }
            } catch (Exception $e) {
                // Fallback para arquivo
            }
        }
        
        // Cache em arquivo
        $file = CACHE_PATH . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = unserialize(file_get_contents($file));
            if ($data && $data['expires'] > time()) {
                return $data['value'];
            }
            unlink($file);
        }
        
        return false;
    }
    
    /**
     * Salvar no cache
     */
    private function saveToCache($key, $value) {
        if (CACHE_DRIVER === 'redis' && class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redis->connect(REDIS_HOST, REDIS_PORT);
                if (REDIS_PASSWORD) {
                    $redis->auth(REDIS_PASSWORD);
                }
                
                $redis->setex($key, CACHE_TTL, json_encode($value));
                return true;
            } catch (Exception $e) {
                // Fallback para arquivo
            }
        }
        
        // Cache em arquivo
        $file = CACHE_PATH . '/' . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expires' => time() + CACHE_TTL
        ];
        
        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }
    
    /**
     * Limpar cache expirado
     */
    public function clearExpiredCache() {
        if (CACHE_DRIVER === 'file') {
            $files = glob(CACHE_PATH . '/*.cache');
            foreach ($files as $file) {
                $data = unserialize(file_get_contents($file));
                if (!$data || $data['expires'] < time()) {
                    unlink($file);
                }
            }
        }
    }
}