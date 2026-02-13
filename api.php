<?php
/**
 * Amazon Feed Manager - API Endpoints para n8n
 * Retorna produtos com links de afiliado já gerados
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/AmazonAPI.php';

$db = new Database();
$amazon = new AmazonAPI($db);

$action = $_GET['action'] ?? 'products';
$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {
        case 'products':
            // Retorna produtos com links de afiliado
            $categoryId = $_GET['category_id'] ?? null;
            $limit = min(100, intval($_GET['limit'] ?? 50));
            
            $products = $db->getProducts($limit, $categoryId);
            
            $response = [
                'status' => 'success',
                'count' => count($products),
                'data' => $products
            ];
            break;
            
        case 'categories':
            // Lista categorias ativas
            $categories = $db->getCategories(true);
            
            $response = [
                'status' => 'success',
                'count' => count($categories),
                'data' => $categories
            ];
            break;
            
        case 'search':
            // Busca produtos por keyword
            $input = json_decode(file_get_contents('php://input'), true);
            $keyword = $input['keyword'] ?? $_POST['keyword'] ?? $_GET['keyword'] ?? '';
            
            if (empty($keyword)) {
                throw new Exception('Keyword é obrigatória');
            }
            
            $products = $amazon->searchByKeyword($keyword);
            
            $response = [
                'status' => 'success',
                'keyword' => $keyword,
                'count' => count($products),
                'data' => $products
            ];
            break;
            
        case 'product':
            // Retorna produto específico por ASIN
            $asin = $_GET['asin'] ?? '';
            
            if (empty($asin)) {
                throw new Exception('ASIN é obrigatório');
            }
            
            $product = $db->getProductByAsin($asin);
            
            if (!$product) {
                // Buscar na Amazon se não existir no DB
                $product = $amazon->getProductByAsin($asin);
            }
            
            $response = [
                'status' => 'success',
                'data' => $product
            ];
            break;
            
        case 'sync':
            // Força sincronização de uma categoria
            $categoryId = $_GET['category_id'] ?? $_POST['category_id'] ?? null;
            
            if (!$categoryId) {
                throw new Exception('category_id é obrigatório');
            }
            
            $products = $amazon->searchProducts($categoryId);
            
            $response = [
                'status' => 'success',
                'message' => 'Sincronização concluída',
                'products_synced' => count($products)
            ];
            break;
            
        case 'stats':
            // Retorna estatísticas
            $stats = $db->getStats();
            
            $response = [
                'status' => 'success',
                'data' => $stats
            ];
            break;
            
        default:
            throw new Exception('Ação não reconhecida: ' . $action);
    }
    
} catch (Exception $e) {
    $response = [
        'status' => 'error',
        'message' => $e->getMessage()
    ];
    http_response_code(400);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);