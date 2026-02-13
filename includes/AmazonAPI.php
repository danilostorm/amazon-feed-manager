<?php
/**
 * Amazon Product Advertising API 5.0 Handler
 */

class AmazonAPI {
    private $db;
    private $credentials;
    
    public function __construct($db) {
        $this->db = $db;
        $this->credentials = $db->getCredentials();
    }
    
    /**
     * Gera link de afiliado com a tag configurada
     */
    public function generateAffiliateUrl($asin) {
        if (empty($this->credentials['associate_tag'])) {
            throw new Exception('Associate Tag não configurada');
        }
        
        $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
        return "https://www.amazon.{$marketplace}/dp/{$asin}?tag={$this->credentials['associate_tag']}";
    }
    
    /**
     * Busca produtos por categoria
     */
    public function searchProducts($categoryId) {
        $stmt = $this->db->db->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([':id' => $categoryId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            throw new Exception('Categoria não encontrada');
        }
        
        $products = [];
        $keywords = array_filter(array_map('trim', explode(',', $category['keywords'])));
        
        foreach ($keywords as $keyword) {
            $results = $this->searchByKeyword($keyword, $category['browse_node_id']);
            foreach ($results as $product) {
                $product['category_id'] = $categoryId;
                $this->db->saveProduct($product);
                $products[] = $product;
            }
        }
        
        $this->db->logSync($categoryId, count($products));
        
        return $products;
    }
    
    /**
     * Busca produtos por palavra-chave usando PA-API 5.0
     */
    public function searchByKeyword($keyword, $browseNodeId = null) {
        if (empty($this->credentials['access_key']) || empty($this->credentials['secret_key'])) {
            throw new Exception('Credenciais Amazon não configuradas');
        }
        
        // Preparar requisição para PA-API 5.0
        $host = $this->credentials['marketplace'];
        $region = strpos($host, '.br') !== false ? 'us-east-1' : 'us-east-1';
        $uri = '/paapi5/searchitems';
        
        $payload = [
            'Keywords' => $keyword,
            'Resources' => [
                'Images.Primary.Large',
                'ItemInfo.Title',
                'ItemInfo.Features',
                'ItemInfo.ByLineInfo',
                'Offers.Listings.Price',
                'Offers.Listings.Availability.Message'
            ],
            'PartnerTag' => $this->credentials['associate_tag'],
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.amazon.' . (strpos($host, '.br') !== false ? 'com.br' : 'com')
        ];
        
        if ($browseNodeId) {
            $payload['BrowseNodeId'] = $browseNodeId;
        }
        
        // Fazer requisição (simplificado - em produção usar biblioteca oficial)
        $products = [];
        
        try {
            $response = $this->makeApiRequest($host, $uri, $payload);
            
            if (isset($response['SearchResult']['Items'])) {
                foreach ($response['SearchResult']['Items'] as $item) {
                    $asin = $item['ASIN'];
                    $product = [
                        'asin' => $asin,
                        'title' => $item['ItemInfo']['Title']['DisplayValue'] ?? '',
                        'price' => $item['Offers']['Listings'][0]['Price']['Amount'] ?? null,
                        'currency' => $item['Offers']['Listings'][0]['Price']['Currency'] ?? 'BRL',
                        'image_url' => $item['Images']['Primary']['Large']['URL'] ?? null,
                        'product_url' => $item['DetailPageURL'] ?? null,
                        'affiliate_url' => $this->generateAffiliateUrl($asin),
                        'features' => isset($item['ItemInfo']['Features']['DisplayValues']) 
                            ? json_encode($item['ItemInfo']['Features']['DisplayValues']) 
                            : null,
                        'availability' => $item['Offers']['Listings'][0]['Availability']['Message'] ?? null
                    ];
                    
                    $products[] = $product;
                }
            }
        } catch (Exception $e) {
            // Se PA-API falhar, usar método alternativo (scraping simplificado)
            $products = $this->searchByKeywordFallback($keyword);
        }
        
        return $products;
    }
    
    /**
     * Método alternativo sem PA-API (para testes ou quando não tiver API aprovada)
     */
    private function searchByKeywordFallback($keyword) {
        $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
        $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword);
        
        // Simula busca (em produção, fazer scraping real ou usar dados mockados)
        // Por enquanto retorna array vazio para não dar erro
        return [];
    }
    
    /**
     * Busca produto específico por ASIN
     */
    public function getProductByAsin($asin) {
        // Verificar se existe no DB primeiro
        $product = $this->db->getProductByAsin($asin);
        
        if ($product) {
            return $product;
        }
        
        // Se não existir, buscar na API e salvar
        $product = [
            'asin' => $asin,
            'title' => 'Produto ' . $asin,
            'affiliate_url' => $this->generateAffiliateUrl($asin),
            'product_url' => "https://www.amazon.com.br/dp/{$asin}"
        ];
        
        $this->db->saveProduct($product);
        
        return $product;
    }
    
    /**
     * Faz requisição assinada para PA-API 5.0
     */
    private function makeApiRequest($host, $uri, $payload) {
        // Implementação simplificada
        // Em produção, usar a biblioteca oficial da Amazon:
        // https://github.com/thewirecutter/paapi5-php-sdk
        
        throw new Exception('PA-API 5.0 requer biblioteca oficial. Use método alternativo ou instale SDK.');
    }
}