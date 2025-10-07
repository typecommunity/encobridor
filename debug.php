<?php
/**
 * TESTE: Verificar se .htaccess está sendo processado
 * Salvar em: /cloaker-pro/test-htaccess.php
 */

header('Content-Type: text/html; charset=utf-8');

$htaccessPath = __DIR__ . '/.htaccess';
$htaccessExists = file_exists($htaccessPath);
$htaccessContent = $htaccessExists ? file_get_contents($htaccessPath) : '';
$htaccessReadable = $htaccessExists && is_readable($htaccessPath);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Teste .htaccess</title>
    <style>
        body {
            font-family: monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.6;
        }
        .box {
            background: #2d2d2d;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #007acc;
        }
        .error { border-left-color: #f48771; }
        .success { border-left-color: #89d185; }
        h2 { margin-top: 0; color: #4ec9b0; }
        code {
            background: #1e1e1e;
            padding: 2px 6px;
            border-radius: 3px;
        }
        pre {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border: 1px solid #3e3e3e;
        }
    </style>
</head>
<body>
    <h1>🔍 Verificação do .htaccess</h1>

    <div class="box <?= $htaccessExists ? 'success' : 'error' ?>">
        <h2>1. Arquivo Existe?</h2>
        <p><strong>Caminho:</strong> <code><?= $htaccessPath ?></code></p>
        <p><strong>Status:</strong> <?= $htaccessExists ? '✅ SIM' : '❌ NÃO' ?></p>
        <?php if ($htaccessExists): ?>
            <p><strong>Tamanho:</strong> <?= filesize($htaccessPath) ?> bytes</p>
            <p><strong>Legível:</strong> <?= $htaccessReadable ? '✅ SIM' : '❌ NÃO' ?></p>
            <p><strong>Permissões:</strong> <?= substr(sprintf('%o', fileperms($htaccessPath)), -4) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($htaccessExists): ?>
        <div class="box">
            <h2>2. Conteúdo do .htaccess</h2>
            <pre><?= htmlspecialchars($htaccessContent) ?></pre>
        </div>

        <div class="box">
            <h2>3. Análise das Regras</h2>
            <?php
            $hasRewriteEngine = strpos($htaccessContent, 'RewriteEngine On') !== false;
            $hasRewriteRule = strpos($htaccessContent, 'RewriteRule') !== false;
            $hasCloakRule = strpos($htaccessContent, 'cloak.php') !== false;
            $hasIfModule = strpos($htaccessContent, '<IfModule') !== false;
            ?>
            <p>✓ RewriteEngine On: <?= $hasRewriteEngine ? '✅ SIM' : '❌ NÃO' ?></p>
            <p>✓ RewriteRule: <?= $hasRewriteRule ? '✅ SIM' : '❌ NÃO' ?></p>
            <p>✓ cloak.php: <?= $hasCloakRule ? '✅ SIM' : '❌ NÃO' ?></p>
            <p>✓ &lt;IfModule&gt;: <?= $hasIfModule ? '⚠️ SIM (pode causar problemas)' : '✅ NÃO (correto)' ?></p>
        </div>
    <?php endif; ?>

    <div class="box">
        <h2>4. Teste de Redirecionamento</h2>
        <p>O .htaccess pode estar sendo ignorado pelo servidor.</p>
        <p><strong>Para testar, adicione esta linha NO TOPO do .htaccess:</strong></p>
        <pre>Redirect 301 /cloaker-pro/test-redirect /cloaker-pro/test-htaccess.php</pre>
        <p>Depois acesse: <code>https://ataweb.com.br/cloaker-pro/test-redirect</code></p>
        <p>Se redirecionar para esta página = .htaccess está sendo lido ✅</p>
        <p>Se der 404 = .htaccess está sendo ignorado ❌</p>
    </div>

    <div class="box">
        <h2>5. Informações do Servidor</h2>
        <p><strong>SERVER_SOFTWARE:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></p>
        <p><strong>DOCUMENT_ROOT:</strong> <?= $_SERVER['DOCUMENT_ROOT'] ?? 'N/A' ?></p>
        <p><strong>SCRIPT_FILENAME:</strong> <?= $_SERVER['SCRIPT_FILENAME'] ?? 'N/A' ?></p>
        <p><strong>REQUEST_URI:</strong> <?= $_SERVER['REQUEST_URI'] ?? 'N/A' ?></p>
    </div>

    <div class="box error">
        <h2>⚠️ Possíveis Causas do 404</h2>
        <ol>
            <li><strong>AllowOverride está desativado</strong> no LiteSpeed (mais provável)</li>
            <li><strong>.htaccess não está sendo lido</strong> pelo servidor</li>
            <li><strong>Rewrite está desativado</strong> no LiteSpeed</li>
            <li><strong>Sintaxe incorreta</strong> no .htaccess</li>
        </ol>
    </div>

    <div class="box">
        <h2>🔧 Solução Alternativa: .htaccess Simplificado</h2>
        <p>Substitua TODO o conteúdo do .htaccess por este código testado:</p>
        <pre>RewriteEngine On

# Domínios personalizados
RewriteCond %{HTTP_HOST} !^(www\.)?ataweb\.com\.br$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^c/([a-zA-Z0-9\-_]+)/?$ test-rewrite.php?c=$1 [L,QSA]

# Domínio principal
RewriteCond %{HTTP_HOST} ^(www\.)?ataweb\.com\.br$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^c/([a-zA-Z0-9\-_]+)/?$ test-rewrite.php?c=$1 [L,QSA]</pre>
        <p><small>Nota: Estou usando test-rewrite.php primeiro para validar</small></p>
    </div>
</body>
</html>