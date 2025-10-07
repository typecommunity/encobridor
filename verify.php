<?php
/**
 * Cloaker Pro - Verificação de Instalação
 * Execute: https://ataweb.com.br/cloaker-pro/verify.php
 * 
 * IMPORTANTE: Delete este arquivo após a verificação!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$checks = [];
$errors = [];
$warnings = [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloaker Pro - Verificação de Instalação</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            color: #fff;
            line-height: 1.6;
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 2rem;
        }
        h1 { 
            text-align: center;
            margin-bottom: 2rem;
            font-size: 2.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .check-group {
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .check-group h2 {
            margin-bottom: 1rem;
            font-size: 1.5rem;
            border-bottom: 2px solid rgba(255,255,255,0.3);
            padding-bottom: 0.5rem;
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin: 0.5rem 0;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
        }
        .icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 1rem;
            font-size: 1.2rem;
        }
        .ok { background: #4ade80; color: #064e3b; }
        .error { background: #f87171; color: #7f1d1d; }
        .warning { background: #fbbf24; color: #78350f; }
        .detail {
            flex: 1;
        }
        .code {
            background: rgba(0,0,0,0.3);
            padding: 0.5rem;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin: 0.25rem 0;
        }
        .summary {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 2rem;
            margin-top: 2rem;
            text-align: center;
        }
        .summary h2 {
            margin-bottom: 1rem;
            font-size: 2rem;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin: 0.5rem;
            font-weight: 600;
            border: 2px solid rgba(255,255,255,0.3);
            transition: all 0.3s;
        }
        .btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Verificação de Instalação</h1>

        <!-- CHECK 1: ARQUIVOS PRINCIPAIS -->
        <div class="check-group">
            <h2>1. Arquivos Principais</h2>
            <?php
            $files = [
                '.htaccess' => __DIR__ . '/.htaccess',
                'cloak.php' => __DIR__ . '/cloak.php',
                'config.php' => __DIR__ . '/config.php',
                '.env' => __DIR__ . '/.env'
            ];
            
            foreach ($files as $name => $path) {
                $exists = file_exists($path);
                $size = $exists ? filesize($path) : 0;
                
                echo '<div class="check-item">';
                echo '<div class="icon ' . ($exists ? 'ok' : 'error') . '">' . ($exists ? '✓' : '✗') . '</div>';
                echo '<div class="detail">';
                echo '<strong>' . $name . '</strong><br>';
                if ($exists) {
                    echo '<span style="opacity: 0.8;">Tamanho: ' . number_format($size) . ' bytes</span>';
                    
                    // Verificações específicas
                    if ($name === '.htaccess') {
                        $content = file_get_contents($path);
                        if (strpos($content, 'cloak.php?c=') !== false) {
                            echo ' <span style="color: #4ade80;">✓ Regra de cloaking OK</span>';
                        } else {
                            echo ' <span style="color: #f87171;">✗ Regra de cloaking não encontrada</span>';
                            $errors[] = 'Regra de cloaking ausente no .htaccess';
                        }
                    }
                    
                    if ($name === '.env') {
                        $content = file_get_contents($path);
                        if (strpos($content, 'BASE_URL=') !== false) {
                            preg_match('/BASE_URL=(.+)/', $content, $matches);
                            $baseUrl = trim($matches[1] ?? '');
                            echo '<br><code class="code">BASE_URL=' . htmlspecialchars($baseUrl) . '</code>';
                            
                            $expected = 'https://ataweb.com.br/cloaker-pro';
                            if ($baseUrl !== $expected) {
                                echo ' <span style="color: #fbbf24;">⚠ Verifique se está correto</span>';
                                $warnings[] = "BASE_URL pode estar incorreto. Esperado: $expected";
                            }
                        } else {
                            echo ' <span style="color: #f87171;">✗ BASE_URL não definido</span>';
                            $errors[] = 'BASE_URL não encontrado no .env';
                        }
                    }
                } else {
                    echo '<span style="color: #f87171;">Arquivo não encontrado!</span>';
                    $errors[] = "Arquivo $name não encontrado";
                }
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- CHECK 2: ESTRUTURA DE DIRETÓRIOS -->
        <div class="check-group">
            <h2>2. Estrutura de Diretórios</h2>
            <?php
            $dirs = [
                'core' => __DIR__ . '/core',
                'admin' => __DIR__ . '/admin',
                'storage' => __DIR__ . '/storage',
                'storage/logs' => __DIR__ . '/storage/logs'
            ];
            
            foreach ($dirs as $name => $path) {
                $exists = is_dir($path);
                $writable = $exists ? is_writable($path) : false;
                
                echo '<div class="check-item">';
                echo '<div class="icon ' . ($exists ? 'ok' : 'error') . '">' . ($exists ? '✓' : '✗') . '</div>';
                echo '<div class="detail">';
                echo '<strong>' . $name . '/</strong><br>';
                
                if ($exists) {
                    echo '<span style="opacity: 0.8;">Existe</span>';
                    if ($writable) {
                        echo ' <span style="color: #4ade80;">✓ Gravável</span>';
                    } else {
                        echo ' <span style="color: #f87171;">✗ Não gravável</span>';
                        $errors[] = "Diretório $name não é gravável";
                    }
                } else {
                    echo '<span style="color: #f87171;">Não encontrado</span>';
                    $errors[] = "Diretório $name não encontrado";
                }
                
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- CHECK 3: CLASSES CORE -->
        <div class="check-group">
            <h2>3. Classes do Sistema</h2>
            <?php
            $classes = [
                'Database.php',
                'Campaign.php',
                'Detector.php',
                'Analytics.php'
            ];
            
            foreach ($classes as $class) {
                $path = __DIR__ . '/core/' . $class;
                $exists = file_exists($path);
                
                echo '<div class="check-item">';
                echo '<div class="icon ' . ($exists ? 'ok' : 'error') . '">' . ($exists ? '✓' : '✗') . '</div>';
                echo '<div class="detail">';
                echo '<strong>core/' . $class . '</strong><br>';
                echo '<span style="opacity: 0.8;">' . ($exists ? 'OK' : 'Não encontrado') . '</span>';
                echo '</div>';
                echo '</div>';
                
                if (!$exists) {
                    $errors[] = "Classe $class não encontrada";
                }
            }
            ?>
        </div>

        <!-- CHECK 4: CONEXÃO COM BANCO -->
        <div class="check-group">
            <h2>4. Banco de Dados</h2>
            <?php
            try {
                require_once __DIR__ . '/config.php';
                
                if (isset($pdo) && $pdo instanceof PDO) {
                    echo '<div class="check-item">';
                    echo '<div class="icon ok">✓</div>';
                    echo '<div class="detail">';
                    echo '<strong>Conexão PDO</strong><br>';
                    echo '<span style="opacity: 0.8;">Conectado com sucesso</span><br>';
                    echo '<code class="code">Host: ' . DB_HOST . ' | Database: ' . DB_NAME . '</code>';
                    echo '</div>';
                    echo '</div>';
                    
                    // Testar query
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM campaigns");
                    $result = $stmt->fetch();
                    
                    echo '<div class="check-item">';
                    echo '<div class="icon ok">✓</div>';
                    echo '<div class="detail">';
                    echo '<strong>Tabela Campaigns</strong><br>';
                    echo '<span style="opacity: 0.8;">Total de campanhas: <strong>' . $result['total'] . '</strong></span>';
                    echo '</div>';
                    echo '</div>';
                    
                    // Campanhas ativas
                    $stmt = $pdo->query("SELECT * FROM campaigns WHERE status = 'active'");
                    $activeCampaigns = $stmt->fetchAll();
                    
                    if (empty($activeCampaigns)) {
                        echo '<div class="check-item">';
                        echo '<div class="icon warning">⚠</div>';
                        echo '<div class="detail">';
                        echo '<strong>Campanhas Ativas</strong><br>';
                        echo '<span style="color: #fbbf24;">Nenhuma campanha ativa. Ative uma no painel admin.</span>';
                        echo '</div>';
                        echo '</div>';
                        $warnings[] = 'Nenhuma campanha ativa';
                    } else {
                        echo '<div class="check-item">';
                        echo '<div class="icon ok">✓</div>';
                        echo '<div class="detail">';
                        echo '<strong>Campanhas Ativas (' . count($activeCampaigns) . ')</strong><br>';
                        foreach ($activeCampaigns as $camp) {
                            $url = BASE_URL . '/c/' . $camp['slug'];
                            echo '<div style="margin: 0.25rem 0;">';
                            echo '📌 <strong>' . htmlspecialchars($camp['name']) . '</strong> ';
                            echo '<code style="background: rgba(0,0,0,0.3); padding: 0.25rem 0.5rem; border-radius: 3px;">' . htmlspecialchars($camp['slug']) . '</code> ';
                            echo '<a href="' . $url . '" target="_blank" style="color: #4ade80; text-decoration: none;">Testar →</a>';
                            echo '</div>';
                        }
                        echo '</div>';
                        echo '</div>';
                    }
                    
                } else {
                    echo '<div class="check-item">';
                    echo '<div class="icon error">✗</div>';
                    echo '<div class="detail">';
                    echo '<strong>Conexão PDO</strong><br>';
                    echo '<span style="color: #f87171;">Conexão não estabelecida</span>';
                    echo '</div>';
                    echo '</div>';
                    $errors[] = 'Conexão com banco de dados falhou';
                }
            } catch (Exception $e) {
                echo '<div class="check-item">';
                echo '<div class="icon error">✗</div>';
                echo '<div class="detail">';
                echo '<strong>Erro no Banco</strong><br>';
                echo '<span style="color: #f87171;">' . htmlspecialchars($e->getMessage()) . '</span>';
                echo '</div>';
                echo '</div>';
                $errors[] = 'Erro: ' . $e->getMessage();
            }
            ?>
        </div>

        <!-- CHECK 5: MOD_REWRITE -->
        <div class="check-group">
            <h2>5. Apache mod_rewrite</h2>
            <?php
            if (function_exists('apache_get_modules')) {
                $modules = apache_get_modules();
                $hasRewrite = in_array('mod_rewrite', $modules);
                
                echo '<div class="check-item">';
                echo '<div class="icon ' . ($hasRewrite ? 'ok' : 'error') . '">' . ($hasRewrite ? '✓' : '✗') . '</div>';
                echo '<div class="detail">';
                echo '<strong>mod_rewrite</strong><br>';
                echo '<span style="opacity: 0.8;">' . ($hasRewrite ? 'Ativo' : 'Inativo') . '</span>';
                echo '</div>';
                echo '</div>';
                
                if (!$hasRewrite) {
                    $errors[] = 'mod_rewrite não está ativo';
                }
            } else {
                echo '<div class="check-item">';
                echo '<div class="icon warning">⚠</div>';
                echo '<div class="detail">';
                echo '<strong>mod_rewrite</strong><br>';
                echo '<span style="opacity: 0.8;">Não foi possível verificar (função apache_get_modules indisponível)</span>';
                echo '</div>';
                echo '</div>';
                $warnings[] = 'Não foi possível verificar mod_rewrite';
            }
            ?>
        </div>

        <!-- RESUMO FINAL -->
        <div class="summary">
            <?php
            $totalErrors = count($errors);
            $totalWarnings = count($warnings);
            
            if ($totalErrors === 0 && $totalWarnings === 0) {
                echo '<h2 style="color: #4ade80;">✅ Tudo OK!</h2>';
                echo '<p style="font-size: 1.2rem; margin: 1rem 0;">O sistema está 100% configurado e pronto para uso.</p>';
            } elseif ($totalErrors === 0) {
                echo '<h2 style="color: #fbbf24;">⚠️ Avisos Detectados</h2>';
                echo '<p style="margin: 1rem 0;">O sistema está funcional, mas há ' . $totalWarnings . ' aviso(s):</p>';
                echo '<ul style="text-align: left; max-width: 600px; margin: 1rem auto;">';
                foreach ($warnings as $warning) {
                    echo '<li style="margin: 0.5rem 0;">' . htmlspecialchars($warning) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<h2 style="color: #f87171;">❌ Erros Encontrados</h2>';
                echo '<p style="margin: 1rem 0;">Foram encontrados ' . $totalErrors . ' erro(s):</p>';
                echo '<ul style="text-align: left; max-width: 600px; margin: 1rem auto;">';
                foreach ($errors as $error) {
                    echo '<li style="margin: 0.5rem 0; color: #f87171;">' . htmlspecialchars($error) . '</li>';
                }
                echo '</ul>';
            }
            ?>
            
            <div style="margin-top: 2rem;">
                <a href="<?php echo BASE_URL; ?>/admin" class="btn">📊 Painel Admin</a>
                <?php if (isset($activeCampaigns) && !empty($activeCampaigns)): ?>
                    <a href="<?php echo BASE_URL . '/c/' . $activeCampaigns[0]['slug']; ?>" target="_blank" class="btn">🔗 Testar Campanha</a>
                <?php endif; ?>
            </div>
        </div>

        <p style="text-align: center; margin-top: 2rem; opacity: 0.8;">
            <small>⚠️ <strong>IMPORTANTE:</strong> Delete este arquivo (verify.php) após a verificação!</small>
        </p>
    </div>
</body>
</html>