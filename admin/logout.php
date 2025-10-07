<?php
/**
 * Cloaker Pro - Logout
 * Script de logout do sistema
 */

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

// Criar instância de Auth e fazer logout
$auth = new Auth();
$auth->logout();

// Redirecionar para login
header('Location: login.php?logout=1');
exit;