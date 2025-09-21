<?php

$host = "caboose.proxy.rlwy.net"; 
$port = 46551; 
$user = "root"; 
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$db = "railway"; 

$mysqli = new mysqli($host, $user, $password, $db, $port);

if ($mysqli->connect_error) {
    die("Erro na conexão: " . $mysqli->connect_error);
}