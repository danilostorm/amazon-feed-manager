<?php
/**
 * Amazon Feed Manager - Painel Admin
 * Sistema para gerenciar feeds Amazon com geração automática de links de afiliado
 */

require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/AmazonAPI.php';

session_start();

$db = new Database();
$amazon = new AmazonAPI($db);

// Processar ações
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_credentials':
                $db->saveCredentials([
                    'associate_tag' => $_POST['associate_tag'],
                    'access_key' => $_POST['access_key'],
                    'secret_key' => $_POST['secret_key'],
                    'marketplace' => $_POST['marketplace']
                ]);
                $message = 'Credenciais salvas com sucesso!';
                break;
                
            case 'add_category':
                $db->addCategory([
                    'name' => $_POST['category_name'],
                    'browse_node_id' => $_POST['browse_node_id'],
                    'keywords' => $_POST['keywords'],
                    'active' => isset($_POST['active']) ? 1 : 0
                ]);
                $message = 'Categoria adicionada com sucesso!';
                break;
                
            case 'delete_category':
                $db->deleteCategory($_POST['category_id']);
                $message = 'Categoria removida!';
                break;
                
            case 'sync_category':
                try {
                    $products = $amazon->searchProducts($_POST['category_id']);
                    $message = count($products) . ' produtos sincronizados!';
                } catch (Exception $e) {
                    $error = 'Erro ao sincronizar: ' . $e->getMessage();
                }
                break;
        }
    }
}

