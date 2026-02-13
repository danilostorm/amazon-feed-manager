<?php
/**
 * Amazon API Handler - Integra Creators API + Scraper Fallback
 */

require_once __DIR__ . '/AmazonCreatorsAPI.php';

class AmazonAPI {
    private $db;
    private $credentials;
    private $creatorsAPI;
    private $debugMode = true;
    
    public function __construct($db) {
        $this->db = $db;
        $this->credentials = $db->getCredentials();
        
        // Inicializar Creators API se credenciais estiverem configuradas
        if (!empty($this->credentials['credential_id']) && !empty($this->credentials['credential_secret'])) {
            $this->creatorsAPI = new AmazonCreatorsAPI($this->credentials);
        }
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
     * Tenta Creators API primeiro, depois usa scraper
     */
    public function searchByKeyword($keyword, $browseNodeId = null) {
        // Tentar Creators API se disponível
        if ($this->creatorsAPI) {
            try {
                $this->debug("Tentando Creators API para: {$keyword}");
                $response = $this->creatorsAPI->searchItems([
                    'keywords' => $keyword,
                    'browseNodeId' => $browseNodeId,
                    'itemCount' => 10
                ]);
                
                $products = $this->parseCreatorsAPIResponse($response);
                
                if (!empty($products)) {
                    $this->debug("Creators API retornou " . count($products) . " produtos");
                    return $products;
                }
            } catch (Exception $e) {
                $this->debug("Creators API falhou: " . $e->getMessage());
            }
        }
        
        // Fallback: Scraper
        $this->debug("Usando scraper para: {$keyword}");
        return $this->searchByKeywordScraper($keyword, $browseNodeId);
    }
    
    /**
     * Parse resposta da Creators API
     */
    private function parseCreatorsAPIResponse($response) {
        $products = [];
        
        if (!isset($response['SearchResult']['Items'])) {
            return $products;
        }
        
        foreach ($response['SearchResult']['Items'] as $item) {
            $asin = $item['ASIN'];
            
            $product = [
                'asin' => $asin,
                'title' => $item['ItemInfo']['Title']['DisplayValue'] ?? 'Produto ' . $asin,
                'price' => null,
                'currency' => 'BRL',
                'image_url' => $item['Images']['Primary']['Large']['URL'] ?? ($item['Images']['Primary']['Medium']['URL'] ?? null),
                'product_url' => $item['DetailPageURL'] ?? null,
                'affiliate_url' => $this->generateAffiliateUrl($asin),
                'features' => null,
                'availability' => null,
                'rating' => null
            ];
            
            // Extrair preço
            if (isset($item['Offers']['Listings'][0]['Price'])) {
                $price = $item['Offers']['Listings'][0]['Price'];
                $product['price'] = $price['Amount'] ?? null;
                $product['currency'] = $price['Currency'] ?? 'BRL';
            }
            
            // Extrair disponibilidade
            if (isset($item['Offers']['Listings'][0]['Availability']['Message'])) {
                $product['availability'] = $item['Offers']['Listings'][0]['Availability']['Message'];
            }
            
            // Extrair features
            if (isset($item['ItemInfo']['Features']['DisplayValues'])) {
                $product['features'] = json_encode($item['ItemInfo']['Features']['DisplayValues']);
            }
            
            $products[] = $product;
        }
        
        return $products;
    }
    
    /**
     * Método Scraper (Fallback)
     */
    private function searchByKeywordScraper($keyword, $browseNodeId = null) {
        $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
        
        if ($browseNodeId) {
            $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword) . "&rh=n:{$browseNodeId}";
        } else {
            $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword);
        }
        
        $this->debug("Fetching URL: {$url}");
        
        $html = $this->fetchUrl($url);
        
        if (empty($html)) {
            $this->debug("Empty HTML response");
            return [];
        }
        
        $products = $this->parseAmazonSearchResults($html);
        
        if (empty($products)) {
            $products = $this->parseAmazonSearchResultsAlternative($html);
        }
        
        $this->debug("Scraper encontrou " . count($products) . " produtos");
        
        return $products;
    }
    
    private function fetchUrl($url) {
        usleep(rand(500000, 1500000));
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive'
            ],
            CURLOPT_ENCODING => 'gzip, deflate'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200) ? $response : '';
    }
    
    private function parseAmazonSearchResults($html) {
        $products = [];
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        $productDivs = $xpath->query("//div[@data-component-type='s-search-result']");
        
        foreach ($productDivs as $div) {
            $asin = $div->getAttribute('data-asin');
            if (empty($asin)) continue;
            
            $product = ['asin' => $asin];
            
            $titleNodes = $xpath->query(".//h2//span", $div);
            $product['title'] = ($titleNodes->length > 0) ? trim($titleNodes->item(0)->textContent) : 'Produto ' . $asin;
            
            $priceNodes = $xpath->query(".//span[@class='a-price']//span[@class='a-offscreen']", $div);
            if ($priceNodes->length > 0) {
                $priceText = preg_replace('/[^\d,.]/', '', $priceNodes->item(0)->textContent);
                $product['price'] = str_replace(',', '.', $priceText);
            }
            
            $imgNodes = $xpath->query(".//img[@class='s-image']", $div);
            if ($imgNodes->length > 0) {
                $product['image_url'] = $imgNodes->item(0)->getAttribute('src');
            }
            
            $linkNodes = $xpath->query(".//h2//a", $div);
            if ($linkNodes->length > 0) {
                $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
                $product['product_url'] = 'https://www.amazon.' . $marketplace . $linkNodes->item(0)->getAttribute('href');
            }
            
            $product['affiliate_url'] = $this->generateAffiliateUrl($asin);
            $product['currency'] = 'BRL';
            $product['availability'] = 'Em estoque';
            
            $products[] = $product;
            if (count($products) >= 20) break;
        }
        
        return $products;
    }
    
    private function parseAmazonSearchResultsAlternative($html) {
        $products = [];
        $blocks = preg_split('/data-asin="([A-Z0-9]{10})"/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        for ($i = 1; $i < count($blocks); $i += 2) {
            if (!isset($blocks[$i]) || !isset($blocks[$i + 1])) continue;
            $asin = $blocks[$i];
            $block = $blocks[$i + 1];
            if (strlen($asin) !== 10) continue;
            
            $product = ['asin' => $asin, 'title' => 'Produto ' . $asin];
            
            if (preg_match('/<span[^>]*>([^<]+)</', $block, $m)) {
                $product['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/<span class="a-offscreen">([^<]+)</', $block, $m)) {
                $product['price'] = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $m[1]));
            }
            if (preg_match('/<img[^>]*src="([^"]+)"/', $block, $m)) {
                $product['image_url'] = $m[1];
            }
            
            $product['affiliate_url'] = $this->generateAffiliateUrl($asin);
            $product['currency'] = 'BRL';
            $products[] = $product;
            if (count($products) >= 20) break;
        }
        
        return $products;
    }
    
    public function getProductByAsin($asin) {
        $product = $this->db->getProductByAsin($asin);
        if ($product) return $product;
        
        if ($this->creatorsAPI) {
            try {
                $response = $this->creatorsAPI->getItems([$asin]);
                $products = $this->parseCreatorsAPIResponse($response);
                if (!empty($products)) {
                    $this->db->saveProduct($products[0]);
                    return $products[0];
                }
            } catch (Exception $e) {
                $this->debug("GetItems falhou: " . $e->getMessage());
            }
        }
        
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
    
    private function debug($message) {
        if ($this->debugMode) {
            $logFile = __DIR__ . '/../cache/amazon-api.log';
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] {$message}\n", FILE_APPEND);
        }
    }
}