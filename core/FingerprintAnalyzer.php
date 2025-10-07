<?php
/**
 * Analisador de Fingerprint
 * Analisa dados do fingerprint para detectar anomalias e bots
 */

class FingerprintAnalyzer {
    
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Analisar fingerprint completo
     */
    public function analyze($data, $ip) {
        $flags = [];
        $riskScore = 0;
        
        // Verificações básicas
        $checks = [
            'checkBasicConsistency' => 1.0,
            'checkUserAgent' => 1.2,
            'checkScreenResolution' => 0.8,
            'checkLanguage' => 0.6,
            'checkTimezone' => 0.5,
            'checkHardware' => 0.7,
            'checkWebGL' => 1.0,
            'checkPlugins' => 0.6,
            'checkBehavior' => 1.5,
            'checkDataConsistency' => 1.0,
            'checkKnownFingerprint' => 1.3
        ];
        
        foreach ($checks as $method => $weight) {
            if (method_exists($this, $method)) {
                $result = $this->$method($data['data'] ?? [], $ip);
                $riskScore += ($result['score'] * $weight);
                $flags = array_merge($flags, $result['flags']);
            }
        }
        
        return [
            'risk_score' => min(round($riskScore), 100),
            'is_suspicious' => $riskScore >= 50,
            'is_bot' => $riskScore >= 70,
            'flags' => array_unique($flags)
        ];
    }
    
