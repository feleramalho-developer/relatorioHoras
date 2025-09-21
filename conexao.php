<?php

$host = getenv('MYSQL_HOST');
$user = getenv('MYSQL_USER');
$pass = getenv('MYSQL_PASSWORD');
$db = getenv('MYSQL_DATABASE');

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_errno) {
    die("Falha na conexão: " . $mysqli->connect_error);
}

?>