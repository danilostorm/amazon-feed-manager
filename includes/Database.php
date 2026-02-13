<?php
/**
 * Database Handler - SQLite
 */

class Database {
    private $db;
    
    public function __construct() {
        $this->db = new PDO('sqlite:' . DB_FILE);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
    }
    
    /**
     * Retorna instância PDO (para queries customizadas)
     */
    public function getPdo() {
        return $this->db;
    }
    
    private function createTables() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS credentials (
                id INTEGER PRIMARY KEY,
                associate_tag TEXT NOT NULL,
                access_key TEXT NOT NULL,
                secret_key TEXT NOT NULL,
                marketplace TEXT DEFAULT 'webservices.amazon.com.br',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                browse_node_id TEXT,
                keywords TEXT,
                active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                asin TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                price TEXT,
                currency TEXT DEFAULT 'BRL',
                image_url TEXT,
                product_url TEXT,
                affiliate_url TEXT,
                features TEXT,
                rating REAL,
                reviews_count INTEGER,
                availability TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            );
            
            CREATE TABLE IF NOT EXISTS sync_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                products_synced INTEGER,
                sync_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            );
            
            CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id);
            CREATE INDEX IF NOT EXISTS idx_products_asin ON products(asin);
            CREATE INDEX IF NOT EXISTS idx_products_updated ON products(updated_at);
        ");
    }
    
    public function saveCredentials($data) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO credentials (id, associate_tag, access_key, secret_key, marketplace, updated_at)
            VALUES (1, :associate_tag, :access_key, :secret_key, :marketplace, CURRENT_TIMESTAMP)
        ");
        
        return $stmt->execute([
            ':associate_tag' => $data['associate_tag'],
            ':access_key' => $data['access_key'],
            ':secret_key' => $data['secret_key'],
            ':marketplace' => $data['marketplace']
        ]);
    }
    
    public function getCredentials() {
        $stmt = $this->db->query("SELECT * FROM credentials WHERE id = 1");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    public function addCategory($data) {
        $stmt = $this->db->prepare("
            INSERT INTO categories (name, browse_node_id, keywords, active)
            VALUES (:name, :browse_node_id, :keywords, :active)
        ");
        
        return $stmt->execute([
            ':name' => $data['name'],
            ':browse_node_id' => $data['browse_node_id'],
            ':keywords' => $data['keywords'],
            ':active' => $data['active']
        ]);
    }
    
    public function getCategory($id) {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function deleteCategory($id) {
        $stmt = $this->db->prepare("DELETE FROM categories WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
    
    public function getCategories($activeOnly = false) {
        $sql = "
            SELECT c.*, COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
        ";
        
        if ($activeOnly) {
            $sql .= " WHERE c.active = 1";
        }
        
        $sql .= " GROUP BY c.id ORDER BY c.name";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function saveProduct($data) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO products 
            (category_id, asin, title, price, currency, image_url, product_url, affiliate_url, 
             features, rating, reviews_count, availability, updated_at)
            VALUES 
            (:category_id, :asin, :title, :price, :currency, :image_url, :product_url, :affiliate_url,
             :features, :rating, :reviews_count, :availability, CURRENT_TIMESTAMP)
        ");
        
        return $stmt->execute([
            ':category_id' => $data['category_id'] ?? null,
            ':asin' => $data['asin'],
            ':title' => $data['title'],
            ':price' => $data['price'] ?? null,
            ':currency' => $data['currency'] ?? 'BRL',
            ':image_url' => $data['image_url'] ?? null,
            ':product_url' => $data['product_url'] ?? null,
            ':affiliate_url' => $data['affiliate_url'],
            ':features' => $data['features'] ?? null,
            ':rating' => $data['rating'] ?? null,
            ':reviews_count' => $data['reviews_count'] ?? null,
            ':availability' => $data['availability'] ?? null
        ]);
    }
    
    public function getProducts($limit = 50, $categoryId = null) {
        $sql = "SELECT * FROM products";
        $params = [];
        
        if ($categoryId) {
            $sql .= " WHERE category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }
        
        $sql .= " ORDER BY updated_at DESC LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getProductByAsin($asin) {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE asin = :asin");
        $stmt->execute([':asin' => $asin]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function logSync($categoryId, $count) {
        $stmt = $this->db->prepare("
            INSERT INTO sync_log (category_id, products_synced)
            VALUES (:category_id, :count)
        ");
        
        return $stmt->execute([
            ':category_id' => $categoryId,
            ':count' => $count
        ]);
    }
    
    public function getStats() {
        $stats = [];
        
        // Total de categorias ativas
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM categories WHERE active = 1");
        $stats['total_categories'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Total de produtos
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM products");
        $stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Sincronizados hoje
        $stmt = $this->db->query("
            SELECT SUM(products_synced) as count 
            FROM sync_log 
            WHERE DATE(sync_date) = DATE('now')
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['synced_today'] = $result['count'] ?? 0;
        
        return $stats;
    }
}