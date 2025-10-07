<?php
/**
 * Cloaker Pro - Criar Usuário Admin
 * Execute este arquivo UMA VEZ para criar o usuário admin
 * Depois DELETE este arquivo por segurança!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Carregar configurações
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Database.php';

try {
    $db = Database::getInstance();
    
    // Dados do admin
    $username = 'admin';
    $email = 'admin@cloaker.com';
    $password = 'admin123'; // MUDE ISSO DEPOIS!
    
    // Verificar se já existe
    $existing = $db->selectOne('users', ['username' => $username]);
    
    if ($existing) {
        echo "<h2>✅ Usuário admin já existe!</h2>";
        echo "<p><strong>Username:</strong> {$existing['username']}</p>";
        echo "<p><strong>Email:</strong> {$existing['email']}</p>";
        echo "<p><strong>Status:</strong> {$existing['status']}</p>";
        echo "<hr>";
        echo "<h3>🔄 Deseja resetar a senha para 'admin123'?</h3>";
        
        if (isset($_GET['reset']) && $_GET['reset'] === 'yes') {
            $updated = $db->update('users', [
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'status' => 'active'
            ], ['username' => $username]);
            
            if ($updated) {
                echo "<p style='color: green; font-weight: bold;'>✅ Senha resetada com sucesso!</p>";
                echo "<p><strong>Username:</strong> admin</p>";
                echo "<p><strong>Nova Senha:</strong> admin123</p>";
            } else {
                echo "<p style='color: red;'>❌ Erro ao resetar senha!</p>";
            }
        } else {
            echo "<p><a href='?reset=yes' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Sim, Resetar Senha</a></p>";
        }
        
    } else {
        // Criar novo usuário
        $userId = $db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($userId) {
            echo "<h2 style='color: green;'>✅ Usuário admin criado com sucesso!</h2>";
            echo "<p><strong>ID:</strong> {$userId}</p>";
            echo "<p><strong>Username:</strong> {$username}</p>";
            echo "<p><strong>Email:</strong> {$email}</p>";
            echo "<p><strong>Senha:</strong> {$password}</p>";
            echo "<hr>";
            echo "<p style='color: red; font-weight: bold;'>⚠️ IMPORTANTE: Altere a senha após o primeiro login!</p>";
        } else {
            echo "<h2 style='color: red;'>❌ Erro ao criar usuário!</h2>";
        }
    }
    
    echo "<hr>";
    echo "<h3>📋 Todos os usuários cadastrados:</h3>";
    
    $users = $db->select('users', [], 'id, username, email, role, status, created_at');
    
    if (!empty($users)) {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Criado em</th>";
        echo "</tr>";
        
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td><strong>{$user['username']}</strong></td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>{$user['status']}</td>";
            echo "<td>{$user['created_at']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p>Nenhum usuário encontrado no banco de dados.</p>";
    }
    
    echo "<hr>";
    echo "<p><a href='admin/login.php' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ir para Login</a></p>";
    echo "<p style='color: red; margin-top: 20px;'><strong>⚠️ DELETE ESTE ARQUIVO (create-admin.php) APÓS CRIAR O USUÁRIO!</strong></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<hr>";
    echo "<h3>Detalhes para Debug:</h3>";
    echo "<pre>";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Admin - Cloaker Pro</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h2 { color: #333; }
        table {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th {
            background: #667eea;
            color: white;
        }
        p { line-height: 1.6; }
        hr { margin: 30px 0; }
    </style>
</head>
<body>
    <!-- Conteúdo gerado acima -->
</body>
</html>