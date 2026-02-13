<?php
/**
 * Script de Instalação - Cria banco de dados MySQL
 */

echo "<h1>Amazon Feed Manager - Instalação</h1>";

// Conectar ao MySQL sem selecionar banco
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'amazon_feed';

echo "<p>Conectando ao MySQL...</p>";

try {
    $pdo = new PDO("mysql:host={$host}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p>✅ Conectado ao MySQL!</p>";
    
    // Criar banco de dados
    echo "<p>Criando banco de dados '{$dbname}'...</p>";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p>✅ Banco de dados criado!</p>";
    
    // Selecionar banco
    $pdo->exec("USE `{$dbname}`");
    
    // Criar tabelas
    echo "<p>Criando tabelas...</p>";
    
    require_once __DIR__ . '/includes/Database.php';
    $db = new Database();
    
    echo "<p>✅ Tabelas criadas com sucesso!</p>";
    
    // Verificar credenciais
    $creds = $db->getCredentials();
    echo "<p>✅ Credenciais Amazon configuradas!</p>";
    echo "<pre>";
    echo "Associate Tag: {$creds['associate_tag']}\n";
    echo "Credential ID: {$creds['credential_id']}\n";
    echo "Version: {$creds['version']}\n";
    echo "</pre>";
    
    echo "<hr>";
    echo "<h2>✅ Instalação concluída!</h2>";
    echo "<p><a href='index.php'>Acessar o sistema</a></p>";
    echo "<p><strong>IMPORTANTE:</strong> Delete o arquivo install.php por segurança!</p>";
    
} catch (PDOException $e) {
    echo "<p>❌ Erro: " . $e->getMessage() . "</p>";
    echo "<p>Verifique:</p>";
    echo "<ul>";
    echo "<li>MySQL está rodando</li>";
    echo "<li>Usuário e senha em config.php estão corretos</li>";
    echo "<li>Usuário tem permissão para criar banco de dados</li>";
    echo "</ul>";
}
