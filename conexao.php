<?php

$host = "caboose.proxy.rlwy.net"; // confirme no Railway
$port = 46551; // confirme a porta no Railway
$user = "root"; // ou o usuário que aparece lá
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$db = "usuario"; // confirme o nome do banco

$conn = new mysqli($host, $user, $password, $db, $port);

if ($conn->connect_error) {
    die("Erro na conexão: " . $conn->connect_error);
}