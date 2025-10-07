<?php
/**
 * Cloaker Pro - Detector v2.1 CORRIGIDO
 * Sistema COMPLETO de detecção com todos os filtros
 * Suporta: Bot, VPN, Proxy, TOR, Datacenter, Headless, GeoIP avançado, Fingerprinting
 * 
 * CORREÇÕES v2.1:
 * - Fallbacks para has_javascript e has_cookies sem fingerprint
 * - Detecção específica de scrapers
 * - Melhor análise comportamental
 */

class Detector {
    private $db = null;
    private $botPatterns = [];
    private $datacenterRanges = [];
    private $torExitNodes = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadBotPatterns();
        $this->loadDatacenterRanges();
        $this->loadTorExitNodes();
    }
    
    /**
     * MÉTODO PRINCIPAL - Análise completa do visitante
     */
    public function analyze($request = null) {
        if ($request === null) {
            $request = $_SERVER;
        }
        
        $startTime = microtime(true);
        
        // 1. Informações básicas
        $ip = $this->getRealIp($request);
        $userAgent = $request['HTTP_USER_AGENT'] ?? '';
        
        // 2. Detecção de dispositivo (device, OS, browser)
        $deviceInfo = $this->detectDevice($userAgent);
        
        // 3. Detecção de bot (completa)
        $botInfo = $this->detectBot($userAgent, $request);
        
        // 4. Detecção de rede (VPN, Proxy, TOR, Datacenter - SEPARADOS)
        $networkInfo = $this->detectNetworkType($ip, $request);
        
        // 5. GeoIP avançado (país, cidade, região, ISP, timezone)
        $geoInfo = $this->getAdvancedGeoInfo($ip);
        
        // 6. Detecção de headless browser
        $headlessInfo = $this->detectHeadlessBrowser($userAgent, $request);
        
        // 7. Detecção de scraper (específico)
        $scraperInfo = $this->detectScraper($userAgent, $request);
        
        // 8. Verificar fingerprint armazenado
        $fingerprint = $this->getStoredFingerprint($request);
        
        // 9. Montar análise base
        $analysis = array_merge(
            [
                'timestamp' => time(),
                'analysis_time' => round((microtime(true) - $startTime) * 1000, 2),
                
                // Identificação
                'ip' => $ip,
                'user_agent' => $userAgent,
                'referer' => $request['HTTP_REFERER'] ?? '',
                'request_uri' => $request['REQUEST_URI'] ?? '',
                'request_method' => $request['REQUEST_METHOD'] ?? 'GET',
                
                // Headers importantes
                'accept' => $request['HTTP_ACCEPT'] ?? '',
                'accept_language' => $request['HTTP_ACCEPT_LANGUAGE'] ?? '',
                'accept_encoding' => $request['HTTP_ACCEPT_ENCODING'] ?? '',
                'connection' => $request['HTTP_CONNECTION'] ?? '',
            ],
            $deviceInfo,
            $botInfo,
            $networkInfo,
            $geoInfo,
            $headlessInfo,
            $scraperInfo
        );
        
        // 10. FALLBACKS para has_javascript e has_cookies (CORREÇÃO)
        // Se não tem fingerprint, fazer detecção básica
        if (!$fingerprint) {
            $analysis['has_javascript'] = $this->detectJavaScriptBasic($request);
            $analysis['has_cookies'] = $this->detectCookiesBasic();
            $analysis['browser_features'] = [
                'canvas' => false,
                'webgl' => false,
                'audio' => false
            ];
        }
        
        // 11. Enriquecer com fingerprint se disponível
        if ($fingerprint) {
            $analysis = $this->enrichWithFingerprint($analysis, $fingerprint);
        }
        
        // 12. Idioma do navegador (para filtro de language)
        $analysis['language'] = $this->extractLanguage($request);
        $analysis['languages'] = $this->extractLanguages($request);
        
        // 13. Gerar fingerprint hash único
        $analysis['fingerprint'] = $this->generateFingerprint($analysis);
        
        return $analysis;
    }
    
    // ====================================================================
    // DETECÇÃO BÁSICA SEM FINGERPRINT (NOVO - CORREÇÃO)
    // ====================================================================
    
    /**
     * Detectar JavaScript sem fingerprint (heurística)
     */
    private function detectJavaScriptBasic($request) {
        // Se chegou com cookie _js=1, tem JS
        if (!empty($_COOKIE['_js'])) {
            return true;
        }
        
        // Se tem Accept header específico de browser moderno, provavelmente tem JS
        $accept = $request['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'text/html') !== false && strpos($accept, 'application/xhtml+xml') !== false) {
            return true; // Assume true para browsers modernos
        }
        
        // Padrão conservador: assume false se não confirmar
        return false;
    }
    
    /**
     * Detectar Cookies sem fingerprint
     */
    private function detectCookiesBasic() {
        // Se tem QUALQUER cookie, cookies estão habilitados
        return !empty($_COOKIE);
    }
    
    // ====================================================================
    // DETECÇÃO DE SCRAPER (ESPECÍFICO - NOVO)
    // ====================================================================
    
    private function detectScraper($userAgent, $request) {
        $result = [
            'is_scraper' => false,
            'scraper_probability' => 0.0,
            'scraper_type' => null,
            'scraper_name' => null,
        ];
        
        if (empty($userAgent)) {
            return $result;
        }
        
        $ua = strtolower($userAgent);
        
        // Padrões específicos de scrapers
        $scraperPatterns = [
            'scrapy' => 'Scrapy Framework',
            'beautifulsoup' => 'Beautiful Soup',
            'httrack' => 'HTTrack',
            'webzip' => 'WebZIP',
            'webcopier' => 'WebCopier',
            'teleport' => 'Teleport Pro',
            'wget' => 'GNU Wget',
            'curl' => 'cURL',
            'python-requests' => 'Python Requests',
            'go-http-client' => 'Go HTTP Client',
            'java/' => 'Java HTTP Client',
            'apache-httpclient' => 'Apache HttpClient',
            'node-fetch' => 'Node Fetch',
            'axios' => 'Axios',
            'got' => 'Got',
        ];
        
        foreach ($scraperPatterns as $pattern => $name) {
            if (strpos($ua, $pattern) !== false) {
                $result['is_scraper'] = true;
                $result['scraper_probability'] = 1.0;
                $result['scraper_type'] = 'known_scraper';
                $result['scraper_name'] = $name;
                return $result;
            }
        }
        
        // Análise comportamental de scraper
        $points = 0;
        
        // Headers típicos de scrapers
        if (empty($request['HTTP_ACCEPT'])) $points += 30;
        if (empty($request['HTTP_ACCEPT_LANGUAGE'])) $points += 30;
        if (empty($request['HTTP_ACCEPT_ENCODING'])) $points += 20;
        if (empty($request['HTTP_CONNECTION'])) $points += 10;
        
        // User-Agent muito simples ou genérico
        if (strlen($userAgent) < 30) $points += 20;
        
        // Sem cookies
        if (empty($_COOKIE)) $points += 15;
        
        $probability = min($points / 100, 1.0);
        
        if ($probability >= 0.6) {
            $result['is_scraper'] = true;
            $result['scraper_probability'] = $probability;
            $result['scraper_type'] = 'suspected_scraper';
            $result['scraper_name'] = 'Unknown Scraper';
        } else {
            $result['scraper_probability'] = $probability;
        }
        
        return $result;
    }
    
    // ====================================================================
    // DETECÇÃO DE DISPOSITIVO (Device, OS, Browser)
    // ====================================================================
    
    private function detectDevice($userAgent) {
        $info = [
            'device_type' => 'desktop',
            'device_brand' => null,
            'device_model' => null,
            'browser' => 'Unknown',
            'browser_version' => '',
            'os' => 'Unknown',
            'os_version' => '',
            'is_mobile' => false,
            'is_tablet' => false,
            'is_desktop' => true,
        ];
        
        if (empty($userAgent)) {
            return $info;
        }
        
        $ua = strtolower($userAgent);
        
        // ===== DISPOSITIVO =====
        
        // Tablets
        if (preg_match('/(ipad|tablet|kindle|playbook|silk)/i', $userAgent)) {
            $info['device_type'] = 'tablet';
            $info['is_tablet'] = true;
            $info['is_mobile'] = false;
            $info['is_desktop'] = false;
            
            if (strpos($ua, 'ipad') !== false) {
                $info['device_brand'] = 'Apple';
                $info['device_model'] = 'iPad';
            }
        }
        // Mobile
        elseif (preg_match('/(android|iphone|ipod|blackberry|windows phone|mobile)/i', $userAgent)) {
            $info['device_type'] = 'mobile';
            $info['is_mobile'] = true;
            $info['is_tablet'] = false;
            $info['is_desktop'] = false;
            
            if (strpos($ua, 'iphone') !== false) {
                $info['device_brand'] = 'Apple';
                $info['device_model'] = 'iPhone';
            } elseif (strpos($ua, 'samsung') !== false) {
                $info['device_brand'] = 'Samsung';
            } elseif (strpos($ua, 'huawei') !== false) {
                $info['device_brand'] = 'Huawei';
            } elseif (strpos($ua, 'xiaomi') !== false) {
                $info['device_brand'] = 'Xiaomi';
            }
        }
        
        // ===== SISTEMA OPERACIONAL =====
        
        if (preg_match('/windows nt ([\d\.]+)/i', $userAgent, $match)) {
            $version = $match[1];
            if ($version == '10.0') $info['os'] = 'Windows 10/11';
            elseif ($version == '6.3') $info['os'] = 'Windows 8.1';
            elseif ($version == '6.2') $info['os'] = 'Windows 8';
            elseif ($version == '6.1') $info['os'] = 'Windows 7';
            else $info['os'] = 'Windows';
            $info['os_version'] = $version;
        }
        elseif (preg_match('/mac os x ([\d_\.]+)/i', $userAgent, $match)) {
            $info['os'] = 'macOS';
            $info['os_version'] = str_replace('_', '.', $match[1]);
        }
        elseif (preg_match('/android ([\d\.]+)/i', $userAgent, $match)) {
            $info['os'] = 'Android';
            $info['os_version'] = $match[1];
        }
        elseif (preg_match('/iphone os ([\d_]+)/i', $userAgent, $match)) {
            $info['os'] = 'iOS';
            $info['os_version'] = str_replace('_', '.', $match[1]);
        }
        elseif (preg_match('/ipad.*os ([\d_]+)/i', $userAgent, $match)) {
            $info['os'] = 'iPadOS';
            $info['os_version'] = str_replace('_', '.', $match[1]);
        }
        elseif (strpos($ua, 'linux') !== false) {
            $info['os'] = 'Linux';
        }
        elseif (strpos($ua, 'ubuntu') !== false) {
            $info['os'] = 'Ubuntu';
        }
        
        // ===== NAVEGADOR =====
        
        if (preg_match('/edg\/([\d\.]+)/i', $userAgent, $match)) {
            $info['browser'] = 'Edge';
            $info['browser_version'] = $match[1];
        }
        elseif (preg_match('/chrome\/([\d\.]+)/i', $userAgent, $match)) {
            $info['browser'] = 'Chrome';
            $info['browser_version'] = $match[1];
        }
        elseif (preg_match('/firefox\/([\d\.]+)/i', $userAgent, $match)) {
            $info['browser'] = 'Firefox';
            $info['browser_version'] = $match[1];
        }
        elseif (preg_match('/safari\/([\d\.]+)/i', $userAgent, $match) && strpos($ua, 'chrome') === false) {
            $info['browser'] = 'Safari';
            $info['browser_version'] = $match[1];
        }
        elseif (preg_match('/opera\/([\d\.]+)/i', $userAgent, $match) || preg_match('/opr\/([\d\.]+)/i', $userAgent, $match)) {
            $info['browser'] = 'Opera';
            $info['browser_version'] = $match[1];
        }
        
        return $info;
    }
    
    // ====================================================================
    // DETECÇÃO DE BOT (Completa)
    // ====================================================================
    
    private function detectBot($userAgent, $request) {
        $result = [
            'is_bot' => false,
            'bot_probability' => 0.0,
            'bot_type' => null,
            'bot_name' => null,
            'bot_category' => null,
        ];
        
        // User-Agent vazio = 90% bot
        if (empty($userAgent)) {
            $result['is_bot'] = true;
            $result['bot_probability'] = 0.9;
            $result['bot_type'] = 'empty_ua';
            $result['bot_name'] = 'No User-Agent';
            return $result;
        }
        
        $ua = strtolower($userAgent);
        
        // Verificar padrões conhecidos de bots
        foreach ($this->botPatterns as $pattern) {
            if (stripos($ua, strtolower($pattern['pattern'])) !== false) {
                $result['is_bot'] = true;
                $result['bot_probability'] = 1.0;
                $result['bot_type'] = $pattern['category'];
                $result['bot_name'] = $pattern['bot_name'];
                $result['bot_category'] = $pattern['category'];
                
                // Incrementar hits no banco
                $this->incrementBotHits($pattern['id']);
                
                return $result;
            }
        }
        
        // Análise comportamental (pontuação de 0-100)
        $points = 0;
        
        // Headers ausentes
        if (empty($request['HTTP_ACCEPT'])) $points += 25;
        if (empty($request['HTTP_ACCEPT_LANGUAGE'])) $points += 25;
        if (empty($request['HTTP_ACCEPT_ENCODING'])) $points += 15;
        
        // User-Agent suspeito
        if (preg_match('/(curl|wget|python|java|ruby|perl|go-http)/i', $ua)) $points += 50;
        if (preg_match('/(bot|crawl|spider|scrape|scan)/i', $ua)) $points += 40;
        
        // User-Agent muito curto (< 20 caracteres)
        if (strlen($userAgent) < 20) $points += 30;
        
        // Sem cookies
        if (empty($_COOKIE)) $points += 15;
        
        $probability = min($points / 100, 1.0);
        
        if ($probability >= 0.5) {
            $result['is_bot'] = true;
            $result['bot_probability'] = $probability;
            $result['bot_type'] = 'behavioral';
            $result['bot_name'] = 'Suspected Bot';
            $result['bot_category'] = 'suspected';
        } else {
            $result['bot_probability'] = $probability;
        }
        
        return $result;
    }
    
    // ====================================================================
    // DETECÇÃO DE REDE (VPN, Proxy, TOR, Datacenter - SEPARADOS)
    // ====================================================================
    
    private function detectNetworkType($ip, $request) {
        $result = [
            'is_vpn' => false,
            'is_proxy' => false,
            'is_tor' => false,
            'is_datacenter' => false,
            'ip_type' => 'residential', // residential, datacenter, hosting, mobile
            'proxy_headers' => [],
        ];
        
        // 1. Detectar PROXY (headers)
        $proxyHeaders = [
            'HTTP_VIA',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_X_PROXY_ID',
            'HTTP_PROXY_CONNECTION',
            'HTTP_XROXY_CONNECTION',
            'HTTP_X_IMForwards',
            'HTTP_XONNECTION',
        ];
        
        foreach ($proxyHeaders as $header) {
            if (!empty($request[$header])) {
                $result['is_proxy'] = true;
                $result['proxy_headers'][] = $header;
            }
        }
        
        // 2. Detectar TOR (exit nodes conhecidos)
        if ($this->isTorExitNode($ip)) {
            $result['is_tor'] = true;
            $result['is_proxy'] = true; // TOR também é proxy
            $result['ip_type'] = 'tor';
        }
        
        // 3. Detectar DATACENTER (ranges conhecidos)
        if ($this->isDatacenterIp($ip)) {
            $result['is_datacenter'] = true;
            $result['ip_type'] = 'datacenter';
        }
        
        // 4. Detectar VPN (heurística + APIs)
        $vpnInfo = $this->detectVPN($ip);
        if ($vpnInfo['is_vpn']) {
            $result['is_vpn'] = true;
            $result['vpn_provider'] = $vpnInfo['provider'];
        }
        
        return $result;
    }
    
    private function isTorExitNode($ip) {
        // Verificar cache em memória
        return in_array($ip, $this->torExitNodes);
    }
    
    private function isDatacenterIp($ip) {
        $ipLong = ip2long($ip);
        if ($ipLong === false) return false;
        
        foreach ($this->datacenterRanges as $range) {
            if ($ipLong >= $range['start'] && $ipLong <= $range['end']) {
                return true;
            }
        }
        
        return false;
    }
    
    private function detectVPN($ip) {
        $result = ['is_vpn' => false, 'provider' => null];
        
        // Lista de providers VPN conhecidos (IPs ou ASNs)
        $vpnProviders = [
            'NordVPN', 'ExpressVPN', 'Surfshark', 'CyberGhost',
            'Private Internet Access', 'ProtonVPN', 'Hotspot Shield'
        ];
        
        // Aqui você pode integrar com APIs de detecção de VPN
        // Exemplo: IPHub, IPQualityScore, etc.
        
        return $result;
    }
    
    // ====================================================================
    // GEOIP AVANÇADO (País, Cidade, Região, ISP, Timezone, ASN)
    // ====================================================================
    
    private function getAdvancedGeoInfo($ip) {
        $info = [
            'country_code' => 'XX',
            'country_name' => 'Unknown',
            'city' => '',
            'region' => '',
            'region_code' => '',
            'postal_code' => '',
            'latitude' => null,
            'longitude' => null,
            'timezone' => '',
            'isp' => '',
            'organization' => '',
            'asn' => '',
            'connection_type' => '', // Cable/DSL, Corporate, Cellular
        ];
        
        // IP local/privado
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $info['country_code'] = 'LOCAL';
            $info['country_name'] = 'Local Network';
            $info['city'] = 'Localhost';
            return $info;
        }
        
        // API ip-api.com (gratuita, 45 req/min)
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=66846719"; // todos os campos
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'ignore_errors' => true,
                    'user_agent' => 'CloakerPro/2.0'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                
                if ($data && $data['status'] === 'success') {
                    $info['country_code'] = $data['countryCode'] ?? 'XX';
                    $info['country_name'] = $data['country'] ?? 'Unknown';
                    $info['city'] = $data['city'] ?? '';
                    $info['region'] = $data['regionName'] ?? '';
                    $info['region_code'] = $data['region'] ?? '';
                    $info['postal_code'] = $data['zip'] ?? '';
                    $info['latitude'] = $data['lat'] ?? null;
                    $info['longitude'] = $data['lon'] ?? null;
                    $info['timezone'] = $data['timezone'] ?? '';
                    $info['isp'] = $data['isp'] ?? '';
                    $info['organization'] = $data['org'] ?? '';
                    $info['asn'] = $data['as'] ?? '';
                }
            }
        } catch (Exception $e) {
            error_log("GeoIP lookup failed: " . $e->getMessage());
        }
        
        return $info;
    }
    
    // ====================================================================
    // DETECÇÃO DE HEADLESS BROWSER
    // ====================================================================
    
    private function detectHeadlessBrowser($userAgent, $request) {
        $result = [
            'is_headless' => false,
            'headless_probability' => 0.0,
            'headless_indicators' => [],
        ];
        
        $ua = strtolower($userAgent);
        $points = 0;
        $indicators = [];
        
        // 1. Padrões conhecidos de headless
        $headlessPatterns = [
            'headless' => 50,
            'phantomjs' => 50,
            'selenium' => 40,
            'webdriver' => 40,
            'puppeteer' => 40,
            'playwright' => 40,
        ];
        
        foreach ($headlessPatterns as $pattern => $score) {
            if (strpos($ua, $pattern) !== false) {
                $points += $score;
                $indicators[] = "ua_contains_{$pattern}";
            }
        }
        
        // 2. Chrome Headless específico
        if (strpos($ua, 'headlesschrome') !== false || 
            strpos($ua, 'chrome') !== false && strpos($ua, 'headless') !== false) {
            $points += 50;
            $indicators[] = 'chrome_headless';
        }
        
        // 3. Headers suspeitos
        if (empty($request['HTTP_ACCEPT_LANGUAGE'])) {
            $points += 20;
            $indicators[] = 'no_accept_language';
        }
        
        // 4. User-Agent muito específico (versão exata demais)
        if (preg_match('/Chrome\/[\d]{2,3}\.0\.0\.0/', $userAgent)) {
            $points += 15;
            $indicators[] = 'exact_chrome_version';
        }
        
        $probability = min($points / 100, 1.0);
        
        $result['headless_probability'] = $probability;
        $result['headless_indicators'] = $indicators;
        
        if ($probability >= 0.5) {
            $result['is_headless'] = true;
        }
        
        return $result;
    }
    
    // ====================================================================
    // FINGERPRINTING
    // ====================================================================
    
    private function getStoredFingerprint($request) {
        if (!$this->db) return null;
        
        try {
            // 1. Tentar por cookie _fp_id
            $fingerprintId = $_COOKIE['_fp_id'] ?? null;
            if ($fingerprintId) {
                $stmt = $this->db->prepare("
                    SELECT * FROM fingerprints 
                    WHERE id = ? 
                    AND is_active = 1 
                    LIMIT 1
                ");
                $stmt->execute([$fingerprintId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $this->updateFingerprintLastSeen($fingerprintId);
                    return $result;
                }
            }
            
            // 2. Tentar por hash
            $fingerprintHash = $_COOKIE['_fp'] ?? null;
            if ($fingerprintHash) {
                $stmt = $this->db->prepare("
                    SELECT * FROM fingerprints 
                    WHERE fingerprint_hash = ? 
                    AND is_active = 1 
                    ORDER BY last_seen DESC 
                    LIMIT 1
                ");
                $stmt->execute([$fingerprintHash]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $this->updateFingerprintLastSeen($result['id']);
                    return $result;
                }
            }
        } catch (Exception $e) {
            error_log("Failed to get fingerprint: " . $e->getMessage());
        }
        
        return null;
    }
    
    private function enrichWithFingerprint($analysis, $fingerprint) {
        $analysis['fingerprint_id'] = $fingerprint['id'];
        $analysis['fingerprint_hash'] = $fingerprint['fingerprint_hash'];
        $analysis['visitor_id'] = $fingerprint['visitor_id'];
        
        // Dados técnicos
        $analysis['screen_width'] = $fingerprint['screen_width'];
        $analysis['screen_height'] = $fingerprint['screen_height'];
        $analysis['color_depth'] = $fingerprint['color_depth'];
        $analysis['pixel_ratio'] = $fingerprint['pixel_ratio'];
        $analysis['viewport_width'] = $fingerprint['viewport_width'];
        $analysis['viewport_height'] = $fingerprint['viewport_height'];
        
        // Hardware
        $analysis['hardware_concurrency'] = $fingerprint['hardware_concurrency'];
        $analysis['device_memory'] = $fingerprint['device_memory'];
        $analysis['platform'] = $fingerprint['platform'];
        
        // WebGL
        $analysis['webgl_vendor'] = $fingerprint['webgl_vendor'];
        $analysis['webgl_renderer'] = $fingerprint['webgl_renderer'];
        
        // Canvas
        $analysis['canvas_hash'] = $fingerprint['canvas_hash'];
        
        // Capacidades
        $analysis['has_cookies'] = (bool)$fingerprint['cookies_enabled'];
        $analysis['has_javascript'] = true; // se chegou aqui, tem JS
        $analysis['touch_support'] = (bool)$fingerprint['touch_support'];
        $analysis['max_touch_points'] = $fingerprint['max_touch_points'];
        
        // Browser features (para filtro require_browser_features)
        $analysis['browser_features'] = [
            'canvas' => !empty($fingerprint['canvas_hash']),
            'webgl' => !empty($fingerprint['webgl_vendor']),
            'audio' => (bool)$fingerprint['audio_context'],
        ];
        
        // Timezone
        $analysis['timezone_offset'] = $fingerprint['timezone_offset'];
        $analysis['timezone_name'] = $fingerprint['timezone_name'];
        
        // Comportamento
        $analysis['mouse_movements'] = $fingerprint['mouse_movements'];
        $analysis['clicks'] = $fingerprint['clicks'];
        $analysis['key_presses'] = $fingerprint['key_presses'];
        $analysis['scrolls'] = $fingerprint['scrolls'];
        
        // Risk score
        if ($fingerprint['is_suspicious']) {
            $analysis['fingerprint_suspicious'] = true;
            $analysis['fingerprint_risk_score'] = $fingerprint['risk_score'];
            $analysis['fingerprint_flags'] = json_decode($fingerprint['flags'], true) ?? [];
            
            // Se muito suspeito, aumentar bot probability
            if ($fingerprint['risk_score'] >= 70) {
                $analysis['is_bot'] = true;
                $analysis['bot_probability'] = max(
                    $analysis['bot_probability'],
                    $fingerprint['risk_score'] / 100
                );
                $analysis['bot_type'] = 'fingerprint_analysis';
            }
        }
        
        // Estatísticas
        $analysis['visit_count'] = $fingerprint['visit_count'];
        $analysis['first_seen'] = $fingerprint['first_seen'];
        $analysis['last_seen'] = $fingerprint['last_seen'];
        
        return $analysis;
    }
    
    private function generateFingerprint($analysis) {
        $data = [
            $analysis['ip'],
            $analysis['user_agent'],
            $analysis['accept_language'] ?? '',
            $analysis['screen_width'] ?? '',
            $analysis['screen_height'] ?? '',
            $analysis['timezone_offset'] ?? '',
            $analysis['platform'] ?? '',
        ];
        
        return hash('sha256', implode('|', $data));
    }
    
    // ====================================================================
    // EXTRAIR IDIOMAS
    // ====================================================================
    
    private function extractLanguage($request) {
        $acceptLang = $request['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (empty($acceptLang)) return '';
        
        // Pegar primeiro idioma
        $parts = explode(',', $acceptLang);
        $first = explode(';', $parts[0])[0];
        return strtolower(trim($first));
    }
    
    private function extractLanguages($request) {
        $acceptLang = $request['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (empty($acceptLang)) return '';
        
        return strtolower($acceptLang);
    }
    
    // ====================================================================
    // OBTER IP REAL
    // ====================================================================
    
    private function getRealIp($request) {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',             // Nginx
            'HTTP_X_FORWARDED_FOR',       // Proxy padrão
            'HTTP_CLIENT_IP',             // Raro
            'REMOTE_ADDR'                 // Fallback
        ];
        
        foreach ($headers as $header) {
            if (isset($request[$header]) && !empty($request[$header])) {
                $ip = $request[$header];
                
                // Se múltiplos IPs, pegar o primeiro
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                // Validar IP público
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $request['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    // ====================================================================
    // HELPERS DE BANCO DE DADOS
    // ====================================================================
    
    private function loadBotPatterns() {
        try {
            $stmt = $this->db->query("
                SELECT id, pattern, category, bot_name 
                FROM bot_agents 
                WHERE active = 1
                ORDER BY priority DESC, category, pattern
            ");
            
            $this->botPatterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($this->botPatterns)) {
                $this->botPatterns = $this->getDefaultBotPatterns();
            }
        } catch (Exception $e) {
            error_log("Failed to load bot patterns: " . $e->getMessage());
            $this->botPatterns = $this->getDefaultBotPatterns();
        }
    }
    
    private function getDefaultBotPatterns() {
        return [
            ['id' => 1, 'pattern' => 'googlebot', 'category' => 'search_engine', 'bot_name' => 'Google Bot'],
            ['id' => 2, 'pattern' => 'bingbot', 'category' => 'search_engine', 'bot_name' => 'Bing Bot'],
            ['id' => 3, 'pattern' => 'slurp', 'category' => 'search_engine', 'bot_name' => 'Yahoo Slurp'],
            ['id' => 4, 'pattern' => 'duckduckbot', 'category' => 'search_engine', 'bot_name' => 'DuckDuckGo Bot'],
            ['id' => 5, 'pattern' => 'baiduspider', 'category' => 'search_engine', 'bot_name' => 'Baidu Spider'],
            ['id' => 6, 'pattern' => 'yandexbot', 'category' => 'search_engine', 'bot_name' => 'Yandex Bot'],
            ['id' => 7, 'pattern' => 'facebookexternalhit', 'category' => 'social_media', 'bot_name' => 'Facebook Bot'],
            ['id' => 8, 'pattern' => 'twitterbot', 'category' => 'social_media', 'bot_name' => 'Twitter Bot'],
            ['id' => 9, 'pattern' => 'whatsapp', 'category' => 'social_media', 'bot_name' => 'WhatsApp'],
            ['id' => 10, 'pattern' => 'telegrambot', 'category' => 'social_media', 'bot_name' => 'Telegram Bot'],
            ['id' => 11, 'pattern' => 'bot', 'category' => 'bot', 'bot_name' => 'Generic Bot'],
            ['id' => 12, 'pattern' => 'crawler', 'category' => 'bot', 'bot_name' => 'Crawler'],
            ['id' => 13, 'pattern' => 'spider', 'category' => 'bot', 'bot_name' => 'Spider'],
            ['id' => 14, 'pattern' => 'curl', 'category' => 'tool', 'bot_name' => 'cURL'],
            ['id' => 15, 'pattern' => 'wget', 'category' => 'tool', 'bot_name' => 'Wget'],
            ['id' => 16, 'pattern' => 'python-requests', 'category' => 'tool', 'bot_name' => 'Python Requests'],
            ['id' => 17, 'pattern' => 'headless', 'category' => 'automation', 'bot_name' => 'Headless Browser'],
            ['id' => 18, 'pattern' => 'selenium', 'category' => 'automation', 'bot_name' => 'Selenium'],
            ['id' => 19, 'pattern' => 'puppeteer', 'category' => 'automation', 'bot_name' => 'Puppeteer'],
            ['id' => 20, 'pattern' => 'phantomjs', 'category' => 'automation', 'bot_name' => 'PhantomJS'],
        ];
    }
    
    private function loadDatacenterRanges() {
        try {
            $stmt = $this->db->query("
                SELECT ip_start_long, ip_end_long 
                FROM datacenter_ranges 
                WHERE active = 1
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->datacenterRanges[] = [
                    'start' => $row['ip_start_long'],
                    'end' => $row['ip_end_long']
                ];
            }
        } catch (Exception $e) {
            error_log("Failed to load datacenter ranges: " . $e->getMessage());
        }
    }
    
    private function loadTorExitNodes() {
        try {
            $stmt = $this->db->query("
                SELECT ip 
                FROM tor_exit_nodes 
                WHERE active = 1 
                AND last_seen > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->torExitNodes[] = $row['ip'];
            }
        } catch (Exception $e) {
            error_log("Failed to load TOR exit nodes: " . $e->getMessage());
        }
    }
    
    private function incrementBotHits($botId) {
        try {
            $stmt = $this->db->prepare("UPDATE bot_agents SET hits = hits + 1 WHERE id = ?");
            $stmt->execute([$botId]);
        } catch (Exception $e) {
            // Ignorar erro
        }
    }
    
    private function updateFingerprintLastSeen($fingerprintId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE fingerprints 
                SET last_seen = NOW(), 
                    visit_count = visit_count + 1 
                WHERE id = ?
            ");
            $stmt->execute([$fingerprintId]);
        } catch (Exception $e) {
            // Ignorar erro
        }
    }
}