<?php
/**
 * Cloaker Pro - GeoIP System
 * Sistema de geolocalização e detecção de localização
 */

class GeoIP {
    private $db;
    private $mmdbPath;
    private $ipApiKey;
    private $cache = [];
    private $cacheExpiry = 86400; // 24 horas
    
    // Listas de IPs conhecidos
    private $vpnRanges = [];
    private $datacenterRanges = [];
    private $botIPs = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->mmdbPath = dirname(__DIR__) . '/data/GeoLite2-City.mmdb';
        $this->ipApiKey = defined('IPAPI_KEY') ? IPAPI_KEY : null;
        $this->loadIPLists();
    }
    
    /**
     * Obter informações de geolocalização
     */
    public function lookup($ip) {
        // Verificar cache em memória
        if (isset($this->cache[$ip])) {
            return $this->cache[$ip];
        }
        
        // Verificar cache no banco
        $cached = $this->getCachedData($ip);
        if ($cached) {
            $this->cache[$ip] = $cached;
            return $cached;
        }
        
        // Tentar diferentes métodos de lookup
        $data = null;
        
        // 1. Tentar MaxMind MMDB
        if (file_exists($this->mmdbPath)) {
            $data = $this->lookupMaxMind($ip);
        }
        
        // 2. Fallback para API online
        if (!$data || $data['country_code'] === 'XX') {
            $data = $this->lookupOnlineAPI($ip);
        }
        
        // 3. Fallback para IP-API gratuito
        if (!$data || $data['country_code'] === 'XX') {
            $data = $this->lookupIPAPI($ip);
        }
        
        // Adicionar informações extras
        $data['is_vpn'] = $this->isVPN($ip);
        $data['is_datacenter'] = $this->isDatacenter($ip);
        $data['is_tor'] = $this->isTor($ip);
        $data['is_proxy'] = $this->isProxy($ip);
        $data['risk_score'] = $this->calculateRiskScore($data);
        
        // Salvar no cache
        $this->saveToCach($ip, $data);
        $this->cache[$ip] = $data;
        
        return $data;
    }
    
    /**
     * Lookup usando MaxMind MMDB
     */
    private function lookupMaxMind($ip) {
        try {
            if (!class_exists('GeoIp2\Database\Reader')) {
                return $this->getDefaultData($ip);
            }
            
            $reader = new \GeoIp2\Database\Reader($this->mmdbPath);
            $record = $reader->city($ip);
            
            return [
                'ip' => $ip,
                'country_code' => $record->country->isoCode ?? 'XX',
                'country_name' => $record->country->name ?? 'Unknown',
                'region' => $record->mostSpecificSubdivision->name ?? '',
                'city' => $record->city->name ?? '',
                'postal_code' => $record->postal->code ?? '',
                'latitude' => $record->location->latitude ?? 0,
                'longitude' => $record->location->longitude ?? 0,
                'timezone' => $record->location->timeZone ?? '',
                'isp' => $record->traits->isp ?? '',
                'organization' => $record->traits->organization ?? '',
                'connection_type' => $record->traits->connectionType ?? '',
                'user_type' => $record->traits->userType ?? '',
                'source' => 'maxmind'
            ];
        } catch (Exception $e) {
            return $this->getDefaultData($ip);
        }
    }
    
    /**
     * Lookup usando API online paga
     */
    private function lookupOnlineAPI($ip) {
        if (!$this->ipApiKey) {
            return null;
        }
        
        try {
            $url = "https://api.ipdata.co/{$ip}?api-key={$this->ipApiKey}";
            $response = $this->makeRequest($url);
            
            if ($response && isset($response['country_code'])) {
                return [
                    'ip' => $ip,
                    'country_code' => $response['country_code'] ?? 'XX',
                    'country_name' => $response['country_name'] ?? 'Unknown',
                    'region' => $response['region'] ?? '',
                    'city' => $response['city'] ?? '',
                    'postal_code' => $response['postal'] ?? '',
                    'latitude' => $response['latitude'] ?? 0,
                    'longitude' => $response['longitude'] ?? 0,
                    'timezone' => $response['time_zone']['name'] ?? '',
                    'isp' => $response['asn']['name'] ?? '',
                    'organization' => $response['asn']['domain'] ?? '',
                    'connection_type' => $response['threat']['is_datacenter'] ? 'Corporate' : '',
                    'user_type' => $response['threat']['is_vpn'] ? 'vpn' : 'regular',
                    'source' => 'ipdata'
                ];
            }
        } catch (Exception $e) {
            // Log error
        }
        
        return null;
    }
    
    /**
     * Lookup usando IP-API gratuito
     */
    private function lookupIPAPI($ip) {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,proxy,hosting";
            $response = $this->makeRequest($url);
            
            if ($response && $response['status'] === 'success') {
                return [
                    'ip' => $ip,
                    'country_code' => $response['countryCode'] ?? 'XX',
                    'country_name' => $response['country'] ?? 'Unknown',
                    'region' => $response['regionName'] ?? '',
                    'city' => $response['city'] ?? '',
                    'postal_code' => $response['zip'] ?? '',
                    'latitude' => $response['lat'] ?? 0,
                    'longitude' => $response['lon'] ?? 0,
                    'timezone' => $response['timezone'] ?? '',
                    'isp' => $response['isp'] ?? '',
                    'organization' => $response['org'] ?? '',
                    'connection_type' => $response['hosting'] ? 'hosting' : '',
                    'user_type' => $response['proxy'] ? 'proxy' : 'regular',
                    'source' => 'ip-api'
                ];
            }
        } catch (Exception $e) {
            // Log error
        }
        
        return $this->getDefaultData($ip);
    }
    
    /**
     * Verificar se é VPN
     */
    public function isVPN($ip) {
        // Verificar lista local de VPNs conhecidas
        foreach ($this->vpnRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        
        // Verificar por ASN conhecidos de VPN
        $vpnASNs = [
            'AS13335', // Cloudflare
            'AS9009',  // M247
            'AS20473', // Choopa
            'AS36351', // SoftLayer
            'AS16509', // Amazon AWS
            'AS15169', // Google
            // Adicionar mais ASNs de VPN conhecidos
        ];
        
        $geoData = $this->lookup($ip);
        if (isset($geoData['asn']) && in_array($geoData['asn'], $vpnASNs)) {
            return true;
        }
        
        // Verificar por padrões de hostname reverso
        $hostname = gethostbyaddr($ip);
        $vpnPatterns = [
            'vpn', 'proxy', 'tor', 'exit', 'node',
            'relay', 'anonymizer', 'anonymous'
        ];
        
        foreach ($vpnPatterns as $pattern) {
            if (stripos($hostname, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar se é datacenter
     */
    public function isDatacenter($ip) {
        // Lista de ranges de datacenters conhecidos
        $datacenterRanges = [
            '104.16.0.0/12',    // Cloudflare
            '172.64.0.0/13',    // Cloudflare
            '173.245.48.0/20',  // Cloudflare
            '103.21.244.0/22',  // Cloudflare
            '103.22.200.0/22',  // Cloudflare
            '103.31.4.0/22',    // Cloudflare
            '141.101.64.0/18',  // Cloudflare
            '108.162.192.0/18', // Cloudflare
            '190.93.240.0/20',  // Cloudflare
            '188.114.96.0/20',  // Cloudflare
            '197.234.240.0/22', // Cloudflare
            '198.41.128.0/17',  // Cloudflare
            '35.0.0.0/8',       // Amazon AWS
            '52.0.0.0/8',       // Amazon AWS
            '54.0.0.0/8',       // Amazon AWS
            '34.64.0.0/10',     // Google Cloud
            '35.184.0.0/13',    // Google Cloud
            '104.196.0.0/14',   // Google Cloud
            '20.0.0.0/8',       // Microsoft Azure
            '40.0.0.0/8',       // Microsoft Azure
            '13.64.0.0/11',     // Microsoft Azure
            '13.96.0.0/13',     // Microsoft Azure
            '13.104.0.0/14',    // Microsoft Azure
            '159.65.0.0/16',    // DigitalOcean
            '159.89.0.0/16',    // DigitalOcean
            '159.203.0.0/16',   // DigitalOcean
            '138.68.0.0/16',    // DigitalOcean
            '178.128.0.0/16',   // DigitalOcean
        ];
        
        foreach ($datacenterRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Verificar se é Tor
     */
    public function isTor($ip) {
        // Lista de exit nodes do Tor (atualizar periodicamente)
        $torExitNodes = $this->getTorExitNodes();
        
        if (in_array($ip, $torExitNodes)) {
            return true;
        }
        
        // Verificar via Tor Project API
        try {
            $url = "https://check.torproject.org/cgi-bin/TorBulkExitList.py?ip=" . $_SERVER['SERVER_ADDR'];
            $exitNodes = file_get_contents($url);
            if (strpos($exitNodes, $ip) !== false) {
                return true;
            }
        } catch (Exception $e) {
            // Silent fail
        }
        
        return false;
    }
    
    /**
     * Verificar se é proxy
     */
    public function isProxy($ip) {
        // Headers que indicam proxy
        $proxyHeaders = [
            'HTTP_VIA',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'HTTP_FORWARDED_FOR_IP',
            'VIA',
            'X_FORWARDED_FOR',
            'FORWARDED_FOR',
            'X_FORWARDED',
            'FORWARDED',
            'CLIENT_IP',
            'FORWARDED_FOR_IP',
            'HTTP_PROXY_CONNECTION'
        ];
        
        foreach ($proxyHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                return true;
            }
        }
        
        // Verificar portas comuns de proxy
        $proxyPorts = [80, 8080, 3128, 8888, 1080, 9050, 9051];
        $port = $this->checkOpenPorts($ip, $proxyPorts);
        if ($port) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Calcular score de risco
     */
    public function calculateRiskScore($data) {
        $score = 0;
        
        // Fatores de risco
        if ($data['is_vpn']) $score += 30;
        if ($data['is_datacenter']) $score += 25;
        if ($data['is_tor']) $score += 40;
        if ($data['is_proxy']) $score += 20;
        
        // País de alto risco
        $highRiskCountries = ['CN', 'RU', 'IN', 'NG', 'PK', 'BD', 'VN'];
        if (in_array($data['country_code'], $highRiskCountries)) {
            $score += 15;
        }
        
        // Tipo de conexão
        if (isset($data['connection_type'])) {
            if ($data['connection_type'] === 'Corporate') $score += 10;
            if ($data['connection_type'] === 'hosting') $score += 20;
        }
        
        return min($score, 100); // Máximo 100
    }
    
    /**
     * Obter distância entre duas coordenadas
     */
    public function getDistance($lat1, $lon1, $lat2, $lon2, $unit = 'km') {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + 
                cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        
        if ($unit == 'km') {
            return ($miles * 1.609344);
        } else if ($unit == 'mi') {
            return $miles;
        }
        
        return $dist;
    }
    
    /**
     * Verificar se IP está em range CIDR
     */
    private function ipInRange($ip, $cidr) {
        list($subnet, $bits) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        
        return ($ip & $mask) == $subnet;
    }
    
    /**
     * Cache de dados
     */
    private function getCachedData($ip) {
        $stmt = $this->db->prepare("
            SELECT data 
            FROM geoip_cache 
            WHERE ip = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, $this->cacheExpiry]);
        
        $result = $stmt->fetch();
        if ($result) {
            return json_decode($result['data'], true);
        }
        
        return null;
    }
    
    private function saveToCache($ip, $data) {
        $stmt = $this->db->prepare("
            INSERT INTO geoip_cache (ip, data, created_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                data = VALUES(data),
                created_at = NOW()
        ");
        $stmt->execute([$ip, json_encode($data)]);
    }
    
    /**
     * Fazer requisição HTTP
     */
    private function makeRequest($url) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'user_agent' => 'Cloaker Pro GeoIP/1.0'
            ]
        ];
        
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            return json_decode($response, true);
        }
        
        return null;
    }
    
    /**
     * Obter dados padrão
     */
    private function getDefaultData($ip) {
        return [
            'ip' => $ip,
            'country_code' => 'XX',
            'country_name' => 'Unknown',
            'region' => '',
            'city' => '',
            'postal_code' => '',
            'latitude' => 0,
            'longitude' => 0,
            'timezone' => '',
            'isp' => '',
            'organization' => '',
            'connection_type' => '',
            'user_type' => 'unknown',
            'source' => 'default'
        ];
    }
    
    /**
     * Carregar listas de IPs
     */
    private function loadIPLists() {
        // Carregar listas de VPN conhecidas
        $vpnFile = dirname(__DIR__) . '/data/vpn-ranges.txt';
        if (file_exists($vpnFile)) {
            $this->vpnRanges = file($vpnFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
        
        // Carregar listas de datacenters
        $dcFile = dirname(__DIR__) . '/data/datacenter-ranges.txt';
        if (file_exists($dcFile)) {
            $this->datacenterRanges = file($dcFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
    }
    
    /**
     * Obter exit nodes do Tor
     */
    private function getTorExitNodes() {
        $cacheFile = dirname(__DIR__) . '/storage/cache/tor-exit-nodes.json';
        
        // Verificar cache
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data['expires'] > time()) {
                return $data['nodes'];
            }
        }
        
        // Baixar lista atualizada
        try {
            $url = "https://check.torproject.org/exit-addresses";
            $content = file_get_contents($url);
            
            preg_match_all('/ExitAddress\s+(\d+\.\d+\.\d+\.\d+)/', $content, $matches);
            $nodes = array_unique($matches[1]);
            
            // Salvar no cache
            $cacheData = [
                'nodes' => $nodes,
                'expires' => time() + 3600 // 1 hora
            ];
            file_put_contents($cacheFile, json_encode($cacheData));
            
            return $nodes;
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Verificar portas abertas
     */
    private function checkOpenPorts($ip, $ports) {
        foreach ($ports as $port) {
            $connection = @fsockopen($ip, $port, $errno, $errstr, 0.5);
            if ($connection) {
                fclose($connection);
                return $port;
            }
        }
        return false;
    }
    
    /**
     * Atualizar base GeoIP
     */
    public function updateDatabase() {
        // URL do MaxMind GeoLite2
        $url = "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=" . MAXMIND_LICENSE_KEY . "&suffix=tar.gz";
        
        $tempFile = sys_get_temp_dir() . '/GeoLite2-City.tar.gz';
        $tempDir = sys_get_temp_dir() . '/geolite2_extract';
        
        // Download arquivo
        $ch = curl_init($url);
        $fp = fopen($tempFile, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        
        // Extrair arquivo
        exec("tar -xzf $tempFile -C $tempDir");
        
        // Mover arquivo MMDB
        $mmdbFile = glob($tempDir . '/*/GeoLite2-City.mmdb')[0];
        if ($mmdbFile) {
            rename($mmdbFile, $this->mmdbPath);
        }
        
        // Limpar arquivos temporários
        unlink($tempFile);
        exec("rm -rf $tempDir");
        
        return file_exists($this->mmdbPath);
    }
    
    /**
     * Estatísticas de uso
     */
    public function getStats() {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_lookups,
                COUNT(DISTINCT ip) as unique_ips,
                AVG(JSON_EXTRACT(data, '$.risk_score')) as avg_risk_score,
                SUM(JSON_EXTRACT(data, '$.is_vpn')) as vpn_count,
                SUM(JSON_EXTRACT(data, '$.is_tor')) as tor_count,
                SUM(JSON_EXTRACT(data, '$.is_proxy')) as proxy_count
            FROM geoip_cache
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>