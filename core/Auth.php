<?php
/**
 * Cloaker Pro - Auth System
 * Sistema de autenticação de administradores
 */

class Auth {
    private $db;
    private $sessionName = 'cloaker_admin';
    private $cookieName = 'cloaker_remember';
    private $maxAttempts = 5;
    private $lockoutTime = 900; // 15 minutos
    
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->db = Database::getInstance();
        $this->checkRememberMe();
    }
    
    /**
     * Login do usuário
     */
    public function login($username, $password, $remember = false) {
        // Verificar lockout
        if ($this->isLockedOut($username)) {
            return [
                'success' => false,
                'message' => 'Conta bloqueada temporariamente devido a muitas tentativas falhas.'
            ];
        }
        
        // Buscar usuário
        $user = $this->getUserByUsername($username);
        
        if (!$user || !password_verify($password, $user['password'])) {
            $this->recordFailedAttempt($username);
            return [
                'success' => false,
                'message' => 'Usuário ou senha inválidos.'
            ];
        }
        
        // Verificar se o usuário está ativo
        if ($user['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Conta inativa. Entre em contato com o administrador.'
            ];
        }
        
        // Login bem-sucedido
        $this->createSession($user);
        $this->clearFailedAttempts($username);
        $this->updateLastLogin($user['id']);
        
        // Remember me
        if ($remember) {
            $this->setRememberToken($user['id']);
        }
        
        // Log do login
        $this->logActivity($user['id'], 'login', [
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        return [
            'success' => true,
            'message' => 'Login realizado com sucesso!',
            'user' => $this->getSafeUserData($user)
        ];
    }
    
    /**
     * Logout do usuário
     */
    public function logout() {
        if ($this->isLoggedIn()) {
            $userId = $_SESSION[$this->sessionName]['id'];
            
            // Log do logout
            $this->logActivity($userId, 'logout');
            
            // Remover remember token
            $this->removeRememberToken($userId);
        }
        
        // Destruir sessão
        $_SESSION = [];
        session_destroy();
        
        // Remover cookie
        if (isset($_COOKIE[$this->cookieName])) {
            setcookie($this->cookieName, '', time() - 3600, '/');
        }
        
        return true;
    }
    
    /**
     * Verificar se está logado
     */
    public function isLoggedIn() {
        return isset($_SESSION[$this->sessionName]) && 
               isset($_SESSION[$this->sessionName]['id']);
    }
    
    /**
     * Obter usuário atual
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $userId = $_SESSION[$this->sessionName]['id'];
        return $this->db->selectOne('users', ['id' => $userId, 'status' => 'active']);
    }
    
    /**
     * Verificar permissão
     */
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        
        // Admin tem todas as permissões
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // Verificar permissões específicas do role
        $permissions = $this->getRolePermissions($user['role']);
        return in_array($permission, $permissions);
    }
    
    /**
     * Criar novo usuário
     */
    public function createUser($username, $password, $email, $role = 'user') {
        // Verificar se usuário já existe
        $existing = $this->db->selectOne('users', ['username' => $username]);
        if ($existing) {
            return [
                'success' => false,
                'message' => 'Nome de usuário já existe.'
            ];
        }
        
        $existingEmail = $this->db->selectOne('users', ['email' => $email]);
        if ($existingEmail) {
            return [
                'success' => false,
                'message' => 'Email já cadastrado.'
            ];
        }
        
        // Criar usuário
        $userId = $this->db->insert('users', [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'role' => $role,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($userId) {
            return [
                'success' => true,
                'message' => 'Usuário criado com sucesso!',
                'user_id' => $userId
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Erro ao criar usuário.'
        ];
    }
    
    /**
     * Alterar senha
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        $user = $this->db->selectOne('users', ['id' => $userId]);
        
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return [
                'success' => false,
                'message' => 'Senha atual incorreta.'
            ];
        }
        
        $success = $this->db->update('users', [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT)
        ], ['id' => $userId]);
        
        if ($success) {
            $this->logActivity($userId, 'password_changed');
            return [
                'success' => true,
                'message' => 'Senha alterada com sucesso!'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Erro ao alterar senha.'
        ];
    }
    
    /**
     * Reset de senha
     */
    public function resetPassword($email) {
        $user = $this->db->selectOne('users', ['email' => $email]);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Email não encontrado.'
            ];
        }
        
        // Gerar token de reset
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Remover tokens antigos
        $this->db->delete('password_resets', ['user_id' => $user['id']]);
        
        // Criar novo token
        $this->db->insert('password_resets', [
            'user_id' => $user['id'],
            'token' => $token,
            'expires_at' => $expires,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Aqui você enviaria o email com o link de reset
        return [
            'success' => true,
            'message' => 'Email de recuperação enviado.',
            'token' => $token // Remover em produção
        ];
    }
    
    /**
     * Confirmar reset de senha
     */
    public function confirmPasswordReset($token, $newPassword) {
        $reset = $this->db->selectOne('password_resets', ['token' => $token]);
        
        if (!$reset || strtotime($reset['expires_at']) < time()) {
            return [
                'success' => false,
                'message' => 'Token inválido ou expirado.'
            ];
        }
        
        // Atualizar senha
        $this->db->update('users', [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT)
        ], ['id' => $reset['user_id']]);
        
        // Remover token usado
        $this->db->delete('password_resets', ['token' => $token]);
        
        $this->logActivity($reset['user_id'], 'password_reset');
        
        return [
            'success' => true,
            'message' => 'Senha alterada com sucesso!'
        ];
    }
    
    /**
     * Funções privadas auxiliares
     */
    private function getUserByUsername($username) {
        // Buscar por username ou email
        $user = $this->db->selectOne('users', ['username' => $username]);
        if (!$user) {
            $user = $this->db->selectOne('users', ['email' => $username]);
        }
        return $user;
    }
    
    private function createSession($user) {
        $_SESSION[$this->sessionName] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'logged_at' => time(),
            'last_activity' => time()
        ];
        
        // Regenerar ID da sessão para segurança
        session_regenerate_id(true);
    }
    
    private function setRememberToken($userId) {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (30 * 24 * 60 * 60); // 30 dias
        
        // Salvar token no banco
        $this->db->update('users', [
            'remember_token' => $token
        ], ['id' => $userId]);
        
        // Criar cookie seguro
        setcookie(
            $this->cookieName,
            $token,
            $expires,
            '/',
            '',
            isset($_SERVER['HTTPS']), // HTTPS only se disponível
            true  // HTTP only
        );
    }
    
    private function checkRememberMe() {
        if (!$this->isLoggedIn() && isset($_COOKIE[$this->cookieName])) {
            $token = $_COOKIE[$this->cookieName];
            
            $user = $this->db->selectOne('users', [
                'remember_token' => $token,
                'status' => 'active'
            ]);
            
            if ($user) {
                $this->createSession($user);
                $this->updateLastLogin($user['id']);
            }
        }
    }
    
    private function removeRememberToken($userId) {
        $this->db->update('users', [
            'remember_token' => null
        ], ['id' => $userId]);
    }
    
    private function updateLastLogin($userId) {
        $this->db->update('users', [
            'last_login' => date('Y-m-d H:i:s')
        ], ['id' => $userId]);
    }
    
    private function isLockedOut($username) {
        $result = $this->db->raw(
            "SELECT COUNT(*) as attempts 
             FROM login_attempts 
             WHERE username = ? 
             AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$username, $this->lockoutTime]
        );
        
        $row = $result->fetch();
        return $row && $row['attempts'] >= $this->maxAttempts;
    }
    
    private function recordFailedAttempt($username) {
        $this->db->insert('login_attempts', [
            'username' => $username,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'attempted_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function clearFailedAttempts($username) {
        $this->db->delete('login_attempts', ['username' => $username]);
    }
    
    private function logActivity($userId, $action, $data = null) {
        $this->db->insert('user_activities', [
            'user_id' => $userId,
            'action' => $action,
            'data' => $data ? json_encode($data) : null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function getRolePermissions($role) {
        $permissions = [
            'admin' => ['*'], // Todas as permissões
            'manager' => [
                'view_campaigns', 'create_campaigns', 'edit_campaigns',
                'view_analytics', 'view_users'
            ],
            'user' => [
                'view_campaigns', 'view_analytics'
            ]
        ];
        
        return $permissions[$role] ?? [];
    }
    
    private function getSafeUserData($user) {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
    }
    
    /**
     * Gerenciamento de sessões
     */
    public function checkSessionTimeout() {
        if ($this->isLoggedIn()) {
            $sessionTimeout = 3600; // 1 hora
            $lastActivity = $_SESSION[$this->sessionName]['last_activity'] ?? time();
            
            if (time() - $lastActivity > $sessionTimeout) {
                $this->logout();
                return false;
            }
            
            $_SESSION[$this->sessionName]['last_activity'] = time();
        }
        
        return true;
    }
    
    /**
     * Lista todos os usuários
     */
    public function listUsers($page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        return $this->db->select(
            'users',
            [],
            'id, username, email, role, status, created_at, last_login',
            'created_at DESC',
            "$perPage OFFSET $offset"
        );
    }
    
    /**
     * Atualizar usuário
     */
    public function updateUser($userId, $data) {
        $allowedFields = ['email', 'role', 'status'];
        $updates = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[$field] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        return $this->db->update('users', $updates, ['id' => $userId]);
    }
    
    /**
     * Deletar usuário
     */
    public function deleteUser($userId) {
        // Não permitir deletar o próprio usuário
        if ($this->getCurrentUser()['id'] == $userId) {
            return false;
        }
        
        return $this->db->delete('users', ['id' => $userId]);
    }
}