<?php

$usuario = 'root';
$senha = '';
$database = 'login_action';
$host = 'localhost';

$mysqli = new mysqli($host, $usuario, $senha, $database);

if($mysqli-> error) {
    die("Falha ao conectar no BD: ". $mysqli ->error);
}