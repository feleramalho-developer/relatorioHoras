<?php
// conexao.php
// Arquivo central de conexão com o banco de dados.
// Inclua este arquivo nos demais scripts com: require_once __DIR__ . '/conexao.php';

$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
