<?php
/**
 * Script para preparar database.sql para produção
 * Remove dados de teste e mantém apenas estrutura + dados essenciais
 */

echo "🔧 Preparando database.sql para GitHub...\n\n";

// Ler arquivo original
$sqlFile = __DIR__ . '/ataw_clock.sql';
if (!file_exists($sqlFile)) {
    die("❌ Erro: Arquivo ataw_clock.sql não encontrado!\n");
}

echo "📖 Lendo arquivo original...\n";
$sql = file_get_contents($sqlFile);

// Remover dumps de tabelas com dados de teste
$tablesToClean = [
    'users',
    'user_activities', 
    'visitors',
    'visitor_logs',
    'system_logs',
    'activity_logs',
    'tenants',
    'tenant_activities',
    'campaigns',
    'fingerprints',
    'geoip_cache',
    'blocked_ips',
    'scraping_attempts',
    'login_attempts',
    'password_resets'
];

echo "🧹 Limpando dados de teste...\n";

foreach ($tablesToClean as $table) {
    // Encontrar e remover INSERTs da tabela
    $pattern = "/INSERT INTO `{$table}`.*?;/is";
    $sql = preg_replace($pattern, "-- Data removed for production\n", $sql);
    echo "  ✓ {$table}\n";
}

// Adicionar comentário no topo
$header = "-- Cloaker Pro - Production Database\n";
$header .= "-- Version: 2.0.0\n";
$header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
$header .= "-- \n";
$header .= "-- This file contains:\n";
$header .= "-- ✓ Complete database structure\n";
$header .= "-- ✓ Essential configuration data\n";
$header .= "-- ✗ No user data\n";
$header .= "-- ✗ No test data\n";
$header .= "-- \n";
$header .= "-- Use install.php for easy installation\n";
$header .= "-- --------------------------------------------\n\n";

$sql = $header . $sql;

// Salvar arquivo limpo
$outputFile = __DIR__ . '/database.sql';
file_put_contents($outputFile, $sql);

echo "\n✅ Arquivo preparado com sucesso!\n";
echo "📁 Salvo em: database.sql\n\n";

// Estatísticas
$lines = count(file($outputFile));
$size = filesize($outputFile);
$sizeKb = round($size / 1024, 2);

echo "📊 Estatísticas:\n";
echo "  Linhas: {$lines}\n";
echo "  Tamanho: {$sizeKb} KB\n\n";

// Verificar tabelas essenciais que DEVEM ter dados
echo "🔍 Verificando dados essenciais...\n";

$essentialTables = [
    'antiscraping_config' => 'Configurações anti-scraping',
    'bot_agents' => 'Lista de bots conhecidos',
    'datacenter_ranges' => 'IPs de datacenters',
    'tor_exit_nodes' => 'Nós de saída TOR',
    'suspicious_patterns' => 'Padrões suspeitos',
    'settings' => 'Configurações do sistema'
];

foreach ($essentialTables as $table => $desc) {
    $hasData = preg_match("/INSERT INTO `{$table}`/", $sql);
    $status = $hasData ? '✓' : '❌';
    echo "  {$status} {$desc}\n";
}

echo "\n✨ Pronto para GitHub!\n";
echo "📝 Próximos passos:\n";
echo "  1. Revise o arquivo database.sql\n";
echo "  2. Teste com install.php\n";
echo "  3. Faça commit no GitHub\n\n";