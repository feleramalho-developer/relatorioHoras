<?php
session_start();
require_once __DIR__ . '/conexao.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, nome, email, senha, liberacao, statusConsultor
                FROM usuario
                WHERE email = :email
                LIMIT 1
            ");

            $stmt->execute([
                ':email' => $email
            ]);

            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario && $senha === $usuario['senha']) {
                $_SESSION['id'] = $usuario['id'];
                $_SESSION['nome'] = $usuario['nome'];
                $_SESSION['email'] = $usuario['email'];
                $_SESSION['liberacao'] = $usuario['liberacao'];

                header('Location: lancamentos.php');
                exit;
            } else {
                $erro = 'E-mail ou senha inválidos.';
            }

        } catch (Exception $e) {
            $erro = 'Erro ao realizar login: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <title>Tela de Login</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background-image: linear-gradient(45deg, silver, black);
        }

        div {
            background-color: rgba(255, 255, 255, 0.98);
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            padding: 80px;
            border-radius: 15px;
            color: black;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                rgba(0, 0, 0, 0.3) 0px 30px 60px -30px,
                rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        .input-box {
            position: absolute;
            right: 20px;
            top: 50%;
            font-size: 20px;
        }

        input {
            padding: 15px;
            border-radius: 10px;
            outline: none;
            font-size: 15px;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                rgba(0, 0, 0, 0.3) 0px 30px 60px -30px,
                rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        button {
            background-color: dodgerblue;
            border: none;
            padding: 15px;
            width: 100%;
            border-radius: 10px;
            color: white;
            font-size: 15px;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                rgba(0, 0, 0, 0.3) 0px 30px 60px -30px,
                rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        button:hover {
            background-color: deepskyblue;
            cursor: pointer;
            box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px,
                rgba(0, 0, 0, 0.3) 0px 30px 60px -30px,
                rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
        }

        img {
            display: block;
            margin: 0 auto;
        }

        .erro {
            display: block;
            color: red;
            font-size: 12px;
            margin-top: 5px;
        }

        footer {
            margin-top: 30px;
            text-align: center;
            color: black;
            border-radius: 12px;
            font-size: 9px;
            width: inherit;
        }
    </style>
</head>

<body>
    <div>
        <img src="./img/logoactionAP.png" width="100px" height="100px">
        <h2>Login</h2>
        <form action="" method="POST">
            <p>
                <input type="text" placeholder="E-Mail" name="email" value="<?= $_POST['email'] ?? '' ?>">
                <i class="bx bxs-user"></i>
                <?php if (!empty($erro_email))
                    echo "<span class='erro'>$erro_email</span>"; ?>
            </p>
            <p>
                <input type="password" placeholder="Senha" name="senha">
                <i class="bx bxs-lock-alt"></i>
                <?php if (!empty($erro_senha))
                    echo "<span class='erro'>$erro_senha</span>"; ?>
            </p>
            <p>
                <button type="submit">Entrar</button>
            </p>
            <?php if (!empty($erro_login))
                echo "<p class='erro'>$erro_login</p>"; ?>
        </form>
        <footer>
            <p>Sistema desenvolvido por <strong>Felipe Santos</strong> - Action Process</p>
            <p>&copy; 2025 Todos os direitos reservados</p>
        </footer>
    </div>
</body>

</html>