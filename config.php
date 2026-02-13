<?php
/**
 * Configurações do Sistema
 */

// Configurações do MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'amazon_feed');
define('DB_USER', 'root'); // Alterar para seu usuário MySQL
define('DB_PASS', ''); // Alterar para sua senha MySQL

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Erros (desabilitar em produção)
error_reporting(E_ALL);
ini_set('display_errors', 1);
