<?php
/**
 * Cloaker Pro - Migração de Slugs
 * 
 * Script para atualizar todas as campanhas existentes
 * com slugs seguros e aleatórios
 * 
 * EXECUTAR UMA ÚNICA VEZ via CLI:
 * php migrate-slugs.php
 */

require_once 'config.php';
require_once 'core/Database.php';
require_once 'core/Campaign.php';

echo "🔐 Cloaker Pro - Migração de Slugs Seguros\n";
echo "==========================================\n\n";

try {
    $campaign = new Campaign();
    $db = new Database();
    
    // Buscar todas as campanhas
    $campaigns = $db->select('campaigns', [], '*');
    
    if (empty($campaigns)) {
        echo "✅ Nenhuma campanha encontrada.\n";
        exit(0);
    }
    
    echo "📊 Encontradas " . count($campaigns) . " campanhas.\n\n";
    
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($campaigns as $camp) {
        echo "Processando: {$camp['name']} (ID: {$camp['id']})\n";
        echo "  Slug atual: {$camp['slug']}\n";
        
        // Verificar se o slug já é seguro (aleatório)
        // Slugs seguros têm pelo menos 8 caracteres hexadecimais
        if (preg_match('/[a-f0-9]{8,}/', $camp['slug'])) {
            echo "  ⏭️  Slug já é seguro - pulando\n\n";
            $skipped++;
            continue;
        }
        
        try {
            // Gerar novo slug seguro
            $result = $campaign->regenerateSlug($camp['id']);
            
            if ($result) {
                // Buscar campanha atualizada
                $updatedCamp = $campaign->get($camp['id']);
                echo "  ✅ Atualizado! Novo slug: {$updatedCamp['slug']}\n";
                echo "  🔗 Nova URL: " . BASE_URL . "/c/{$updatedCamp['slug']}\n\n";
                $updated++;
            } else {
                echo "  ❌ Falha ao atualizar\n\n";
                $errors++;
            }
            
        } catch (Exception $e) {
            echo "  ❌ Erro: " . $e->getMessage() . "\n\n";
            $errors++;
        }
        
        // Pequeno delay para não sobrecarregar
        usleep(100000); // 0.1 segundo
    }
    
    echo "\n==========================================\n";
    echo "📊 RESUMO DA MIGRAÇÃO\n";
    echo "==========================================\n";
    echo "✅ Atualizadas:  $updated\n";
    echo "⏭️  Puladas:      $skipped\n";
    echo "❌ Erros:        $errors\n";
    echo "📈 Total:        " . count($campaigns) . "\n\n";
    
    if ($updated > 0) {
        echo "⚠️  IMPORTANTE: Atualize os links das campanhas em:\n";
        echo "   - Anúncios do Facebook/Google\n";
        echo "   - Posts em redes sociais\n";
        echo "   - E-mails de marketing\n";
        echo "   - Qualquer lugar onde os links antigos estejam publicados\n\n";
        
        echo "🔗 Formato dos novos links:\n";
        echo "   " . BASE_URL . "/c/{slug}\n\n";
    }
    
    echo "✅ Migração concluída!\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
?>