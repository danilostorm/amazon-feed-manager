# 🛍️ Amazon Feed Manager

Sistema PHP completo para gerenciar feeds de produtos Amazon com **geração automática de links de afiliado**, similar ao Content Egg Pro.

## ✨ Funcionalidades

- ✅ **Painel Admin Intuitivo** - Interface completa para gerenciar tudo
- ✅ **Seleção de Categorias** - Escolha quais categorias da Amazon você quer buscar
- ✅ **Geração Automática de Links** - Todos os links já vêm com sua tag de afiliado
- ✅ **API REST para n8n** - Endpoints prontos para integração
- ✅ **Sincronização Automática** - Atualiza preços e disponibilidade
- ✅ **Busca por Keywords** - Busque produtos por palavras-chave
- ✅ **Browse Node ID** - Suporte para categorias específicas da Amazon
- ✅ **Banco SQLite** - Não precisa MySQL, funciona direto

## 🚀 Instalação

### Requisitos
- PHP 7.4+
- Extensão PDO SQLite
- Apache com mod_rewrite (ou Nginx)

### Passo a Passo

1. **Clone ou faça upload dos arquivos para seu servidor**
```bash
git clone https://github.com/danilostorm/amazon-feed-manager.git
cd amazon-feed-manager
```

2. **Configure permissões**
```bash
chmod 755 .
chmod 777 data/
chmod 777 cache/
```

3. **Acesse o painel admin**
```
http://seusite.com/
```

4. **Configure suas credenciais Amazon**
   - Vá na aba "Credenciais Amazon"
   - Insira sua Associate Tag (ex: `stormanimesbr-20`)
   - Insira Access Key e Secret Key da PA-API 5.0
   - Salve

5. **Adicione Categorias**
   - Vá na aba "Categorias"
   - Adicione nome da categoria
   - Opcional: Browse Node ID
   - Adicione keywords separadas por vírgula
   - Clique em "Adicionar Categoria"

6. **Sincronize Produtos**
   - Clique em "🔄 Sincronizar" na categoria desejada
   - Produtos serão buscados e salvos com links de afiliado

## 📡 Integração com n8n

### Endpoints Disponíveis

#### 1. Listar todos os produtos
```
GET /api.php?action=products
```

Retorna todos os produtos com links de afiliado já gerados.

**Resposta:**
```json
{
  "status": "success",
  "count": 50,
  "data": [
    {
      "asin": "B0CLSSFG6J",
      "title": "Ar Condicionado Electrolux Split 9.000 BTUs",
      "price": "1299.90",
      "image_url": "https://m.media-amazon.com/images/I/...",
      "affiliate_url": "https://www.amazon.com.br/dp/B0CLSSFG6J?tag=stormanimesbr-20"
    }
  ]
}
```

#### 2. Produtos por categoria
```
GET /api.php?action=products&category_id=1
```

#### 3. Buscar produtos por keyword
```
POST /api.php?action=search
Content-Type: application/json

{"keyword": "notebook gamer"}
```

#### 4. Produto específico por ASIN
```
GET /api.php?action=product&asin=B0CLSSFG6J
```

#### 5. Listar categorias
```
GET /api.php?action=categories
```

### Exemplo n8n Workflow

```javascript
// Node: HTTP Request
{
  "method": "GET",
  "url": "https://seusite.com/api.php",
  "qs": {
    "action": "products",
    "category_id": "1"
  },
  "authentication": "none"
}

// Os produtos já vêm com affiliate_url pronto para usar!
```

## 🔑 Como obter credenciais Amazon

### 1. Associate Tag (Partner Tag)
- Acesse: https://associados.amazon.com.br/
- Faça login ou cadastre-se
- Sua tag estará no formato: `seusite-20`

### 2. PA-API 5.0 Credentials
- Você precisa de **3 vendas qualificadas** primeiro
- Depois acesse: https://webservices.amazon.com/paapi5/documentation/
- Gere suas credenciais:
  - **Access Key** (Credential ID)
  - **Secret Key** (Credential Secret)

### Alternativa sem PA-API
O sistema inclui método fallback que funciona sem PA-API (útil para testes).

## 📂 Estrutura de Arquivos

```
amazon-feed-manager/
├── index.php              # Painel Admin
├── api.php                # Endpoints REST
├── config.php             # Configurações
├── includes/
│   ├── Database.php       # Handler SQLite
│   └── AmazonAPI.php      # Integração Amazon
├── assets/
│   └── style.css          # Estilos do admin
├── data/
│   └── amazon_feed.db     # Banco SQLite (criado automaticamente)
├── cache/                 # Cache de requisições
└── .htaccess              # Configuração Apache
```

## 🎯 Browse Node IDs Populares (Brasil)

```
Eletrônicos: 16243890011
Computadores: 16364456011
Celulares: 16242300011
Livros: 6740748011
Esportes: 16243649011
Casa e Cozinha: 16242360011
Ferramentas: 16364459011
Brinquedos: 16242366011
```

## 🔧 Configurações Avançadas

### config.php
```php
define('CACHE_TIME', 3600);  // Tempo de cache em segundos
date_default_timezone_set('America/Sao_Paulo');
```

### Limite de produtos por requisição
Edite `api.php` linha 22:
```php
$limit = min(100, intval($_GET['limit'] ?? 50));
```

## 💡 Dicas de Uso

1. **Keywords efetivas**: Use termos específicos como "notebook gamer i7" ao invés de apenas "notebook"
2. **Browse Node + Keywords**: Combine Node ID com keywords para resultados mais precisos
3. **Sincronização regular**: Configure cron job para sincronizar diariamente
4. **Cache**: O sistema mantém cache de 1 hora para evitar requisições excessivas

## 🤝 Integrando com seu workflow n8n existente

Baseado no seu workflow `Ofertas-Amazon-7.json`, você pode:

1. **Substituir busca manual** por chamada à API:
```javascript
// Ao invés de fazer scraping da Amazon
// Chame: GET /api.php?action=products&category_id=1
```

2. **Usar affiliate_url direto**:
```javascript
// Não precisa mais do node "Encurtar Link"
// Use {{ $json.affiliate_url }} direto
```

3. **Manter lógica de envio**:
```javascript
// Seus nodes de WhatsApp e Telegram continuam iguais
// Só muda a fonte dos dados
```

## 📝 Licença

MIT License - Use livremente em seus projetos!

## 🐛 Problemas?

- Verifique permissões das pastas `data/` e `cache/`
- Certifique-se que PDO SQLite está instalado: `php -m | grep pdo_sqlite`
- Verifique se `.htaccess` está funcionando (ou configure Nginx)

## 📧 Suporte

Criado por: [DaNiLoStOrM](https://github.com/danilostorm)

---

**⚡ Pronto para começar a ganhar com afiliados Amazon!**