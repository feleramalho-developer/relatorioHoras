<?php
include('protect.php');

$usuarioLogado = $_SESSION['nome'];

// --- PHPSPREADSHEET ---
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// CONFIGURAÇÃO BANCO
/*
$host = "localhost";
$user = "root";
$pass = "";
$db = "lancamento_db";
*/
$host = "caboose.proxy.rlwy.net"; // confirme no Railway
$port = 46551; // confirme a porta no Railway
$user = "root"; // ou o usuário que aparece lá
$pass = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$db = "usuario"; // confirme o nome do banco

// CONEXÃO COM BANCO

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db`");
    /*
    $pdo->exec("CREATE TABLE IF NOT EXISTS movimentacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente VARCHAR(255) NOT NULL,
        projeto VARCHAR(255) NOT NULL,
        tarefa VARCHAR(100),
        horas VARCHAR(5),
        observacao TEXT,
        usuario VARCHAR(100),
        dlancamento DATE NOT NULL
    )");
    */
    
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}


// EXCLUIR REGISTRO
if (isset($_GET['excluir'])) {
    $id = intval($_GET['excluir']);
    $pdo->prepare("DELETE FROM movimentacoes WHERE id=? ")->execute([$id]);   
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// EDITAR REGISTRO
if (!empty($_POST['editar_id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE movimentacoes 
            SET cliente=?, projeto=?, tarefa=?, horas=?, observacao=?, usuario=?, dlancamento=? 
            WHERE id=? AND usuario=?");
        $stmt->execute([
            $_POST['cliente'],
            $_POST['projeto'],
            $_POST['tarefa'] ?: null,
            $_POST['horas'],
            $_POST['observacao'],
            $usuarioLogado,
            $_POST['dlancamento'],
            $_POST['editar_id'],
            $usuarioLogado
        ]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        echo '<div class="alert error">Erro ao atualizar: ' . $e->getMessage() . '</div>';
    }
}

// --- Importar Planilha XLSX ---
if (isset($_POST['importar']) && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'];
    if ($arquivo) {
        try {
            $spreadsheet = IOFactory::load($arquivo);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Remove cabeçalho
            $cabecalho = array_shift($rows);

            $sql = "INSERT INTO movimentacoes (cliente, projeto, tarefa, horas, observacao, usuario, dlancamento)
                    VALUES (:cliente, :projeto, :tarefa, :horas, :observacao, :usuario, :dlancamento)";
            $stmt = $pdo->prepare($sql);

            foreach ($rows as $row) {
                $stmt->execute([
                    ':cliente' => $row[0] ?? '',
                    ':projeto' => $row[1] ?? '',
                    ':tarefa' => $row[2] ?? null,
                    ':horas' => $row[3] ?? '00:00',
                    ':observacao' => $row[4] ?? '',
                    ':usuario' => $row[5] ?? '',
                    ':dlancamento' => date('Y-m-d', strtotime($row[6] ?? date('Y-m-d')))
                ]);
            }

            echo "<div class='alert success'>Importação concluída com sucesso!</div>";
            header("Refresh:1; url=" . $_SERVER['PHP_SELF']);
            exit;

        } catch (Exception $e) {
            echo "<div class='alert error'>Erro ao importar: " . $e->getMessage() . "</div>";
        }
    }
}

// --- Início correção: cálculo da paginação ---
$por_pagina = isset($por_pagina) ? (int)$por_pagina : 20;
$pagina = isset($pagina) ? max(1, (int)$pagina) : (isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1);
$offset = ($pagina - 1) * $por_pagina;

$stmt_count = $pdo->query("SELECT COUNT(*) FROM movimentacoes");
$total_registros = (int) $stmt_count->fetchColumn();
$total_paginas = max(1, (int) ceil($total_registros / $por_pagina));
// --- Fim correção: cálculo da paginação ---

// INSERIR REGISTRO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['editar_id']) && !isset($_POST['importar'])) {
    $cliente = $_POST['cliente'];
    $projeto = $_POST['projeto'];
    $tarefa = $_POST['tarefa'];
    $horas = $_POST['horas'];
    $observacao = $_POST['observacao'];    
    $dlancamento = $_POST['dlancamento'];

    try {
        $stmt = $pdo->prepare("INSERT INTO movimentacoes 
            (cliente, projeto, tarefa, horas, observacao, usuario, dlancamento)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cliente, $projeto, $tarefa ?: null, $horas, $observacao, $usuarioLogado, $dlancamento]);

        echo "<div class='alert success'>Lançamento salvo com sucesso!</div>";
        header("Refresh: 1; url=" . $_SERVER['PHP_SELF']); 
        exit;
    } catch (Exception $e) {
        echo "<div class='alert error'>Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

// CONSULTA MOVIMENTAÇÕES
$mes = $_GET['mes'] ?? null;
$ano = $_GET['ano'] ?? null;

$sql = "SELECT * FROM movimentacoes WHERE usuario = :usuario";
$countSql = "SELECT COUNT(*) FROM movimentacoes WHERE usuario = :usuario";
$params = [':usuario' => $usuarioLogado];

if (!empty($mes)) {
    $sql      .= " AND MONTH(dlancamento) = :mes";
    $countSql .= " AND MONTH(dlancamento) = :mes";
    $params[':mes'] = (int)$mes;
}

if (!empty($ano)) {
    $sql      .= " AND YEAR(dlancamento) = :ano";
    $countSql .= " AND YEAR(dlancamento) = :ano";
    $params[':ano'] = (int)$ano;
}

$sql .= " ORDER BY dlancamento ASC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $paramType = ($key === ':usuario') ? PDO::PARAM_STR : PDO::PARAM_INT;
    $stmt->bindValue($key, $value, $paramType);
}
$stmt->bindValue(':limit', $por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$movs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">    
    <title>Controle de Horas Action Process</title>
    <style>
       /* Reset básico */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Segoe UI", Roboto, Arial, sans-serif;
}

body {
    background-color: #ffffffff;
    color: #333;
    padding: 20px;
}

/* Cabeçalho */
header {
    background: #2d89ef;
    color: black;
    padding: 15px 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
}

header h1 {
    font-size: 1.5rem;
    font-weight: 600;
    
    
}
h1 {
    font-size: 1.5rem;
    font-weight: 600;
    text-align: center;
    color: #000000ff;
}
h2 {
    font-size: 1.5rem;
    font-weight: 600;
    text-align: left;
    color: #ffffffff;
}
h5 {
    font-size: 0.8rem;
    font-weight: 600;
    text-align: left;
    color: #000000ff;
}
        div {
            background-color: #eaeaeaff;
            width: 90%;
            top: 50%;
            left: 50%;
            margin: 10px auto;   /* centraliza horizontalmente */
            text-align: center;  /* centraliza o texto dentro da div */
            padding: 80px;
            border-radius: 15px;
            color: white;
        }

/* Formulários */
form {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
    margin-bottom: 25px;
}

form input, form select, form textarea, form button {
    width: 100%;
    padding: 10px;
    margin-top: 10px;
    border-radius: 8px;
    border: 1px solid #ddd;
    font-size: 0.95rem;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
}

form input:focus, form select:focus, form textarea:focus {
    border-color: #2d89ef;
    outline: none;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
}

form button {
    background: #2d89ef;
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    border: none;
}

form button:hover {
    background: #00747aff;
}

/* Tabela */
table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    font-size: 10px;
    text-align: left;
    background-color: #fff;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
}

th, td {
    padding: 12px 15px;
}

th {
    background-color: #00747aff;
    color: #fff;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

tr {
    border-bottom: 1px solid #ddd;
}

tr:nth-child(even) {
    background-color: #f9f9f9;
}

tr:hover {
    background-color: #f1f1f1;
    transition: background-color 0.3s;
}

td {
    color: #333;
}

td.valor {
    font-weight: bold;
    color: #e74c3c; /* vermelho para valores */
}

/* Botões de ação */
.action-btn {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    text-decoration: none;
    transition: 0.2s;
    cursor: pointer;
}

.btn-sair {
    position: fixed;    /* fixa no canto da tela */
    top: 10px;          /* distância do topo */
    left: 10px;         /* distância da esquerda */
    background-color: red;
    color: white;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;  /* tira o sublinhado */
    font-weight: bold;
    transition: background 0.3s;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, rgba(0, 0, 0, 0.3) 0px 30px 60px -30px, rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
}

footer {
    margin-top: 30px;
    padding: 15px;
    text-align: center;
    background: silver;
    color: black;
    border-radius: 12px;
    font-size: 0.9rem;
    box-shadow: rgba(50, 50, 93, 0.25) 0px 20px 40px -10px,
                rgba(0, 0, 0, 0.3) 0px 15px 25px -15px,
                rgba(10, 37, 64, 0.35) 0px -2px 6px 0px inset;
}

.btn-sair:hover {
    background-color: darkred; /* efeito hover */
}

.action-btn.edit {
    background: #296342ff;
    color: #000;
}

.action-btn.edit:hover {
    background: #e0a800;
}

.action-btn.delete {
    background: #dc3545;
    color: white;
}

.action-btn.delete:hover {
    background: #e0a800;
}

/* Mensagens de sucesso/erro */
.alert {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
    font-size: 0.95rem;
}

.alert.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
.paginacao {
    margin: 10px auto;
    text-align: center;
    background: none;  /* não herda fundo */
}

.paginacao .pagina-texto {
    color: black; /* cor só para o "Página x de x" */
}
    </style>
</head>

<body>
    <div class="container">        
        <h1>Controle de Horas Action Process</h1>
        <p>
           <a href="logout.php" class="btn-sair">Sair</a>
        </p>
        <h5> Usuário: <?php echo $_SESSION['nome'] ?></h5>

        <!-- FORMULÁRIO DE LANÇAMENTO -->
        <form method="post" style="margin:10px; padding:10px; border:1px solid #ccc; background:#00747aff;">        
            <input type='hidden' name='editar_id' id='editar_id' value=''>
            <h2>Novo Lançamento</h2>
            <input type="text" name="cliente" placeholder="Cliente" required>
            <input type="text" name="projeto" placeholder="Projeto" required>
            <input type="text" name="tarefa" placeholder="Tarefa" required>
            <input type="time" id="horas" name="horas" placeholder="00:00" required>
            <textarea name="observacao" placeholder="Observação"></textarea>
            <input type="text" name="usuario" placeholder="Usuário (Automático)" readonly>
            <input type="date" name="dlancamento" required>            
            <button type="submit">Salvar</button>
        </form>

        <!-- FORMULÁRIO DE FILTRO -->
        <form method="get" style="margin:10px; padding:10px; border:1px solid #ccc; background:#00747aff;">
            <label>Filtrar Lançamentos: </label>
            <label for="mes">Mês:</label>
            <select name="mes" id="mes">
                <option value="">Selecione</option>
                <?php for($m=1;$m<=12;$m++): 
                    $selected = (isset($_GET['mes']) && $_GET['mes']==$m)?'selected':'';
                    echo "<option value='$m' $selected>".strftime("%B", mktime(0,0,0,$m,1))."</option>";
                endfor; ?>
            </select>

            <label for="ano">Ano:</label>
            <select name="ano" id="ano">
                <option value="">Selecione</option>
                <?php 
                $anoAtual = date('Y');
                for ($a = $anoAtual; $a >= $anoAtual - 5; $a--) {
                    $selected = (isset($_GET['ano']) && $_GET['ano'] == $a) ? 'selected' : '';
                    echo "<option value='$a' $selected>$a</option>";
                }
                ?>
            </select>
            <button type="submit">Filtrar</button>
            <button type="button" onclick="limparFiltros()">Limpar</button>
        </form>

        <!-- FORMULÁRIO DE IMPORTAÇÃO -->
        <form action="" method="post" enctype="multipart/form-data" style="margin:10px; padding:10px; border:1px solid #ccc; background:#00747aff;">
            <label for="arquivo">Importar planilha:</label>
            <input type="file" name="arquivo" id="arquivo" accept=".csv, .xlsx">
            <button type="submit" name="importar">Importar</button>
        </form>

        <!-- TABELA DE LANÇAMENTOS -->
        <table>
            <tr>
                <th>ID</th>
                <th>Cliente</th>
                <th>Projeto</th>
                <th>Tarefa</th>
                <th>Horas</th>
                <th>Observação</th>
                <th>Consultor</th>
                <th>Data</th>                
                <th>Ações</th>
            </tr>
            <?php foreach ($movs as $m): ?>
                <tr>
                    <td><?= $m['id'] ?></td>
                    <td><?= $m['cliente'] ?></td>
                    <td><?= $m['projeto'] ?></td>
                    <td><?= $m['tarefa'] ?></td>
                    <td><?= $m['horas'] ?></td>
                    <td><?= $m['observacao'] ?></td>
                    <td><?= $m['usuario'] ?></td>
                    <td><?= $m['dlancamento'] ?></td>                    
                    <td>
                        <a class="action-btn delete" href="?excluir=<?= $m['id'] ?>">Excluir</a>
                        <button type="button" class="action-btn edit editarBtn" data-id="<?= $m['id'] ?>" data-cliente="<?= $m['cliente'] ?>"
                            data-projeto="<?= $m['projeto'] ?>" data-tarefa="<?= $m['tarefa'] ?>"
                            data-horas="<?= $m['horas'] ?>" data-observacao="<?= $m['observacao'] ?>"
                            data-usuario="<?= $m['usuario'] ?>" data-dlancamento="<?= $m['dlancamento'] ?>">Editar</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- PAGINAÇÃO -->
    <div class="paginacao">
        <?php if ($pagina > 1): ?>
            <a href="?pagina=<?= $pagina - 1 ?>&mes=<?= urlencode($mes) ?>&ano=<?= urlencode($ano) ?>">« Anterior</a>
        <?php endif; ?>

        <span class="pagina-texto">Página <?= $pagina ?> de <?= $total_paginas ?></span>

        <?php if ($pagina < $total_paginas): ?>
            <a href="?pagina=<?= $pagina + 1 ?>&mes=<?= urlencode($mes) ?>&ano=<?= urlencode($ano) ?>">Próxima »</a>
        <?php endif; ?>
    </div>

    <footer>
        <p>Sistema desenvolvido por <strong>Felipe Santos</strong> - Action Process</p>
        <p>&copy; 2025 Todos os direitos reservados</p>
    </footer>

    <script>
        function limparFiltros() {
            document.getElementById("mes").value = "";
            document.getElementById("ano").value = "";
            window.location.href = "lancamentos.php";
        }

        document.querySelectorAll('.editarBtn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('editar_id').value = this.dataset.id;
                document.querySelector('[name=cliente]').value = this.dataset.cliente;               
                document.querySelector('[name=projeto]').value = this.dataset.projeto;
                document.querySelector('[name=tarefa]').value = this.dataset.tarefa;
                document.querySelector('[name=horas]').value = this.dataset.horas;
                document.querySelector('[name=observacao]').value = this.dataset.observacao;
                document.querySelector('[name=usuario]').value = this.dataset.usuario;
                document.querySelector('[name=dlancamento]').value = this.dataset.dlancamento;                
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    </script>
</body>
</html>