    /**
     * Verificar consistência básica
     */
    private function checkBasicConsistency($data, $ip) {
        $score = 0;
        $flags = [];
        
        if (empty($data['userAgent'])) {
            $score += 30;
            $flags[] = 'no_user_agent';
        }
        
        if (isset($data['cookies']) && !$data['cookies']) {
            $score += 10;
            $flags[] = 'cookies_disabled';
        }
        
        $requiredFields = ['screen', 'viewport', 'timezone', 'language'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $score += 15;
                $flags[] = "missing_{$field}";
            }
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar User-Agent
     */
    private function checkUserAgent($data, $ip) {
        $score = 0;
        $flags = [];
        
        $userAgent = $data['userAgent'] ?? '';
        
        if (empty($userAgent)) {
            return ['score' => 50, 'flags' => ['empty_ua']];
        }
        
        $ua = strtolower($userAgent);
        
        // Padrões de bots
        $botPatterns = ['bot', 'crawl', 'spider', 'scrape', 'curl', 'wget', 'python', 'java', 'phantom', 'selenium', 'headless', 'puppeteer'];
        
        foreach ($botPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                $score += 40;
                $flags[] = "bot_pattern_{$pattern}";
                break;
            }
        }
        
        if (strlen($userAgent) < 50) {
            $score += 20;
            $flags[] = 'ua_too_short';
        }
        
        if (strlen($userAgent) > 500) {
            $score += 15;
            $flags[] = 'ua_too_long';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar resolução de tela
     */
    private function checkScreenResolution($data, $ip) {
        $score = 0;
        $flags = [];
        
        $screen = $data['screen'] ?? [];
        if (empty($screen)) {
            return ['score' => 20, 'flags' => ['no_screen_data']];
        }
        
        $width = $screen['width'] ?? 0;
        $height = $screen['height'] ?? 0;
        
        // Resoluções suspeitas
        if ($width < 800 || $height < 600) {
            $score += 15;
            $flags[] = 'suspicious_low_resolution';
        }
        
        if ($width > 5000 || $height > 5000) {
            $score += 10;
            $flags[] = 'suspicious_high_resolution';
        }
        
        // Viewport igual à tela (headless)
        $viewport = $data['viewport'] ?? [];
        if (!empty($viewport)) {
            if ($viewport['width'] == $width && $viewport['height'] == $height) {
                $score += 10;
                $flags[] = 'viewport_equals_screen';
            }
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar idioma
     */
    private function checkLanguage($data, $ip) {
        $score = 0;
        $flags = [];
        
        $language = $data['language'] ?? '';
        $languages = $data['languages'] ?? '';
        
        if (empty($language)) {
            $score += 15;
            $flags[] = 'no_language';
        }
        
        if (!empty($language) && empty($languages)) {
            $score += 5;
            $flags[] = 'single_language';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar timezone
     */
    private function checkTimezone($data, $ip) {
        $score = 0;
        $flags = [];
        
        $timezone = $data['timezone'] ?? [];
        if (empty($timezone)) {
            return ['score' => 10, 'flags' => ['no_timezone']];
        }
        
        $offset = $timezone['offset'] ?? 0;
        if ($offset % 15 != 0) {
            $score += 5;
            $flags[] = 'unusual_timezone_offset';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar hardware
     */
    private function checkHardware($data, $ip) {
        $score = 0;
        $flags = [];
        
        $cores = $data['hardwareConcurrency'] ?? 0;
        $memory = $data['deviceMemory'] ?? 0;
        
        if ($cores == 1 || $cores == 2) {
            $score += 10;
            $flags[] = 'low_cpu_cores';
        }
        
        if ($cores > 64) {
            $score += 15;
            $flags[] = 'high_cpu_cores';
        }
        
        if ($memory > 0 && $memory < 2) {
            $score += 10;
            $flags[] = 'low_memory';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar WebGL
     */
    private function checkWebGL($data, $ip) {
        $score = 0;
        $flags = [];
        
        $webgl = $data['webgl'] ?? [];
        
        if (empty($webgl) || $webgl === 'not_supported') {
            $score += 15;
            $flags[] = 'no_webgl';
            return ['score' => $score, 'flags' => $flags];
        }
        
        $vendor = strtolower($webgl['vendor'] ?? '');
        $renderer = strtolower($webgl['renderer'] ?? '');
        
        if (strpos($vendor, 'mesa') !== false || strpos($renderer, 'swiftshader') !== false) {
            $score += 20;
            $flags[] = 'generic_webgl';
        }
        
        if (strpos($renderer, 'llvmpipe') !== false) {
            $score += 25;
            $flags[] = 'software_renderer';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar plugins
     */
    private function checkPlugins($data, $ip) {
        $score = 0;
        $flags = [];
        
        $plugins = $data['plugins'] ?? '';
        
        if (empty($plugins)) {
            $score += 15;
            $flags[] = 'no_plugins';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar comportamento
     */
    private function checkBehavior($data, $ip) {
        $score = 0;
        $flags = [];
        
        $behavior = $data['behavior'] ?? [];
        $mouseMovements = $behavior['mouseMovements'] ?? 0;
        $clicks = $behavior['clicks'] ?? 0;
        $keyPresses = $behavior['keyPresses'] ?? 0;
        $scrolls = $behavior['scrolls'] ?? 0;
        
        if ($mouseMovements == 0 && $clicks == 0 && $keyPresses == 0 && $scrolls == 0) {
            $score += 30;
            $flags[] = 'no_interaction';
        }
        
        if ($clicks > 0 && $mouseMovements == 0) {
            $score += 25;
            $flags[] = 'clicks_without_movement';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar consistência entre dados
     */
    private function checkDataConsistency($data, $ip) {
        $score = 0;
        $flags = [];
        
        $ua = strtolower($data['userAgent'] ?? '');
        $isMobileUA = strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false;
        $touchPoints = $data['touchSupport']['maxTouchPoints'] ?? 0;
        
        if ($isMobileUA && $touchPoints == 0) {
            $score += 20;
            $flags[] = 'mobile_without_touch';
        }
        
        if (!$isMobileUA && $touchPoints > 5) {
            $score += 10;
            $flags[] = 'desktop_with_multi_touch';
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
    
    /**
     * Verificar fingerprint conhecido
     */
    private function checkKnownFingerprint($data, $ip) {
        $score = 0;
        $flags = [];
        
        $fingerprint = $data['fingerprint'] ?? null;
        if (empty($fingerprint)) {
            return ['score' => $score, 'flags' => $flags];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT ip_address) as ip_count, SUM(visit_count) as total_visits
                FROM fingerprints 
                WHERE fingerprint_hash = ?
            ");
            $stmt->execute([$fingerprint]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $ipCount = $result['ip_count'];
                $totalVisits = $result['total_visits'];
                
                if ($ipCount > 10) {
                    $score += 30;
                    $flags[] = 'fingerprint_multiple_ips';
                }
                
                if ($totalVisits > 100) {
                    $score += 15;
                    $flags[] = 'high_visit_count';
                }
            }
        } catch (Exception $e) {
            // Ignorar erro
        }
        
        return ['score' => $score, 'flags' => $flags];
    }
}
?>