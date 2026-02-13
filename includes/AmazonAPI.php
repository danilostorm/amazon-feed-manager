<?php
/**
 * Amazon Product Advertising API 5.0 Handler + Scraper Fallback
 */

class AmazonAPI {
    private $db;
    private $credentials;
    private $debugMode = true; // Ativar logs
    
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
     * Múltiplos métodos de parse para maior compatibilidade
     */
    private function searchByKeywordScraper($keyword, $browseNodeId = null) {
        $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
        
        // Montar URL de busca
        if ($browseNodeId) {
            $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword) . "&rh=n:{$browseNodeId}";
        } else {
            $url = "https://www.amazon.{$marketplace}/s?k=" . urlencode($keyword);
        }
        
        $this->debug("Fetching URL: {$url}");
        
        // Fazer requisição com headers corretos
        $html = $this->fetchUrl($url);
        
        if (empty($html)) {
            $this->debug("Empty HTML response");
            return [];
        }
        
        $this->debug("HTML length: " . strlen($html) . " chars");
        
        // Salvar HTML para debug
        if ($this->debugMode) {
            file_put_contents(__DIR__ . '/../cache/last_response.html', $html);
        }
        
        // Tentar múltiplos métodos de parse
        $products = $this->parseAmazonSearchResults($html);
        
        if (empty($products)) {
            $this->debug("First parse failed, trying alternative method");
            $products = $this->parseAmazonSearchResultsAlternative($html);
        }
        
        $this->debug("Found " . count($products) . " products");
        
