<?php
/**
 * Database Handler - SQLite
 */

class Database {
    private $db;
    
    public function __construct() {
        $dbPath = __DIR__ . '/../data/amazon_feed.db';
        
        // Criar pasta data se não existir
        if (!file_exists(__DIR__ . '/../data')) {
            mkdir(__DIR__ . '/../data', 0755, true);
        }
        
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
    }
    
    public function getPdo() {
        return $this->db;
    }
    
    private function createTables() {
        // Tabela de credenciais
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                credential_id TEXT,
                credential_secret TEXT,
                version TEXT DEFAULT '2.1',
                associate_tag TEXT,
                marketplace TEXT DEFAULT 'www.amazon.com.br',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Tabela de categorias
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                browse_node_id TEXT,
                keywords TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Tabela de produtos
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asin TEXT UNIQUE NOT NULL,
                title TEXT,
                price REAL,
                currency TEXT DEFAULT 'BRL',
                image_url TEXT,
                product_url TEXT,
                affiliate_url TEXT,
                features TEXT,
                availability TEXT,
                rating TEXT,
                category_id INTEGER,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            )
        ");
        
        // Tabela de sincronizações
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                products_count INTEGER,
                synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            )
        ");
    }
    
    public function getCredentials() {
        $stmt = $this->db->query("SELECT * FROM credentials ORDER BY id DESC LIMIT 1");
        $creds = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$creds) {
            // Inserir credenciais padrão do CSV fornecido
            $this->db->exec("
                INSERT INTO credentials (credential_id, credential_secret, version, associate_tag, marketplace)
                VALUES ('78ft3eumief9asdv8896d46c2q', '17cf0nstftiqmap47qtjpt7ho91rkc0n0rlndfov77ge23sef1us', '2.1', 'stormanimesbr-20', 'www.amazon.com.br')
            ");
            return $this->getCredentials();
        }
        
        return $creds;
    }
    
    public function saveProduct($product) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO products 
            (asin, title, price, currency, image_url, product_url, affiliate_url, features, availability, rating, category_id, updated_at)
            VALUES (:asin, :title, :price, :currency, :image_url, :product_url, :affiliate_url, :features, :availability, :rating, :category_id, CURRENT_TIMESTAMP)
        ");
        
        $stmt->execute([
            ':asin' => $product['asin'],
            ':title' => $product['title'] ?? null,
            ':price' => $product['price'] ?? null,
            ':currency' => $product['currency'] ?? 'BRL',
            ':image_url' => $product['image_url'] ?? null,
            ':product_url' => $product['product_url'] ?? null,
            ':affiliate_url' => $product['affiliate_url'] ?? null,
            ':features' => $product['features'] ?? null,
            ':availability' => $product['availability'] ?? null,
            ':rating' => $product['rating'] ?? null,
            ':category_id' => $product['category_id'] ?? null
        ]);
    }
    
    public function getProductByAsin($asin) {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE asin = :asin");
        $stmt->execute([':asin' => $asin]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getCategory($id) {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function logSync($categoryId, $count) {
        $stmt = $this->db->prepare("
            INSERT INTO sync_logs (category_id, products_count)
            VALUES (:category_id, :count)
        ");
        $stmt->execute([
            ':category_id' => $categoryId,
            ':count' => $count
        ]);
    }
}