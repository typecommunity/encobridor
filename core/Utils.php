<?php
/**
 * Cloaker Pro - Utils
 * Funções auxiliares e utilitárias
 */

class Utils {
    
    /**
     * Gerar string aleatória
     */
    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Gerar UUID v4
     */
    public static function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Sanitizar entrada de dados
     */
    public static function sanitize($input, $type = 'string') {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
                
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
                
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
                
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                
            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
                
            case 'sql':
                return addslashes($input);
                
            case 'filename':
                return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $input);
                
            case 'alphanumeric':
                return preg_replace('/[^a-zA-Z0-9]/', '', $input);
                
            case 'string':
            default:
                return trim(strip_tags($input));
        }
    }
    
    /**
     * Validar entrada de dados
     */
    public static function validate($input, $type = 'string', $options = []) {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
                
            case 'url':
                return filter_var($input, FILTER_VALIDATE_URL) !== false;
                
            case 'ip':
                return filter_var($input, FILTER_VALIDATE_IP) !== false;
                
            case 'domain':
                return preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/i', $input);
                
            case 'phone':
                return preg_match('/^[\+]?[(]?[0-9]{1,3}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{1,9}$/', $input);
                
            case 'date':
                $format = $options['format'] ?? 'Y-m-d';
                $d = DateTime::createFromFormat($format, $input);
                return $d && $d->format($format) === $input;
                
            case 'regex':
                $pattern = $options['pattern'] ?? '/.*/';
                return preg_match($pattern, $input);
                
            case 'length':
                $min = $options['min'] ?? 0;
                $max = $options['max'] ?? PHP_INT_MAX;
                $len = strlen($input);
                return $len >= $min && $len <= $max;
                
            case 'range':
                $min = $options['min'] ?? PHP_INT_MIN;
                $max = $options['max'] ?? PHP_INT_MAX;
                return $input >= $min && $input <= $max;
                
            default:
                return !empty($input);
        }
    }
    
    /**
     * Obter IP real do visitante
     */
    public static function getRealIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',             // Proxy
            'HTTP_X_FORWARDED_FOR',       // Load balancer
            'HTTP_X_FORWARDED',           // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',   // Cluster
            'HTTP_X_REAL_IP',             // Nginx
            'HTTP_FORWARDED_FOR',         // Proxy
            'HTTP_FORWARDED',             // Proxy
            'REMOTE_ADDR'                 // Padrão
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validar IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Obter User Agent
     */
    public static function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    /**
     * Obter Referer
     */
    public static function getReferer() {
        return $_SERVER['HTTP_REFERER'] ?? '';
    }
    
    /**
     * Formatar bytes para leitura humana
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Formatar número
     */
    public static function formatNumber($number, $decimals = 0) {
        if ($number >= 1000000) {
            return number_format($number / 1000000, 1) . 'M';
        } elseif ($number >= 1000) {
            return number_format($number / 1000, 1) . 'K';
        }
        return number_format($number, $decimals);
    }
    
    /**
     * Tempo relativo (ex: "há 5 minutos")
     */
    public static function timeAgo($timestamp) {
        if (!is_numeric($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return 'agora mesmo';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return "há $minutes " . ($minutes == 1 ? 'minuto' : 'minutos');
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return "há $hours " . ($hours == 1 ? 'hora' : 'horas');
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return "há $days " . ($days == 1 ? 'dia' : 'dias');
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return "há $months " . ($months == 1 ? 'mês' : 'meses');
        }
        
        $years = floor($diff / 31536000);
        return "há $years " . ($years == 1 ? 'ano' : 'anos');
    }
    
    /**
     * Gerar slug de URL
     */
    public static function generateSlug($text) {
        // Remover acentos
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        // Converter para minúsculas
        $text = strtolower($text);
        // Remover caracteres especiais
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        // Remover hífens duplicados
        $text = preg_replace('/-+/', '-', $text);
        // Remover hífens do início e fim
        return trim($text, '-');
    }
    
    /**
     * Criptografar dados
     */
    public static function encrypt($data, $key = null) {
        if (!$key) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'default-key';
        }
        
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Descriptografar dados
     */
    public static function decrypt($data, $key = null) {
        if (!$key) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'default-key';
        }
        
        $parts = explode('::', base64_decode($data), 2);
        if (count($parts) !== 2) {
            return false;
        }
        
        return openssl_decrypt($parts[0], 'AES-256-CBC', $key, 0, $parts[1]);
    }
    
    /**
     * Gerar hash seguro
     */
    public static function hash($data, $salt = '') {
        return hash('sha256', $data . $salt);
    }
    
    /**
     * Verificar CSRF Token
     */
    public static function verifyCSRFToken($token) {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Gerar CSRF Token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Log de debug
     */
    public static function debug($data, $label = '') {
        if (!defined('DEBUG') || !DEBUG) {
            return;
        }
        
        $logFile = dirname(__DIR__) . '/storage/logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $message = "[$timestamp]";
        if ($label) {
            $message .= " [$label]";
        }
        $message .= " " . (is_string($data) ? $data : print_r($data, true)) . "\n";
        
        file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log de erro
     */
    public static function logError($message, $context = []) {
        $logFile = dirname(__DIR__) . '/storage/logs/error.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $log = "[$timestamp] ERROR: $message";
        if (!empty($context)) {
            $log .= " | Context: " . json_encode($context);
        }
        $log .= "\n";
        
        file_put_contents($logFile, $log, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Enviar resposta JSON
     */
    public static function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Redirecionar
     */
    public static function redirect($url, $statusCode = 302) {
        header("Location: $url", true, $statusCode);
        exit;
    }
    
    /**
     * Obter extensão de arquivo
     */
    public static function getFileExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Verificar se é requisição AJAX
     */
    public static function isAjax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Verificar se é requisição POST
     */
    public static function isPost() {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
    
    /**
     * Obter dados do POST
     */
    public static function getPost($key = null, $default = null) {
        if ($key === null) {
            return $_POST;
        }
        
        return $_POST[$key] ?? $default;
    }
    
    /**
     * Obter dados do GET
     */
    public static function getGet($key = null, $default = null) {
        if ($key === null) {
            return $_GET;
        }
        
        return $_GET[$key] ?? $default;
    }
    
    /**
     * Limpar cache de diretório
     */
    public static function clearCache($directory = null) {
        if (!$directory) {
            $directory = dirname(__DIR__) . '/storage/cache';
        }
        
        $files = glob($directory . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }
    
    /**
     * Criar diretório se não existir
     */
    public static function createDirectory($path, $permissions = 0755) {
        if (!file_exists($path)) {
            return mkdir($path, $permissions, true);
        }
        
        return true;
    }
    
    /**
     * Detectar dispositivo móvel
     */
    public static function isMobile() {
        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $mobileAgents = [
            'mobile', 'android', 'iphone', 'ipod', 'ipad', 'windows phone',
            'blackberry', 'kindle', 'silk', 'opera mini', 'opera mobi'
        ];
        
        foreach ($mobileAgents as $agent) {
            if (strpos($userAgent, $agent) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detectar bot/crawler
     */
    public static function isBot() {
        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'wget', 'curl',
            'python-requests', 'go-http-client', 'postman', 'insomnia',
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp',
            'telegram', 'slack'
        ];
        
        foreach ($botPatterns as $pattern) {
            if (strpos($userAgent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Parse User Agent
     */
    public static function parseUserAgent($userAgent = null) {
        if (!$userAgent) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        $result = [
            'browser' => 'Unknown',
            'version' => '',
            'os' => 'Unknown',
            'device' => 'Desktop'
        ];
        
        // Detectar browser
        if (preg_match('/Firefox\/([0-9\.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Firefox';
            $result['version'] = $matches[1];
        } elseif (preg_match('/Chrome\/([0-9\.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Chrome';
            $result['version'] = $matches[1];
        } elseif (preg_match('/Safari\/([0-9\.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Safari';
            $result['version'] = $matches[1];
        } elseif (preg_match('/Edge\/([0-9\.]+)/', $userAgent, $matches)) {
            $result['browser'] = 'Edge';
            $result['version'] = $matches[1];
        }
        
        // Detectar OS
        if (preg_match('/Windows NT ([0-9\.]+)/', $userAgent, $matches)) {
            $result['os'] = 'Windows ' . $matches[1];
        } elseif (preg_match('/Mac OS X ([0-9_]+)/', $userAgent)) {
            $result['os'] = 'macOS';
        } elseif (preg_match('/Linux/', $userAgent)) {
            $result['os'] = 'Linux';
        } elseif (preg_match('/Android ([0-9\.]+)/', $userAgent, $matches)) {
            $result['os'] = 'Android ' . $matches[1];
            $result['device'] = 'Mobile';
        } elseif (preg_match('/iPhone|iPad|iPod/', $userAgent)) {
            $result['os'] = 'iOS';
            $result['device'] = preg_match('/iPad/', $userAgent) ? 'Tablet' : 'Mobile';
        }
        
        return $result;
    }
    
    /**
     * Validar licença de domínio
     */
    public static function validateDomain($domain, $allowedDomains) {
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        $domain = strtolower(trim($domain, '/'));
        
        foreach ($allowedDomains as $allowed) {
            $allowed = str_replace(['http://', 'https://', 'www.'], '', $allowed);
            $allowed = strtolower(trim($allowed, '/'));
            
            // Suporte a wildcards
            if (strpos($allowed, '*') !== false) {
                $pattern = str_replace('*', '.*', $allowed);
                if (preg_match('/^' . $pattern . '$/', $domain)) {
                    return true;
                }
            } elseif ($domain === $allowed) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obter configuração
     */
    public static function getConfig($key, $default = null) {
        global $config;
        
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
    
    /**
     * Benchmark de código
     */
    public static function benchmark($callback, $iterations = 1) {
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            call_user_func($callback);
        }
        
        $end = microtime(true);
        $duration = ($end - $start) * 1000; // em ms
        
        return [
            'iterations' => $iterations,
            'total_time' => $duration,
            'avg_time' => $duration / $iterations
        ];
    }
    
    /**
     * Limitar taxa de requisições
     */
    public static function rateLimit($identifier, $maxRequests = 60, $timeWindow = 60) {
        $cacheFile = dirname(__DIR__) . '/storage/cache/rate_limit_' . md5($identifier) . '.json';
        
        $data = [];
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
        }
        
        $now = time();
        $windowStart = $now - $timeWindow;
        
        // Limpar requisições antigas
        $data = array_filter($data, function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        // Verificar limite
        if (count($data) >= $maxRequests) {
            return false;
        }
        
        // Adicionar nova requisição
        $data[] = $now;
        file_put_contents($cacheFile, json_encode($data));
        
        return true;
    }
    
    /**
     * Cache simples em arquivo
     */
    public static function cache($key, $callback = null, $ttl = 3600) {
        $cacheFile = dirname(__DIR__) . '/storage/cache/' . md5($key) . '.cache';
        
        // Tentar obter do cache
        if (file_exists($cacheFile)) {
            $data = unserialize(file_get_contents($cacheFile));
            if ($data['expires'] > time()) {
                return $data['value'];
            }
        }
        
        // Se não houver callback, retornar null
        if ($callback === null) {
            return null;
        }
        
        // Executar callback e salvar no cache
        $value = call_user_func($callback);
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        file_put_contents($cacheFile, serialize($data));
        return $value;
    }
    
    /**
     * Exportar dados para CSV
     */
    public static function exportCSV($data, $filename = 'export.csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Dados
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Minificar HTML
     */
    public static function minifyHTML($html) {
        $search = [
            '/\>[^\S ]+/s',     // strip whitespaces after tags
            '/[^\S ]+\</s',     // strip whitespaces before tags
            '/(\s)+/s',         // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/' // remove HTML comments
        ];
        
        $replace = [
            '>',
            '<',
            '\\1',
            ''
        ];
        
        return preg_replace($search, $replace, $html);
    }
    
    /**
     * Verificar se é SSL/HTTPS
     */
    public static function isSSL() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || $_SERVER['SERVER_PORT'] == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
    }
    
    /**
     * Obter URL base
     */
    public static function getBaseURL() {
        $protocol = self::isSSL() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME']);
        
        return rtrim($protocol . $host . $path, '/');
    }
    
    /**
     * Gerar QR Code (URL da API)
     */
    public static function generateQRCode($data, $size = 200) {
        $data = urlencode($data);
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$data}";
    }
    
    /**
     * Verificar força da senha
     */
    public static function checkPasswordStrength($password) {
        $strength = 0;
        $feedback = [];
        
        if (strlen($password) < 8) {
            $feedback[] = 'Senha deve ter pelo menos 8 caracteres';
        } else {
            $strength += 25;
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $feedback[] = 'Adicione letras minúsculas';
        } else {
            $strength += 25;
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $feedback[] = 'Adicione letras maiúsculas';
        } else {
            $strength += 25;
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $feedback[] = 'Adicione números';
        } else {
            $strength += 25;
        }
        
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $feedback[] = 'Adicione caracteres especiais para maior segurança';
        }
        
        return [
            'strength' => min($strength, 100),
            'feedback' => $feedback,
            'level' => $strength >= 75 ? 'strong' : ($strength >= 50 ? 'medium' : 'weak')
        ];
    }
}