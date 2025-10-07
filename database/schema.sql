-- =====================================================
-- Cloaker Pro - Database Schema
-- Sistema de Cloaking Profissional
-- =====================================================

-- Criar database se não existir
CREATE DATABASE IF NOT EXISTS cloaker_pro 
    DEFAULT CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE cloaker_pro;

-- =====================================================
-- Tabela: Configurações do Sistema
-- =====================================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) UNIQUE NOT NULL,
    value TEXT,
    type ENUM('string', 'integer', 'boolean', 'json', 'array') DEFAULT 'string',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key_name (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Usuários Admin
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'user', 'viewer') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT 1,
    last_login TIMESTAMP NULL,
    last_ip VARCHAR(45),
    api_token VARCHAR(255),
    remember_token VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_api_token (api_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Campanhas
-- =====================================================
CREATE TABLE IF NOT EXISTS campaigns (
    id VARCHAR(32) PRIMARY KEY,
    user_id INT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    safe_page TEXT NOT NULL,
    money_page TEXT NOT NULL,
    mode ENUM('paranoid', 'balanced', 'aggressive', 'custom') DEFAULT 'balanced',
    status BOOLEAN DEFAULT 1,
    is_default BOOLEAN DEFAULT 0,
    pixel_facebook VARCHAR(100),
    pixel_google VARCHAR(100),
    pixel_tiktok VARCHAR(100),
    pixel_snapchat VARCHAR(100),
    pixel_twitter VARCHAR(100),
    custom_code TEXT,
    proxy_safe_page BOOLEAN DEFAULT 0,
    redirect_type ENUM('301', '302', '303', '307', 'meta', 'javascript') DEFAULT '302',
    ab_testing BOOLEAN DEFAULT 0,
    ab_variants JSON,
    hits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Regras de Cloaking
-- =====================================================
CREATE TABLE IF NOT EXISTS rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(32),
    name VARCHAR(100),
    description TEXT,
    type ENUM('geo', 'device', 'isp', 'ip', 'referer', 'time', 'bot', 'vpn', 'proxy', 'language', 'browser', 'os', 'custom') NOT NULL,
    field VARCHAR(100),
    operator ENUM('equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'starts_with', 'ends_with', 'regex', 'between', 'greater_than', 'less_than') NOT NULL,
    value TEXT NOT NULL,
    action ENUM('safe', 'money', 'block', 'redirect') DEFAULT 'safe',
    redirect_url TEXT,
    priority INT DEFAULT 0,
    active BOOLEAN DEFAULT 1,
    hits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_priority (campaign_id, priority),
    INDEX idx_active (active),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Visitantes
-- =====================================================
CREATE TABLE IF NOT EXISTS visitors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(32),
    visitor_id VARCHAR(64),
    session_id VARCHAR(64),
    ip VARCHAR(45) NOT NULL,
    ip_type ENUM('residential', 'datacenter', 'vpn', 'proxy', 'tor', 'mobile', 'unknown') DEFAULT 'unknown',
    country_code VARCHAR(2),
    country_name VARCHAR(100),
    city VARCHAR(100),
    region VARCHAR(100),
    postal_code VARCHAR(20),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    timezone VARCHAR(50),
    isp VARCHAR(200),
    organization VARCHAR(200),
    asn VARCHAR(20),
    user_agent TEXT,
    device_type ENUM('desktop', 'mobile', 'tablet', 'tv', 'console', 'wearable', 'bot', 'unknown') DEFAULT 'unknown',
    device_brand VARCHAR(50),
    device_model VARCHAR(50),
    os VARCHAR(50),
    os_version VARCHAR(20),
    browser VARCHAR(50),
    browser_version VARCHAR(20),
    browser_language VARCHAR(10),
    referer TEXT,
    landing_page TEXT,
    query_string TEXT,
    decision ENUM('safe', 'money', 'blocked') NOT NULL,
    decision_reason VARCHAR(255),
    decision_rule_id INT,
    fingerprint VARCHAR(64),
    is_bot BOOLEAN DEFAULT 0,
    is_vpn BOOLEAN DEFAULT 0,
    is_proxy BOOLEAN DEFAULT 0,
    is_tor BOOLEAN DEFAULT 0,
    bot_probability DECIMAL(3, 2),
    response_time INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_time (campaign_id, created_at),
    INDEX idx_visitor (visitor_id),
    INDEX idx_session (session_id),
    INDEX idx_ip (ip),
    INDEX idx_country (country_code),
    INDEX idx_decision (decision),
    INDEX idx_fingerprint (fingerprint),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Analytics Agregados (por hora)
-- =====================================================
CREATE TABLE IF NOT EXISTS analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(32),
    date DATE NOT NULL,
    hour TINYINT(2),
    total_visitors INT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    safe_page_views INT DEFAULT 0,
    money_page_views INT DEFAULT 0,
    blocked_visitors INT DEFAULT 0,
    bot_visitors INT DEFAULT 0,
    vpn_visitors INT DEFAULT 0,
    mobile_visitors INT DEFAULT 0,
    desktop_visitors INT DEFAULT 0,
    tablet_visitors INT DEFAULT 0,
    countries JSON,
    browsers JSON,
    devices JSON,
    top_referers JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign_date_hour (campaign_id, date, hour),
    INDEX idx_date (date),
    INDEX idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Países (para referência)
-- =====================================================
CREATE TABLE IF NOT EXISTS countries (
    code VARCHAR(2) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    native_name VARCHAR(100),
    continent ENUM('AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'),
    capital VARCHAR(100),
    currency VARCHAR(10),
    languages VARCHAR(100),
    phone_code VARCHAR(10),
    INDEX idx_name (name),
    INDEX idx_continent (continent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Lista de IPs (blacklist/whitelist)
-- =====================================================
CREATE TABLE IF NOT EXISTS ip_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(32),
    type ENUM('blacklist', 'whitelist') NOT NULL,
    ip_start VARBINARY(16),
    ip_end VARBINARY(16),
    ip_cidr VARCHAR(50),
    description TEXT,
    source VARCHAR(100),
    active BOOLEAN DEFAULT 1,
    hits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_ip_range (ip_start, ip_end),
    INDEX idx_type (type),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: User Agents de Bots
-- =====================================================
CREATE TABLE IF NOT EXISTS bot_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(255) NOT NULL,
    bot_name VARCHAR(100),
    bot_type VARCHAR(50),
    category ENUM('search', 'social', 'ads', 'security', 'monitor', 'scraper', 'other') DEFAULT 'other',
    description TEXT,
    active BOOLEAN DEFAULT 1,
    hits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pattern (pattern),
    INDEX idx_category (category),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: ISPs conhecidos
-- =====================================================
CREATE TABLE IF NOT EXISTS known_isps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    type ENUM('residential', 'business', 'hosting', 'vpn', 'proxy', 'mobile', 'university', 'government') DEFAULT 'residential',
    country_code VARCHAR(2),
    asn VARCHAR(20),
    description TEXT,
    is_suspicious BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_type (type),
    INDEX idx_suspicious (is_suspicious)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Logs do Sistema
-- =====================================================
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    level ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') DEFAULT 'info',
    category VARCHAR(100),
    message TEXT,
    context JSON,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_level (level),
    INDEX idx_category (category),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Tokens de API
-- =====================================================
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100),
    token VARCHAR(255) UNIQUE NOT NULL,
    permissions JSON,
    last_used_at TIMESTAMP NULL,
    last_ip VARCHAR(45),
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Cache (opcional, se não usar Redis)
-- =====================================================
CREATE TABLE IF NOT EXISTS cache (
    cache_key VARCHAR(255) PRIMARY KEY,
    cache_value LONGTEXT,
    expiration INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expiration (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Tabela: Sessões
-- =====================================================
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT,
    last_activity INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Popular dados iniciais
-- =====================================================

-- Configurações padrão
INSERT INTO settings (key_name, value, type, description) VALUES
('installed', 'false', 'boolean', 'Sistema instalado'),
('license_key', '', 'string', 'Chave de licença do sistema'),
('license_domain', '', 'string', 'Domínio licenciado'),
('cache_enabled', 'true', 'boolean', 'Ativar sistema de cache'),
('cache_ttl', '300', 'integer', 'Tempo de cache em segundos'),
('geoip_enabled', 'true', 'boolean', 'Ativar detecção GeoIP'),
('geoip_database', 'GeoLite2-City.mmdb', 'string', 'Arquivo do banco GeoIP'),
('bot_detection', 'true', 'boolean', 'Ativar detecção de bots'),
('vpn_detection', 'true', 'boolean', 'Ativar detecção de VPN'),
('proxy_detection', 'true', 'boolean', 'Ativar detecção de proxy'),
('analytics_enabled', 'true', 'boolean', 'Ativar sistema de analytics'),
('analytics_retention_days', '90', 'integer', 'Dias para manter analytics'),
('debug_mode', 'false', 'boolean', 'Modo debug'),
('maintenance_mode', 'false', 'boolean', 'Modo manutenção'),
('api_rate_limit', '60', 'integer', 'Limite de requisições API por minuto'),
('default_safe_page', 'https://google.com', 'string', 'Safe page padrão'),
('default_money_page', 'https://example.com', 'string', 'Money page padrão')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- Usuário admin padrão (senha: admin123)
-- IMPORTANTE: Alterar senha após instalação!
INSERT INTO users (username, password, email, role, is_active) VALUES
('admin', '$2y$10$8K1jR5X5yGK5UVxZ.gJxOuQxKqQ5YFtqFqKqgxX5p5pKV3vE8Z8zG', 'admin@localhost', 'admin', 1)
ON DUPLICATE KEY UPDATE username = VALUES(username);

-- Bots conhecidos
INSERT INTO bot_agents (pattern, bot_name, bot_type, category) VALUES
('Googlebot', 'Google Bot', 'crawler', 'search'),
('bingbot', 'Bing Bot', 'crawler', 'search'),
('Slurp', 'Yahoo Slurp', 'crawler', 'search'),
('DuckDuckBot', 'DuckDuckGo Bot', 'crawler', 'search'),
('Baiduspider', 'Baidu Spider', 'crawler', 'search'),
('YandexBot', 'Yandex Bot', 'crawler', 'search'),
('facebookexternalhit', 'Facebook Bot', 'crawler', 'social'),
('Twitterbot', 'Twitter Bot', 'crawler', 'social'),
('LinkedInBot', 'LinkedIn Bot', 'crawler', 'social'),
('WhatsApp', 'WhatsApp Bot', 'crawler', 'social'),
('TelegramBot', 'Telegram Bot', 'crawler', 'social'),
('Applebot', 'Apple Bot', 'crawler', 'search'),
('AhrefsBot', 'Ahrefs Bot', 'crawler', 'monitor'),
('SemrushBot', 'Semrush Bot', 'crawler', 'monitor'),
('DotBot', 'Moz DotBot', 'crawler', 'monitor'),
('MJ12bot', 'Majestic Bot', 'crawler', 'monitor'),
('AdsBot-Google', 'Google Ads Bot', 'validator', 'ads'),
('Mediapartners-Google', 'Google Adsense', 'validator', 'ads'),
('APIs-Google', 'Google APIs', 'api', 'search'),
('bingpreview', 'Bing Preview', 'preview', 'search')
ON DUPLICATE KEY UPDATE pattern = VALUES(pattern);

-- Alguns países (adicionar mais conforme necessário)
INSERT INTO countries (code, name, continent) VALUES
('US', 'United States', 'NA'),
('BR', 'Brazil', 'SA'),
('GB', 'United Kingdom', 'EU'),
('DE', 'Germany', 'EU'),
('FR', 'France', 'EU'),
('ES', 'Spain', 'EU'),
('IT', 'Italy', 'EU'),
('CA', 'Canada', 'NA'),
('AU', 'Australia', 'OC'),
('JP', 'Japan', 'AS'),
('CN', 'China', 'AS'),
('IN', 'India', 'AS'),
('MX', 'Mexico', 'NA'),
('AR', 'Argentina', 'SA'),
('PT', 'Portugal', 'EU')
ON DUPLICATE KEY UPDATE name = VALUES(name);