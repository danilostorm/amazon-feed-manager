# Amazon Feed Manager API

API REST para busca de produtos Amazon, similar à Axesso API.

## Endpoints Disponíveis

### 1. Buscar Produtos por Keyword

```
GET /api/?endpoint=search&keyword={keyword}&page={page}&domainCode={domain}
```

**Parâmetros:**
- `keyword` (obrigatório): Palavra-chave de busca
- `page` (opcional): Número da página (padrão: 1)
- `sortBy` (opcional): Ordenação - `relevanceblender`, `price-asc`, `price-desc`, `review-rank`
- `domainCode` (opcional): Marketplace - `com.br`, `com` (padrão: com.br)
- `browseNode` (opcional): ID do nó de categoria

**Exemplo:**
```bash
curl "https://amz.hoststorm.cloud/api/?endpoint=search&keyword=notebook&page=1"
```

**Resposta:**
```json
{
    "responseStatus": "PRODUCT_FOUND_RESPONSE",
    "responseMessage": "Produtos encontrados com sucesso!",
    "sortStrategy": "relevanceblender",
    "domainCode": "com.br",
    "keyword": "notebook",
    "numberOfProducts": 20,
    "resultCount": 20,
    "page": 1,
    "foundProducts": ["B08N5WRWNW", "B09C123ABC", ...],
    "searchProductDetails": [
        {
            "asin": "B08N5WRWNW",
            "productDescription": "Notebook Dell Inspiron 15",
            "price": 2499.90,
            "retailPrice": 2999.00,
            "imgUrl": "https://m.media-amazon.com/images/I/71abc123.jpg",
            "productRating": "4.5 out of 5 stars",
            "countReview": 450,
            "prime": true,
            "dpUrl": "/dp/B08N5WRWNW",
            "affiliateUrl": "https://www.amazon.com.br/dp/B08N5WRWNW?tag=stormanimesbr-20"
        }
    ]
}
```

---

### 2. Obter Produto por ASIN

```
GET /api/?endpoint=product&asin={asin}
```

**Parâmetros:**
- `asin` (obrigatório): Amazon Standard Identification Number

**Exemplo:**
```bash
curl "https://amz.hoststorm.cloud/api/?endpoint=product&asin=B08N5WRWNW"
```

**Resposta:**
```json
{
    "responseStatus": "PRODUCT_FOUND_RESPONSE",
    "responseMessage": "Produto encontrado com sucesso!",
    "productDetails": {
        "asin": "B08N5WRWNW",
        "title": "Notebook Dell Inspiron 15",
        "price": 2499.90,
        "currency": "BRL",
        "imageUrl": "https://m.media-amazon.com/images/I/71abc123.jpg",
        "productUrl": "https://www.amazon.com.br/dp/B08N5WRWNW",
        "affiliateUrl": "https://www.amazon.com.br/dp/B08N5WRWNW?tag=stormanimesbr-20",
        "rating": "4.5 out of 5 stars",
        "availability": "Em estoque"
    }
}
```

---

### 3. Best Sellers

```
GET /api/?endpoint=bestsellers&limit={limit}
```

**Parâmetros:**
- `limit` (opcional): Número de produtos (padrão: 20)

**Exemplo:**
```bash
curl "https://amz.hoststorm.cloud/api/?endpoint=bestsellers&limit=10"
```

**Resposta:**
```json
{
    "responseStatus": "PRODUCT_FOUND_RESPONSE",
    "responseMessage": "Best sellers encontrados!",
    "countProducts": 10,
    "products": [
        {
            "asin": "B08N5WRWNW",
            "productTitle": "Notebook Dell Inspiron 15",
            "price": 2499.90,
            "imgUrl": "https://m.media-amazon.com/images/I/71abc123.jpg",
            "productRating": "4.5 out of 5 stars",
            "url": "https://www.amazon.com.br/dp/B08N5WRWNW",
            "affiliateUrl": "https://www.amazon.com.br/dp/B08N5WRWNW?tag=stormanimesbr-20"
        }
    ]
}
```

---

### 4. Listar Categorias

```
GET /api/?endpoint=categories
```

**Exemplo:**
```bash
curl "https://amz.hoststorm.cloud/api/?endpoint=categories"
```

**Resposta:**
```json
{
    "responseStatus": "SUCCESS",
    "responseMessage": "Categorias listadas com sucesso",
    "categories": [
        {
            "id": 1,
            "name": "Eletrônicos",
            "browseNodeId": "16243852011",
            "keywords": "notebook, laptop, computador",
            "isActive": true
        }
    ]
}
```

---

## Respostas de Erro

**404 - Endpoint não encontrado:**
```json
{
    "status": "error",
    "message": "Endpoint não encontrado"
}
```

**400 - Parâmetro ausente:**
```json
{
    "responseStatus": "PARAMETER_ERROR",
    "responseMessage": "Parâmetro \"keyword\" é obrigatório"
}
```

**500 - Erro interno:**
```json
{
    "status": "error",
    "message": "Mensagem de erro detalhada"
}
```

---

## Headers CORS

A API já vem configurada com CORS habilitado para aceitar requisições de qualquer origem:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type
```

---

## Uso com JavaScript

```javascript
// Buscar produtos
fetch('https://amz.hoststorm.cloud/api/?endpoint=search&keyword=notebook')
  .then(response => response.json())
  .then(data => {
    console.log(data.searchProductDetails);
  });

// Obter produto específico
fetch('https://amz.hoststorm.cloud/api/?endpoint=product&asin=B08N5WRWNW')
  .then(response => response.json())
  .then(data => {
    console.log(data.productDetails);
  });
```

---

## Uso com PHP

```php
<?php
// Buscar produtos
$response = file_get_contents('https://amz.hoststorm.cloud/api/?endpoint=search&keyword=notebook');
$data = json_decode($response, true);

foreach ($data['searchProductDetails'] as $product) {
    echo $product['productDescription'] . ' - R$ ' . $product['price'] . "\n";
}
?>
```

---

## Uso com Python

```python
import requests

# Buscar produtos
response = requests.get('https://amz.hoststorm.cloud/api/?endpoint=search&keyword=notebook')
data = response.json()

for product in data['searchProductDetails']:
    print(f"{product['productDescription']} - R$ {product['price']}")
```

---

## Limitações

- Scraper: até 20 produtos por busca
- Rate limit: não implementado (adicionar se necessário)
- Cache: produtos são salvos no banco SQLite

---

## Segurança

Para uso em produção, considere adicionar:
- API Key authentication
- Rate limiting
- IP whitelist
- HTTPS obrigatório
