<?php
/**
 * Amazon Product Advertising API 5.0 Handler + Scraper Fallback
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
        $category = $this->db->getCategory($categoryId);
        
        if (!$category) {
            throw new Exception('Categoria não encontrada');
        }
        
        $products = [];
        $keywords = array_filter(array_map('trim', explode(',', $category['keywords'])));
        
        if (empty($keywords)) {
            throw new Exception('Nenhuma keyword definida para esta categoria');
        }
        
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
     * Busca produtos por palavra-chave
     * Tenta PA-API primeiro, depois usa scraper
     */
    public function searchByKeyword($keyword, $browseNodeId = null) {
        // Se não tiver credenciais configuradas, vai direto pro scraper
        if (empty($this->credentials['access_key']) || empty($this->credentials['secret_key'])) {
            return $this->searchByKeywordScraper($keyword, $browseNodeId);
        }
        
        // Tentar PA-API primeiro
        try {
            $products = $this->searchByKeywordPAAPI($keyword, $browseNodeId);
            if (!empty($products)) {
                return $products;
            }
        } catch (Exception $e) {
            // Se falhar, usar scraper
        }
        
        // Fallback: Scraper
        return $this->searchByKeywordScraper($keyword, $browseNodeId);
    }
    
    /**
     * Busca usando PA-API 5.0 (requer aprovação Amazon)
     */
    private function searchByKeywordPAAPI($keyword, $browseNodeId = null) {
        $host = $this->credentials['marketplace'];
        $uri = '/paapi5/searchitems';
        
        $payload = [
            'Keywords' => $keyword,
            'Resources' => [
                'Images.Primary.Large',
                'ItemInfo.Title',
                'ItemInfo.Features',
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
        
        $response = $this->makeApiRequest($host, $uri, $payload);
        
        $products = [];
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
        
        return $products;
    }
    
    /**
     * Método Scraper - Funciona SEM precisar de PA-API aprovada
     * Baseado no método do workflow n8n do usuário
     */
    private function searchByKeywordScraper($keyword, $browseNodeId = null) {
        $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
        
        // Montar URL de busca
        if ($browseNodeId) {
            $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword) . "&rh=n:{$browseNodeId}";
        } else {
            $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword);
        }
        
        // Fazer requisição com headers corretos
        $html = $this->fetchUrl($url);
        
        if (empty($html)) {
            return [];
        }
        
        // Parse HTML para extrair produtos
        return $this->parseAmazonSearchResults($html);
    }
    
    /**
     * Faz requisição HTTP com headers adequados
     */
    private function fetchUrl($url) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Cache-Control: max-age=0'
            ],
            CURLOPT_ENCODING => 'gzip, deflate'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return '';
        }
        
        return $response;
    }
    
    /**
     * Parse HTML da página de resultados da Amazon
     * Baseado no código JavaScript do workflow n8n
     */
    private function parseAmazonSearchResults($html) {
        $products = [];
        
        // Regex para limpar títulos de anúncios patrocinados
        $sponsoredPattern = '/^Anúncio patrocinado\s?[–-]\s?/i';
        
        // Dividir HTML em blocos de produtos
        $blocks = preg_split('/data-component-type="s-search-result"/', $html);
        
        // Pular primeiro bloco (cabeçalho)
        array_shift($blocks);
        
        foreach ($blocks as $block) {
            $product = [];
            
            // Extrair ASIN
            if (preg_match('/data-asin="([^"]+)"/', $block, $matches)) {
                $product['asin'] = $matches[1];
            } else {
                continue; // Sem ASIN, pular
            }
            
            // Extrair Título (tentar primeiro do alt da imagem)
            if (preg_match('/<img[^>]*class="s-image"[^>]*alt="([^"]+)"/', $block, $matches)) {
                $product['title'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/class="a-size-(?:base-plus|medium) a-color-base a-text-normal">([^<]+)</', $block, $matches)) {
                $product['title'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            } else {
                $product['title'] = 'Produto ' . $product['asin'];
            }
            
            // Limpar título de anúncios patrocinados
            $product['title'] = preg_replace($sponsoredPattern, '', $product['title']);
            $product['title'] = trim($product['title']);
            
            // Extrair Link do produto
            if (preg_match('/class="a-link-normal s-(?:no-outline|underline-text)[^"]*"[^>]*href="([^"]+)"/', $block, $matches)) {
                $productPath = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
                $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
                $product['product_url'] = 'https://www.amazon.' . $marketplace . $productPath;
            }
            
            // Extrair Imagem
            if (preg_match('/<img[^>]*class="s-image"[^>]*src="([^"]+)"/', $block, $matches)) {
                $product['image_url'] = $matches[1];
            }
            
            // Extrair Preço (primeiro a-offscreen é geralmente o preço atual)
            if (preg_match('/<span class="a-price"[^>]*><span class="a-offscreen">([^<]+)</', $block, $matches)) {
                $priceText = $matches[1];
                // Remover R$ e limpar
                $priceText = preg_replace('/[^\d,.]/', '', $priceText);
                $priceText = str_replace(',', '.', $priceText);
                $product['price'] = $priceText;
            }
            
            // Extrair avaliação
            if (preg_match('/aria-label="([\d,]+) de 5 estrelas"/', $block, $matches)) {
                $product['rating'] = str_replace(',', '.', $matches[1]);
            }
            
            // Gerar link de afiliado
            if (!empty($product['asin'])) {
                $product['affiliate_url'] = $this->generateAffiliateUrl($product['asin']);
                $product['currency'] = 'BRL';
                $product['availability'] = 'Em estoque';
                
                $products[] = $product;
            }
            
            // Limitar a 20 produtos por busca
            if (count($products) >= 20) {
                break;
            }
        }
        
        return $products;
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
        
        // Se não existir, criar produto básico
        $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
        $product = [
            'asin' => $asin,
            'title' => 'Produto ' . $asin,
            'affiliate_url' => $this->generateAffiliateUrl($asin),
            'product_url' => "https://www.amazon.{$marketplace}/dp/{$asin}",
            'currency' => 'BRL'
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
        
        throw new Exception('PA-API 5.0 requer biblioteca oficial. Usando método scraper.');
    }
}