        return $products;
    }
    
    /**
     * Faz requisição HTTP com headers adequados
     */
    private function fetchUrl($url) {
        $ch = curl_init();
        
        // Adicionar delay aleatório para parecer humano
        usleep(rand(500000, 1500000)); // 0.5 a 1.5 segundos
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Cache-Control: max-age=0',
                'DNT: 1'
            ],
            CURLOPT_ENCODING => 'gzip, deflate'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->debug("CURL Error: {$error}");
        }
        
        $this->debug("HTTP Code: {$httpCode}");
        
        if ($httpCode !== 200) {
            return '';
        }
        
        return $response;
    }
    
    /**
     * Parse HTML da página de resultados da Amazon (Método 1)
     */
    private function parseAmazonSearchResults($html) {
        $products = [];
        
        // Usar DOMDocument para parse mais robusto
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        // Procurar por divs de produto
        $productDivs = $xpath->query("//div[@data-component-type='s-search-result']");
        
        $this->debug("Found {$productDivs->length} product divs with XPath");
        
        foreach ($productDivs as $div) {
            $product = [];
            
            // Extrair ASIN
            $asin = $div->getAttribute('data-asin');
            if (empty($asin)) continue;
            
            $product['asin'] = $asin;
            
            // Extrair título
            $titleNodes = $xpath->query(".//h2//span", $div);
            if ($titleNodes->length > 0) {
                $product['title'] = trim($titleNodes->item(0)->textContent);
            } else {
                $product['title'] = 'Produto ' . $asin;
            }
            
            // Extrair preço
            $priceNodes = $xpath->query(".//span[@class='a-price']//span[@class='a-offscreen']", $div);
            if ($priceNodes->length > 0) {
                $priceText = $priceNodes->item(0)->textContent;
                $priceText = preg_replace('/[^\d,.]/', '', $priceText);
                $product['price'] = str_replace(',', '.', $priceText);
            }
            
            // Extrair imagem
            $imgNodes = $xpath->query(".//img[@class='s-image']", $div);
            if ($imgNodes->length > 0) {
                $product['image_url'] = $imgNodes->item(0)->getAttribute('src');
            }
            
            // Extrair link
            $linkNodes = $xpath->query(".//h2//a", $div);
            if ($linkNodes->length > 0) {
                $href = $linkNodes->item(0)->getAttribute('href');
                $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
                $product['product_url'] = 'https://www.amazon.' . $marketplace . $href;
            }
            
            // Extrair rating
            $ratingNodes = $xpath->query(".//span[contains(@class, 'a-icon-alt')]", $div);
            if ($ratingNodes->length > 0) {
                $product['rating'] = $ratingNodes->item(0)->textContent;
            }
            
            // Gerar affiliate URL
            $product['affiliate_url'] = $this->generateAffiliateUrl($asin);
            $product['currency'] = 'BRL';
            $product['availability'] = 'Em estoque';
            
            $products[] = $product;
            
            if (count($products) >= 20) break;
        }
        
        return $products;
    }
    
    /**
     * Parse HTML - Método Alternativo (Regex)
     */
    private function parseAmazonSearchResultsAlternative($html) {
        $products = [];
        
        // Regex para limpar títulos
        $sponsoredPattern = '/^Anúncio patrocinado\s?[–-]\s?/i';
        
        // Dividir HTML em blocos de produtos
        $blocks = preg_split('/data-asin="([A-Z0-9]{10})"/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        $this->debug("Found " . (count($blocks) - 1) . " blocks with regex");
        
        for ($i = 1; $i < count($blocks); $i += 2) {
            if (!isset($blocks[$i]) || !isset($blocks[$i + 1])) continue;
            
            $asin = $blocks[$i];
            $block = $blocks[$i + 1];
            
            if (empty($asin) || strlen($asin) !== 10) continue;
            
            $product = ['asin' => $asin];
            
            // Extrair Título
            if (preg_match('/<span[^>]*class="[^"]*a-size-(?:medium|base-plus)[^"]*"[^>]*>([^<]+)</', $block, $matches)) {
                $product['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<h2[^>]*>.*?<span[^>]*>([^<]+)</', $block, $matches)) {
                $product['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            } else {
                $product['title'] = 'Produto ' . $asin;
            }
            
            // Limpar título
            $product['title'] = preg_replace($sponsoredPattern, '', $product['title']);
            $product['title'] = trim($product['title']);
            
            // Extrair Preço
            if (preg_match('/<span class="a-offscreen">([^<]+)</', $block, $matches)) {
                $priceText = $matches[1];
                $priceText = preg_replace('/[^\d,.]/', '', $priceText);
                $product['price'] = str_replace(',', '.', $priceText);
            }
            
            // Extrair Imagem
            if (preg_match('/<img[^>]*class="s-image"[^>]*src="([^"]+)"/', $block, $matches)) {
                $product['image_url'] = $matches[1];
            }
            
            // Extrair Link
            if (preg_match('/<a[^>]*class="[^"]*s-(?:no-outline|underline-text)[^"]*"[^>]*href="([^"]+)"/', $block, $matches)) {
                $marketplace = strpos($this->credentials['marketplace'], '.br') !== false ? 'com.br' : 'com';
                $product['product_url'] = 'https://www.amazon.' . $marketplace . html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            }
            
            // Extrair Rating
            if (preg_match('/([\d,]+)\s+de\s+5\s+estrelas/', $block, $matches)) {
                $product['rating'] = str_replace(',', '.', $matches[1]) . ' de 5 estrelas';
            }
            
            // Gerar affiliate URL
            $product['affiliate_url'] = $this->generateAffiliateUrl($asin);
            $product['currency'] = 'BRL';
            $product['availability'] = 'Em estoque';
            
            $products[] = $product;
            
            if (count($products) >= 20) break;
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
     * Debug helper
     */
    private function debug($message) {
        if ($this->debugMode) {
            $logFile = __DIR__ . '/../cache/scraper.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
        }
    }
    
    /**
     * Faz requisição assinada para PA-API 5.0
     */
    private function makeApiRequest($host, $uri, $payload) {
        throw new Exception('PA-API 5.0 requer biblioteca oficial. Usando método scraper.');
    }
}