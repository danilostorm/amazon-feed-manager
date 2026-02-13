<?php
/**
 * Amazon Feed Manager API
 * REST API similar à Axesso para busca de produtos Amazon
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AmazonAPI.php';

// Inicializar
$db = new Database();
$amazonAPI = new AmazonAPI($db);

// Roteamento simples
$endpoint = $_GET['endpoint'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($endpoint) {
        case 'search':
            handleSearch($amazonAPI, $db);
            break;
            
        case 'product':
            handleProduct($amazonAPI);
            break;
            
        case 'bestsellers':
            handleBestSellers($db);
            break;
            
        case 'categories':
            handleCategories($db);
            break;
            
        default:
            response([
                'status' => 'error',
                'message' => 'Endpoint não encontrado',
                'endpoints' => [
                    'search' => 'Buscar produtos por keyword',
                    'product' => 'Obter detalhes de um produto por ASIN',
                    'bestsellers' => 'Listar produtos mais vendidos',
                    'categories' => 'Listar categorias disponíveis'
                ]
            ], 404);
    }
} catch (Exception $e) {
    response([
        'status' => 'error',
        'message' => $e->getMessage()
    ], 500);
}

/**
 * Endpoint: Buscar produtos por keyword
 * GET /api/?endpoint=search&keyword=notebook&page=1&sortBy=price-asc&domainCode=com.br
 */
function handleSearch($amazonAPI, $db) {
    $keyword = $_GET['keyword'] ?? '';
    $page = intval($_GET['page'] ?? 1);
    $sortBy = $_GET['sortBy'] ?? 'relevanceblender';
    $domainCode = $_GET['domainCode'] ?? 'com.br';
    $category = $_GET['category'] ?? null;
    $browseNode = $_GET['browseNode'] ?? null;
    
    if (empty($keyword)) {
        response([
            'responseStatus' => 'PARAMETER_ERROR',
            'responseMessage' => 'Parâmetro "keyword" é obrigatório'
        ], 400);
        return;
    }
    
    // Buscar produtos
    $products = $amazonAPI->searchByKeyword($keyword, $browseNode);
    
    // Formatar resposta similar à Axesso
    $searchProductDetails = [];
    foreach ($products as $product) {
        $searchProductDetails[] = [
            'asin' => $product['asin'],
            'productDescription' => $product['title'],
            'price' => floatval($product['price'] ?? 0),
            'retailPrice' => floatval($product['price'] ?? 0),
            'imgUrl' => $product['image_url'] ?? '',
            'productRating' => $product['rating'] ?? null,
            'countReview' => 0,
            'prime' => true,
            'dpUrl' => $product['product_url'] ?? '',
            'affiliateUrl' => $product['affiliate_url'] ?? ''
        ];
    }
    
    response([
        'responseStatus' => 'PRODUCT_FOUND_RESPONSE',
        'responseMessage' => 'Produtos encontrados com sucesso!',
        'sortStrategy' => $sortBy,
        'domainCode' => $domainCode,
        'keyword' => $keyword,
        'numberOfProducts' => count($searchProductDetails),
        'resultCount' => count($searchProductDetails),
        'page' => $page,
        'foundProducts' => array_column($searchProductDetails, 'asin'),
        'searchProductDetails' => $searchProductDetails
    ]);
}

/**
 * Endpoint: Obter produto por ASIN
 * GET /api/?endpoint=product&asin=B08N5WRWNW
 */
function handleProduct($amazonAPI) {
    $asin = $_GET['asin'] ?? '';
    
    if (empty($asin)) {
        response([
            'responseStatus' => 'PARAMETER_ERROR',
            'responseMessage' => 'Parâmetro "asin" é obrigatório'
        ], 400);
        return;
    }
    
    $product = $amazonAPI->getProductByAsin($asin);
    
    if (!$product) {
        response([
            'responseStatus' => 'PRODUCT_NOT_FOUND',
            'responseMessage' => 'Produto não encontrado'
        ], 404);
        return;
    }
    
    response([
        'responseStatus' => 'PRODUCT_FOUND_RESPONSE',
        'responseMessage' => 'Produto encontrado com sucesso!',
        'productDetails' => [
            'asin' => $product['asin'],
            'title' => $product['title'],
            'price' => floatval($product['price'] ?? 0),
            'currency' => $product['currency'] ?? 'BRL',
            'imageUrl' => $product['image_url'] ?? '',
            'productUrl' => $product['product_url'] ?? '',
            'affiliateUrl' => $product['affiliate_url'] ?? '',
            'rating' => $product['rating'] ?? null,
            'availability' => $product['availability'] ?? 'Em estoque'
        ]
    ]);
}

/**
 * Endpoint: Best Sellers
 * GET /api/?endpoint=bestsellers&limit=20
 */
function handleBestSellers($db) {
    $limit = intval($_GET['limit'] ?? 20);
    
    $stmt = $db->getPdo()->prepare("
        SELECT * FROM products 
        ORDER BY updated_at DESC 
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
    
    $productList = [];
    foreach ($products as $product) {
        $productList[] = [
            'asin' => $product['asin'],
            'productTitle' => $product['title'],
            'price' => floatval($product['price'] ?? 0),
            'imgUrl' => $product['image_url'] ?? '',
            'productRating' => $product['rating'] ?? null,
            'url' => $product['product_url'] ?? '',
            'affiliateUrl' => $product['affiliate_url'] ?? ''
        ];
    }
    
    response([
        'responseStatus' => 'PRODUCT_FOUND_RESPONSE',
        'responseMessage' => 'Best sellers encontrados!',
        'countProducts' => count($productList),
        'products' => $productList
    ]);
}

/**
 * Endpoint: Listar categorias
 * GET /api/?endpoint=categories
 */
function handleCategories($db) {
    $stmt = $db->getPdo()->query("SELECT * FROM categories ORDER BY name");
    $categories = $stmt->fetchAll();
    
    $categoryList = [];
    foreach ($categories as $category) {
        $categoryList[] = [
            'id' => $category['id'],
            'name' => $category['name'],
            'browseNodeId' => $category['browse_node_id'],
            'keywords' => $category['keywords'],
            'isActive' => (bool)$category['is_active']
        ];
    }
    
    response([
        'responseStatus' => 'SUCCESS',
        'responseMessage' => 'Categorias listadas com sucesso',
        'categories' => $categoryList
    ]);
}

/**
 * Helper para enviar resposta JSON
 */
function response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
