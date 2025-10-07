<?php
/**
 * ============================================
 * CLOAKER PRO - LICENSE BLOCKED PAGE
 * ============================================
 * 
 * Arquivo: license-blocked.php
 * Caminho: cloaker-pro/license-blocked.php
 * URL: https://cliente-dominio.com/license-blocked.php
 * 
 * Descrição: Página exibida quando a licença está:
 * - Suspensa
 * - Expirada
 * - Não configurada
 * - Inválida
 * 
 * Instrui o usuário a entrar em contato com o administrador
 * ============================================
 */

session_start();
$error = $_SESSION['license_error'] ?? 'License verification failed';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licença Bloqueada - Cloaker Pro</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 600px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            padding: 40px;
            text-align: center;
            color: white;
        }
        
        .header svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 16px;
        }
        
        .content {
            padding: 40px;
        }
        
        .error-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .error-box h3 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .error-box p {
            color: #856404;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .info-section h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .info-section p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 15px;
        }
        
        .contact-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .contact-box h4 {
            color: #333;
            margin-bottom: 15px;
        }
        
        .contact-btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .contact-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #dee2e6;
        }
        
        .footer p {
            color: #6c757d;
            font-size: 14px;
        }
        
        .status-icon {
            display: inline-block;
            width: 12px;
            height: 12px;
            background: #dc3545;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                      d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <h1>Acesso Bloqueado</h1>
            <p>Sistema de Licenciamento Cloaker Pro</p>
        </div>
        
        <div class="content">
            <div class="error-box">
                <h3><span class="status-icon"></span>Erro de Licença</h3>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
            
            <div class="info-section">
                <h3>O que aconteceu?</h3>
                <p>
                    O sistema detectou um problema com a licença do Cloaker Pro instalada neste domínio. 
                    Isso pode ocorrer por diversos motivos:
                </p>
                <ul style="color: #666; line-height: 1.8; margin-left: 20px;">
                    <li>Licença suspensa pelo administrador</li>
                    <li>Licença expirada</li>
                    <li>Licença não configurada corretamente</li>
                    <li>Problema de comunicação com o servidor de validação</li>
                </ul>
            </div>
            
            <div class="info-section">
                <h3>O que fazer agora?</h3>
                <p>
                    Para resolver este problema e voltar a utilizar o sistema, você precisa entrar em 
                    contato com o administrador do sistema.
                </p>
            </div>
            
            <div class="contact-box">
                <h4>Precisa de Ajuda?</h4>
                <a href="https://autostacker.app/login/cloaker/" class="contact-btn" target="_blank">
                    Acessar Painel de Licenças
                </a>
            </div>
        </div>
        
        <div class="footer">
            <p>Cloaker Pro - Sistema de Licenciamento | Powered by AutoStacker</p>
        </div>
    </div>
</body>
</html>