$credentials = $db->getCredentials();
$categories = $db->getCategories();
$stats = $db->getStats();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amazon Feed Manager</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>🛍️ Amazon Feed Manager</h1>
            <p>Sistema de gerenciamento de feeds com geração automática de links de afiliado</p>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo $stats['total_categories']; ?></h3>
                <p>Categorias Ativas</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $stats['total_products']; ?></h3>
                <p>Produtos no Feed</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $stats['synced_today']; ?></h3>
                <p>Sincronizados Hoje</p>
            </div>
        </div>

        <!-- Abas -->
        <div class="tabs">
            <button class="tab-button active" onclick="openTab('credentials')">Credenciais Amazon</button>
            <button class="tab-button" onclick="openTab('categories')">Categorias</button>
            <button class="tab-button" onclick="openTab('products')">Produtos</button>
            <button class="tab-button" onclick="openTab('api')">API / n8n</button>
        </div>

        <!-- Aba Credenciais -->
        <div id="credentials" class="tab-content active">
            <h2>Configurar Credenciais Amazon</h2>
            <form method="POST" class="form-card">
                <input type="hidden" name="action" value="save_credentials">
                
                <div class="form-group">
                    <label>Associate Tag (Partner Tag) *</label>
                    <input type="text" name="associate_tag" 
                           value="<?php echo htmlspecialchars($credentials['associate_tag'] ?? ''); ?>" 
                           placeholder="stormanimesbr-20" required>
                    <small>Sua tag de afiliado Amazon (ex: stormanimesbr-20)</small>
                </div>
                
                <div class="form-group">
                    <label>Access Key (Credential ID) *</label>
                    <input type="text" name="access_key" 
                           value="<?php echo htmlspecialchars($credentials['access_key'] ?? ''); ?>" 
                           placeholder="78ft3eumief9asdv8896d46c2q" required>
                </div>
                
                <div class="form-group">
                    <label>Secret Key (Credential Secret) *</label>
                    <input type="password" name="secret_key" 
                           value="<?php echo htmlspecialchars($credentials['secret_key'] ?? ''); ?>" 
                           placeholder="17cf0nstftiqmap47qtjpt7ho91rkc0n..." required>
                </div>
                
                <div class="form-group">
                    <label>Marketplace</label>
                    <select name="marketplace">
                        <option value="webservices.amazon.com.br" <?php echo ($credentials['marketplace'] ?? '') === 'webservices.amazon.com.br' ? 'selected' : ''; ?>>Brasil (amazon.com.br)</option>
                        <option value="webservices.amazon.com" <?php echo ($credentials['marketplace'] ?? '') === 'webservices.amazon.com' ? 'selected' : ''; ?>>USA (amazon.com)</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">Salvar Credenciais</button>
            </form>
        </div>

        <!-- Aba Categorias -->
        <div id="categories" class="tab-content">
            <h2>Gerenciar Categorias de Feed</h2>
            
            <div class="form-card">
                <h3>Adicionar Nova Categoria</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_category">
                    
                    <div class="form-group">
                        <label>Nome da Categoria *</label>
                        <input type="text" name="category_name" placeholder="Eletrônicos" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Browse Node ID (opcional)</label>
                        <input type="text" name="browse_node_id" placeholder="16243890011">
                        <small>Node ID da categoria Amazon (deixe vazio para buscar todas categorias)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Keywords para Busca</label>
                        <input type="text" name="keywords" placeholder="notebook, laptop, computador">
                        <small>Palavras-chave separadas por vírgula</small>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="active" checked>
                            Ativar categoria imediatamente
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Adicionar Categoria</button>
                </form>
            </div>
            
            <h3>Categorias Cadastradas</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Browse Node ID</th>
                        <th>Keywords</th>
                        <th>Produtos</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($cat['browse_node_id'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($cat['keywords']); ?></td>
                        <td><?php echo $cat['product_count']; ?></td>
                        <td>
                            <span class="badge <?php echo $cat['active'] ? 'badge-success' : 'badge-inactive'; ?>">
                                <?php echo $cat['active'] ? 'Ativo' : 'Inativo'; ?>
                            </span>
                        </td>
                        <td class="actions">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="sync_category">
                                <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-info">🔄 Sincronizar</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remover categoria?');">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Aba Produtos -->
        <div id="products" class="tab-content">
            <h2>Produtos no Feed</h2>
            <?php
            $products = $db->getProducts(50);
            ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Imagem</th>
                        <th>Título</th>
                        <th>ASIN</th>
                        <th>Preço</th>
                        <th>Link Afiliado</th>
                        <th>Última Atualização</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                    <tr>
                        <td><img src="<?php echo htmlspecialchars($product['image_url']); ?>" width="60" alt=""></td>
                        <td><?php echo htmlspecialchars(substr($product['title'], 0, 50)) . '...'; ?></td>
                        <td><code><?php echo htmlspecialchars($product['asin']); ?></code></td>
                        <td><strong>R$ <?php echo htmlspecialchars($product['price']); ?></strong></td>
                        <td>
                            <a href="<?php echo htmlspecialchars($product['affiliate_url']); ?>" 
                               target="_blank" class="btn btn-sm btn-success">🔗 Ver Link</a>
                        </td>
                        <td><?php echo date('d/m/Y H:i', strtotime($product['updated_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Aba API -->
        <div id="api" class="tab-content">
            <h2>Integração com n8n</h2>
            
            <div class="info-card">
                <h3>📡 Endpoints Disponíveis</h3>
                
                <div class="endpoint">
                    <code class="endpoint-url">GET /api.php?action=products</code>
                    <p>Retorna todos os produtos com links de afiliado gerados</p>
                    <pre>{
  "status": "success",
  "data": [
    {
      "asin": "B0CLSSFG6J",
      "title": "Ar Condicionado Electrolux...",
      "price": "1299.90",
      "image_url": "https://m.media-amazon.com/images/I/...",
      "affiliate_url": "https://www.amazon.com.br/dp/B0CLSSFG6J?tag=stormanimesbr-20"
    }
  ]
}</pre>
                </div>
                
                <div class="endpoint">
                    <code class="endpoint-url">GET /api.php?action=products&category_id=1</code>
                    <p>Retorna produtos de uma categoria específica</p>
                </div>
                
                <div class="endpoint">
                    <code class="endpoint-url">GET /api.php?action=categories</code>
                    <p>Lista todas as categorias ativas</p>
                </div>
                
                <div class="endpoint">
                    <code class="endpoint-url">POST /api.php?action=search</code>
                    <p>Busca produtos por palavra-chave</p>
                    <pre>POST data: {"keyword": "notebook gamer"}</pre>
                </div>
            </div>
            
            <div class="info-card">
                <h3>🔧 Exemplo de uso no n8n</h3>
                <pre>// Node HTTP Request
URL: <?php echo 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']); ?>/api.php
Method: GET
Query Parameters:
  - action: products
  - category_id: 1 (opcional)

// Os links já vêm com sua tag: <?php echo htmlspecialchars($credentials['associate_tag'] ?? 'SUA-TAG-20'); ?></pre>
            </div>
        </div>
    </div>

    <script>
    function openTab(tabName) {
        // Esconder todos os conteúdos
        const contents = document.getElementsByClassName('tab-content');
        for (let i = 0; i < contents.length; i++) {
            contents[i].classList.remove('active');
        }
        
        // Remover classe active dos botões
        const buttons = document.getElementsByClassName('tab-button');
        for (let i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove('active');
        }
        
        // Mostrar conteúdo selecionado
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
    }
    </script>
</body>
</html>