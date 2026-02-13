<?php
/**
 * Script de Debug para testar o scraper
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/AmazonAPI.php';

// Inicializar
$db = new Database();
$amazonAPI = new AmazonAPI($db);

echo "=== Amazon Scraper Debug ===\n\n";

// Testar busca
$keyword = $_GET['q'] ?? 'notebook';
echo "Buscando por: {$keyword}\n";
echo "-----------------------------\n\n";

$products = $amazonAPI->searchByKeyword($keyword);

echo "Produtos encontrados: " . count($products) . "\n\n";

if (!empty($products)) {
    foreach ($products as $i => $product) {
        echo "Produto #" . ($i + 1) . ":\n";
        echo "  ASIN: {$product['asin']}\n";
        echo "  Título: {$product['title']}\n";
        echo "  Preço: R$ {$product['price']}\n";
        echo "  Link: {$product['affiliate_url']}\n";
        echo "\n";
    }
} else {
    echo "\nNENHUM PRODUTO ENCONTRADO!\n\n";
    echo "Verifique os logs em: cache/scraper.log\n";
    echo "HTML salvo em: cache/last_response.html\n";
}

echo "\n=== Últimas linhas do log ===\n";
if (file_exists(__DIR__ . '/cache/scraper.log')) {
    $log = file_get_contents(__DIR__ . '/cache/scraper.log');
    $lines = explode("\n", $log);
    $lastLines = array_slice($lines, -10);
    echo implode("\n", $lastLines);
}
