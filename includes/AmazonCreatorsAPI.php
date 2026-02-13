<?php
/**
 * Amazon Creators API - Implementação Oficial
 * Documentação: https://associados.amazon.com.br/creatorsapi/docs/
 */

class AmazonCreatorsAPI {
    private $credentialId;
    private $credentialSecret;
    private $version;
    private $associateTag;
    private $marketplace = 'www.amazon.com.br';
    private $region = 'us-east-1';
    private $debugMode = true;
    
    public function __construct($credentials) {
        $this->credentialId = $credentials['credential_id'] ?? '';
        $this->credentialSecret = $credentials['credential_secret'] ?? '';
        $this->version = $credentials['version'] ?? '2.1';
        $this->associateTag = $credentials['associate_tag'] ?? '';
        
        if (!empty($credentials['marketplace'])) {
            $this->marketplace = $credentials['marketplace'];
        }
    }
    
    /**
     * SearchItems - Buscar produtos por palavra-chave
     * https://associados.amazon.com.br/creatorsapi/docs/en-us/api-reference/operations/search-items
     */
    public function searchItems($params = []) {
        $endpoint = '/paapi5/searchitems';
        
        $payload = [
            'PartnerTag' => $this->associateTag,
            'PartnerType' => 'Associates',
            'Marketplace' => $this->marketplace,
            'Keywords' => $params['keywords'] ?? '',
            'ItemCount' => $params['itemCount'] ?? 10,
            'Resources' => [
                'Images.Primary.Large',
                'Images.Primary.Medium',
                'ItemInfo.Title',
                'ItemInfo.Features',
                'ItemInfo.ProductInfo',
                'Offers.Listings.Price',
                'Offers.Listings.Availability.Message',
                'Offers.Listings.SavingBasis'
            ]
        ];
        
        // Parâmetros opcionais
        if (!empty($params['browseNodeId'])) {
            $payload['BrowseNodeId'] = $params['browseNodeId'];
        }
        
        if (!empty($params['sortBy'])) {
            $payload['SortBy'] = $params['sortBy'];
        }
        
        if (!empty($params['minPrice'])) {
            $payload['MinPrice'] = $params['minPrice'];
        }
        
        if (!empty($params['maxPrice'])) {
            $payload['MaxPrice'] = $params['maxPrice'];
        }
        
        if (!empty($params['itemPage'])) {
            $payload['ItemPage'] = $params['itemPage'];
        }
        
        return $this->makeRequest('POST', $endpoint, $payload);
    }
    
    /**
     * GetItems - Obter detalhes de produtos por ASIN
     * https://associados.amazon.com.br/creatorsapi/docs/en-us/api-reference/operations/get-items
     */
    public function getItems($asins = []) {
        if (empty($asins)) {
            throw new Exception('ASINs são obrigatórios');
        }
        
        $endpoint = '/paapi5/getitems';
        
        $payload = [
            'PartnerTag' => $this->associateTag,
            'PartnerType' => 'Associates',
            'Marketplace' => $this->marketplace,
            'ItemIds' => is_array($asins) ? $asins : [$asins],
            'Resources' => [
                'Images.Primary.Large',
                'ItemInfo.Title',
                'ItemInfo.Features',
                'ItemInfo.ProductInfo',
                'Offers.Listings.Price',
                'Offers.Listings.Availability.Message'
            ]
        ];
        
        return $this->makeRequest('POST', $endpoint, $payload);
    }
    
    /**
     * GetBrowseNodes - Obter informações de categorias
     * https://associados.amazon.com.br/creatorsapi/docs/en-us/api-reference/operations/get-browse-nodes
     */
    public function getBrowseNodes($browseNodeIds = []) {
        if (empty($browseNodeIds)) {
            throw new Exception('BrowseNodeIds são obrigatórios');
        }
        
        $endpoint = '/paapi5/getbrowsenodes';
        
        $payload = [
            'PartnerTag' => $this->associateTag,
            'PartnerType' => 'Associates',
            'Marketplace' => $this->marketplace,
            'BrowseNodeIds' => is_array($browseNodeIds) ? $browseNodeIds : [$browseNodeIds],
            'Resources' => [
                'BrowseNodes.Ancestor',
                'BrowseNodes.Children'
            ]
        ];
        
        return $this->makeRequest('POST', $endpoint, $payload);
    }
    
    /**
     * Faz requisição para a API
     */
    private function makeRequest($method, $endpoint, $payload) {
        $host = $this->marketplace;
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        // Preparar corpo da requisição
        $requestBody = json_encode($payload);
        
        // Construir URL completa
        $url = "https://{$host}{$endpoint}";
        
        // Headers AWS Signature Version 4
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Encoding' => 'amz-1.0',
            'X-Amz-Date' => $timestamp,
            'X-Amz-Target' => 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1' . str_replace('/paapi5/', '', $endpoint),
            'Host' => $host
        ];
        
        // Gerar assinatura AWS4-HMAC-SHA256
        $signature = $this->generateSignature($method, $endpoint, $requestBody, $headers, $date);
        
        // Adicionar autorização no header
        $headers['Authorization'] = $signature;
        
        // Fazer requisição
        $ch = curl_init($url);
        
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $this->debug("Request URL: {$url}");
        $this->debug("Request Headers: " . json_encode($headers));
        $this->debug("Request Body: {$requestBody}");
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->debug("Response Code: {$httpCode}");
        $this->debug("Response Body: {$response}");
        
        if ($error) {
            throw new Exception("CURL Error: {$error}");
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMessage = $data['__type'] ?? 'Unknown error';
            $errorDetails = $data['Errors'][0]['Message'] ?? $response;
            throw new Exception("API Error [{$httpCode}]: {$errorMessage} - {$errorDetails}");
        }
        
        return $data;
    }
    
    /**
     * Gera assinatura AWS Signature Version 4
     * Documentação: https://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html
     */
    private function generateSignature($method, $endpoint, $body, $headers, $date) {
        $service = 'ProductAdvertisingAPI';
        
        // Task 1: Create canonical request
        $canonicalHeaders = [];
        $signedHeaders = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            $canonicalHeaders[$lowerKey] = trim($value);
            $signedHeaders[] = $lowerKey;
        }
        ksort($canonicalHeaders);
        sort($signedHeaders);
        
        $canonicalHeadersStr = '';
        foreach ($canonicalHeaders as $key => $value) {
            $canonicalHeadersStr .= "{$key}:{$value}\n";
        }
        
        $signedHeadersStr = implode(';', $signedHeaders);
        $hashedPayload = hash('sha256', $body);
        
        $canonicalRequest = implode("\n", [
            $method,
            $endpoint,
            '', // Query string (vazio para POST)
            $canonicalHeadersStr,
            $signedHeadersStr,
            $hashedPayload
        ]);
        
        // Task 2: Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$this->region}/{$service}/aws4_request";
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);
        
        $stringToSign = implode("\n", [
            $algorithm,
            $headers['X-Amz-Date'],
            $credentialScope,
            $hashedCanonicalRequest
        ]);
        
        // Task 3: Calculate signature
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->credentialSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        // Task 4: Add signature to request
        return "{$algorithm} Credential={$this->credentialId}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";
    }
    
    /**
     * Debug helper
     */
    private function debug($message) {
        if ($this->debugMode) {
            $logFile = __DIR__ . '/../cache/creators-api.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
        }
    }
}