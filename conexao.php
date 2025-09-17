<?php

$host = $MYSQLHOST;//"caboose.proxy.rlwy.net"; 
$port = $MYSQLPORT;//46551; 
$user = $MYSQLUSER;//"root"; 
$password = $MYSQLPASSWORD;//"GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$db = $MYSQL_DATABASE;//"usuario"; 

$mysqli = new mysqli($host, $user, $password, $db, $port);

if ($mysqli->connect_error) {
    die("Erro na conexão: " . $mysqli->connect_error);
}