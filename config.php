<?php
/**
 * Amazon Feed Manager - Configurações
 */

define('DB_FILE', __DIR__ . '/data/amazon_feed.db');
define('CACHE_DIR', __DIR__ . '/cache/');
define('CACHE_TIME', 3600); // 1 hora

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Criar diretórios necessários
if (!file_exists(dirname(DB_FILE))) {
    mkdir(dirname(DB_FILE), 0755, true);
}

if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// Error reporting (desativar em produção)
error_reporting(E_ALL);
ini_set('display_errors', 1);