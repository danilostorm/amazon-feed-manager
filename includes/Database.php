<?php
/**
 * Database Handler - MySQL
 */

class Database {
    private $db;
    
    public function __construct() {
        // Configurar aqui ou criar config.php
        $host = 'localhost';
        $dbname = 'amazon_feed';
        $username = 'root'; // Alterar conforme seu MySQL
        $password = ''; // Alterar conforme seu MySQL
        
        // Tentar carregar de config.php se existir
        if (file_exists(__DIR__ . '/../config.php')) {
            include __DIR__ . '/../config.php';
            if (defined('DB_HOST')) $host = DB_HOST;
            if (defined('DB_NAME')) $dbname = DB_NAME;
            if (defined('DB_USER')) $username = DB_USER;
            if (defined('DB_PASS')) $password = DB_PASS;
        }
        
        try {
            $this->db = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
            $this->createTables();
        } catch (PDOException $e) {
            die("Erro de conexão MySQL: " . $e->getMessage() . "<br><br>Verifique as credenciais em config.php");
        }
    }
    
    public function getPdo() {
        return $this->db;
    }
    
    private function createTables() {
        // Tabela de credenciais
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                credential_id VARCHAR(255),
                credential_secret VARCHAR(255),
                version VARCHAR(10) DEFAULT '2.1',
                associate_tag VARCHAR(100),
                marketplace VARCHAR(100) DEFAULT 'www.amazon.com.br',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Tabela de categorias
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                browse_node_id VARCHAR(50),
                keywords TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Tabela de produtos
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asin VARCHAR(20) UNIQUE NOT NULL,
                title TEXT,
                price DECIMAL(10,2),
                currency VARCHAR(10) DEFAULT 'BRL',
                image_url TEXT,
                product_url TEXT,
                affiliate_url TEXT,
                features TEXT,
                availability VARCHAR(255),
                rating VARCHAR(50),
                category_id INT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_asin (asin),
                INDEX idx_category (category_id),
                INDEX idx_updated (updated_at),
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Tabela de sincronizações
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sync_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT,
                products_count INT,
                synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_synced (synced_at),
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    public function getCredentials() {
        $stmt = $this->db->query("SELECT * FROM credentials ORDER BY id DESC LIMIT 1");
        $creds = $stmt->fetch();
        
        if (!$creds) {
            // Inserir credenciais padrão
            $this->db->exec("
                INSERT INTO credentials (credential_id, credential_secret, version, associate_tag, marketplace)
                VALUES ('78ft3eumief9asdv8896d46c2q', '17cf0nstftiqmap47qtjpt7ho91rkc0n0rlndfov77ge23sef1us', '2.1', 'stormanimesbr-20', 'www.amazon.com.br')
            ");
            return $this->getCredentials();
        }
        
        return $creds;
    }
    
    public function updateCredentials($data) {
        $stmt = $this->db->prepare("
            INSERT INTO credentials (credential_id, credential_secret, version, associate_tag, marketplace)
            VALUES (:credential_id, :credential_secret, :version, :associate_tag, :marketplace)
        ");
        
        $stmt->execute([
            ':credential_id' => $data['credential_id'],
            ':credential_secret' => $data['credential_secret'],
            ':version' => $data['version'] ?? '2.1',
            ':associate_tag' => $data['associate_tag'],
            ':marketplace' => $data['marketplace'] ?? 'www.amazon.com.br'
        ]);
    }
    
    public function getCategories() {
        $stmt = $this->db->query("SELECT * FROM categories ORDER BY name");
        return $stmt->fetchAll();
    }
    
    public function getCategory($id) {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function saveCategory($data) {
        if (isset($data['id']) && $data['id'] > 0) {
            // Update
            $stmt = $this->db->prepare("
                UPDATE categories 
                SET name = :name, browse_node_id = :browse_node_id, keywords = :keywords, is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $data['id'],
                ':name' => $data['name'],
                ':browse_node_id' => $data['browse_node_id'] ?? null,
                ':keywords' => $data['keywords'] ?? '',
                ':is_active' => $data['is_active'] ?? 1
            ]);
        } else {
            // Insert
            $stmt = $this->db->prepare("
                INSERT INTO categories (name, browse_node_id, keywords, is_active)
                VALUES (:name, :browse_node_id, :keywords, :is_active)
            ");
            $stmt->execute([
                ':name' => $data['name'],
                ':browse_node_id' => $data['browse_node_id'] ?? null,
                ':keywords' => $data['keywords'] ?? '',
                ':is_active' => $data['is_active'] ?? 1
            ]);
        }
    }
    
    public function deleteCategory($id) {
        $stmt = $this->db->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
    
    public function getProducts($categoryId = null, $limit = 100) {
        if ($categoryId) {
            $stmt = $this->db->prepare("
                SELECT * FROM products 
                WHERE category_id = :category_id 
                ORDER BY updated_at DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM products 
                ORDER BY updated_at DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        
        return $stmt->fetchAll();
    }
    
    public function saveProduct($product) {
        $stmt = $this->db->prepare("
            INSERT INTO products 
            (asin, title, price, currency, image_url, product_url, affiliate_url, features, availability, rating, category_id)
            VALUES (:asin, :title, :price, :currency, :image_url, :product_url, :affiliate_url, :features, :availability, :rating, :category_id)
            ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            price = VALUES(price),
            currency = VALUES(currency),
            image_url = VALUES(image_url),
            product_url = VALUES(product_url),
            affiliate_url = VALUES(affiliate_url),
            features = VALUES(features),
            availability = VALUES(availability),
            rating = VALUES(rating),
            category_id = VALUES(category_id),
            updated_at = CURRENT_TIMESTAMP
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
        return $stmt->fetch();
    }
    
    public function deleteProduct($id) {
        $stmt = $this->db->prepare("DELETE FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
    
    public function getSyncLogs($limit = 50) {
        $stmt = $this->db->prepare("
            SELECT sl.*, c.name as category_name
            FROM sync_logs sl
            LEFT JOIN categories c ON sl.category_id = c.id
            ORDER BY sl.synced_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
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
    
    public function getStats() {
        $stats = [];
        
        // Total de produtos
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM products");
        $stats['total_products'] = $stmt->fetch()['total'];
        
        // Total de categorias
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM categories WHERE is_active = 1");
        $stats['total_categories'] = $stmt->fetch()['total'];
        
        // Última sincronização
        $stmt = $this->db->query("SELECT synced_at FROM sync_logs ORDER BY synced_at DESC LIMIT 1");
        $lastSync = $stmt->fetch();
        $stats['last_sync'] = $lastSync ? $lastSync['synced_at'] : null;
        
        return $stats;
    }
}