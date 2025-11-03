<?php
// lancamentos.php - arquivo completo e ajustado
include('protect.php'); // deve iniciar session e garantir login
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// CONFIGURAÇÃO BANCO
$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway";

// CONEXÃO COM BANCO (PDO)
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// verificar usuário logado
$usuarioLogado = $_SESSION['nome'] ?? null;
if (!$usuarioLogado) {
    // redirecionar para login se não tiver sessão (ou tratar conforme seu fluxo)
    header("Location: login.php");
    exit;
}



// ------------------------
// TRATAMENTO DE AÇÕES
// ------------------------

// EXCLUIR (via GET ?excluir=ID)
if (isset($_GET['excluir'])) {
    $idExcluir = intval($_GET['excluir']);
    try {
        $stmtDel = $pdo->prepare("DELETE FROM lancamentos WHERE id = :id AND usuario = :usuario");
        $stmtDel->execute([':id' => $idExcluir, ':usuario' => $usuarioLogado]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $erroGeral = "Erro ao excluir: " . $e->getMessage();
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

            $sql = "INSERT INTO lancamentos (cliente, projeto, tarefa, horas, observacao, usuario, dlancamento)
 VALUES (:cliente, :projeto, :tarefa, :horas, :observacao, :usuario, :dlancamento)";
            $stmt = $pdo->prepare($sql);

            foreach ($rows as $row) {
                // Ignorar linhas completamente vazias
                if (empty(array_filter($row)))
                    continue;

                $stmt->execute([
                    ':cliente' => trim($row[0] ?? ''),
                    ':projeto' => trim($row[1] ?? ''),
                    ':tarefa' => trim($row[2] ?? null),
                    ':horas' => trim($row[3] ?? '00:00'),
                    ':observacao' => trim($row[4] ?? ''),
                    ':usuario' => trim($row[5] ?? ''),
                    ':dlancamento' => date('Y-m-d', strtotime($row[6] ?? date('Y-m-d')))
                ]);
            }
            echo "<div class='alert success'>Importação concluída com sucesso!</div>";
            header("Location: " . $_SERVER['PHP_SELF'] . "?import=ok");
            exit;

        } catch (Exception $e) {
            echo "<div class='alert error'>Erro ao importar: " . $e->getMessage() . "</div>";
        }
    }
}

// EDITAR (POST com editar_id preenchido)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['editar_id']) && !isset($_POST['importar'])) {
    $editar_id = intval($_POST['editar_id']);
    $cliente = trim($_POST['cliente'] ?? '');
    $projeto = trim($_POST['projeto'] ?? '');
    $tarefa = trim($_POST['tarefa'] ?? null);
    $horas = trim($_POST['horas'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');
    $dlancamento = trim($_POST['dlancamento'] ?? '');

    try {
        $stmtUp = $pdo->prepare("UPDATE lancamentos
            SET cliente = :cliente, projeto = :projeto, tarefa = :tarefa, horas = :horas, observacao = :observacao, usuario = :usuario, dlancamento = :dlancamento
            WHERE id = :id AND usuario = :usuario");
        $stmtUp->execute([
            ':cliente' => $cliente,
            ':projeto' => $projeto,
            ':tarefa' => $tarefa ?: null,
            ':horas' => $horas,
            ':observacao' => $observacao,
            ':usuario' => $usuarioLogado,
            ':dlancamento' => $dlancamento,
            ':id' => $editar_id
        ]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $erroGeral = "Erro ao atualizar: " . $e->getMessage();
    }
}

// INSERIR (POST normal, sem editar_id e sem importar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['editar_id']) && !isset($_POST['importar'])) {
    $cliente = trim($_POST['cliente'] ?? '');
    $projeto = trim($_POST['projeto'] ?? '');
    $tarefa = trim($_POST['tarefa'] ?? null);
    $horas = trim($_POST['horas'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');
    $dlancamento = trim($_POST['dlancamento'] ?? '');

    // validações mínimas
    if ($cliente === '' || $projeto === '' || $horas === '' || $dlancamento === '') {
        $erroGeral = "Preencha os campos obrigatórios (Cliente, Projeto, Horas, Data).";
    } else {
        try {
            $stmtIns = $pdo->prepare("INSERT INTO lancamentos (cliente, projeto, tarefa, horas, observacao, usuario, dlancamento)
                VALUES (:cliente, :projeto, :tarefa, :horas, :observacao, :usuario, :dlancamento)");
            $stmtIns->execute([
                ':cliente' => $cliente,
                ':projeto' => $projeto,
                ':tarefa' => $tarefa ?: null,
                ':horas' => $horas,
                ':observacao' => $observacao,
                ':usuario' => $usuarioLogado,
                ':dlancamento' => $dlancamento
            ]);
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            $erroGeral = "Erro ao inserir: " . $e->getMessage();
        }
    }
}

// ------------------------
// FILTROS, PAGINAÇÃO e CONSULTA
// ------------------------
$mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int) $_GET['mes'] : null;
$ano = isset($_GET['ano']) && $_GET['ano'] !== '' ? (int) $_GET['ano'] : null;

$por_pagina = 20;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $por_pagina;

// montar SQL base com filtros
$sqlBase = " FROM lancamentos WHERE usuario = :usuario ";
$params = [':usuario' => $usuarioLogado];

if (!empty($mes)) {
    $sqlBase .= " AND MONTH(dlancamento) = :mes ";
    $params[':mes'] = $mes;
}

if (!empty($ano)) {
    $sqlBase .= " AND YEAR(dlancamento) = :ano ";
    $params[':ano'] = $ano;
}

// contar total (para paginação)
try {
    $countSql = "SELECT COUNT(*) " . $sqlBase;
    $stmtCount = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $ptype = ($k === ':usuario') ? PDO::PARAM_STR : PDO::PARAM_INT;
        $stmtCount->bindValue($k, $v, $ptype);
    }
    $stmtCount->execute();
    $total_registros = (int) $stmtCount->fetchColumn();
    $total_paginas = max(1, (int) ceil($total_registros / $por_pagina));
} catch (Exception $e) {
    $total_registros = 0;
    $total_paginas = 1;
}

// buscar dados paginados
try {
    $selectSql = "SELECT * " . $sqlBase . " ORDER BY dlancamento ASC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($selectSql);
    // bind filtros
    foreach ($params as $k => $v) {
        $ptype = ($k === ':usuario') ? PDO::PARAM_STR : PDO::PARAM_INT;
        $stmt->bindValue($k, $v, $ptype);
    }
    // bind limit/offset
    $stmt->bindValue(':limit', (int) $por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();
    $movs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $movs = [];
    $erroGeral = "Erro na consulta: " . $e->getMessage();
}

// ------------------------
// SOMA DAS HORAS (MÊS SELECIONADO OU ATUAL)
// ------------------------
$mesFiltro = !empty($mes) ? $mes : date('m');
$anoFiltro = !empty($ano) ? $ano : date('Y');

try {
    $sqlHoras = "
        SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(horas))) AS total_horas
        FROM lancamentos
        WHERE usuario = :usuario
        AND MONTH(dlancamento) = :mes
        AND YEAR(dlancamento) = :ano
    ";
    $stmtHoras = $pdo->prepare($sqlHoras);
    $stmtHoras->execute([
        ':usuario' => $usuarioLogado,
        ':mes' => $mesFiltro,
        ':ano' => $anoFiltro
    ]);
    $totalHorasMes = $stmtHoras->fetchColumn() ?: '00:00:00';
} catch (Exception $e) {
    $totalHorasMes = '00:00:00';
}

// ------------------------
// FUNÇÃO DE ESCAPE (uso repetido)
function h($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Controle de Horas Action Process</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ======= reset/estilos principais (mantive seu tema escuro) ======= */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", sans-serif;
        }

        body {
            background-color: #121221;
            color: #e0e0e0;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, #132d2e, #1f7071);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: rgba(0, 0, 0, 0.4) 0 10px 25px;
        }

        header h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.4rem;
        }

        .btn-sair {
            background-color: #c82333;
            color: #fff;
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .main {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
            align-items: start;
        }

        .left-panel form {
            background: #1f1f2f;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 14px;
        }

        .left-panel h2 {
            font-size: 1rem;
            color: #fff;
            margin-bottom: 10px;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .left-panel input,
        .left-panel textarea,
        .left-panel select,
        .left-panel button {
            width: 100%;
            margin-top: 8px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #444;
            background: #2b2b3b;
            color: #fff;
        }

        .left-panel button {
            background: #0078d7;
            border: none;
            font-weight: 700;
            cursor: pointer;
        }

        .right-panel {}

        .right-panel h2 {
            margin-bottom: 10px;
            display: flex;
            gap: 8px;
            align-items: center;
            color: #fff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #1f1f2f;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: rgba(0, 0, 0, 0.4) 0 6px 15px;
        }

        th,
        td {
            padding: 10px 12px;
            text-align: left;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        th {
            background: #1f7071;
            color: #fff;
            text-transform: uppercase;
            font-weight: 700;
        }

        tr:nth-child(even) {
            background: #2a2a3a;
        }

        tr:hover {
            background: #35354a;
        }

        .action-btn {
            padding: 6px 10px;
            border-radius: 6px;
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn.edit {
            background: #17a2b8;
            border: none;
        }

        .action-btn.delete {
            background: #c82333;
            border: none;
        }

        .paginacao {
            margin-top: 12px;
            text-align: center;
            color: #ddd;
        }

        .paginacao a {
            margin: 0 6px;
            color: #fff;
            text-decoration: none;
            background: #0078d7;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .msg-erro {
            color: #ff8a8a;
            margin-bottom: 10px;
        }

        @media (max-width:900px) {
            .main {
                grid-template-columns: 1fr;
            }
        }

        footer {
            margin-top: 30px;
            text-align: center;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            width: inherit;
        }
    </style>
</head>

<body>
    <header>
        <h1><i class="fa-solid fa-clock"></i> Controle de Horas</h1>
        <a href="logout.php" class="btn-sair"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
    </header>

    <h5 style="margin-bottom:12px;">
        <i class="fa-solid fa-user"></i> Usuário: <strong><?php echo h($usuarioLogado); ?></strong> |
        <i class="fa-solid fa-hourglass-half"></i> Total de Horas neste Mês:
        <strong><?php echo h($totalHorasMes); ?></strong>
    </h5>

    <?php if (!empty($erroGeral)): ?>
        <div class="msg-erro"><?php echo h($erroGeral); ?></div>
    <?php endif; ?>

    <div class="main">
        <!-- esquerda: formulários -->
        <div class="left-panel">
            <form method="post" id="formLancamento">
                <h2><i class="fa-solid fa-plus"></i> Novo Lançamento</h2>
                <input type="hidden" name="editar_id" id="editar_id" value="">
                <label><small>Cliente</small></label>
                <input type="text" name="cliente" placeholder="Cliente" required>
                <label><small>Projeto</small></label>
                <input type="text" name="projeto" placeholder="Projeto" required>
                <label><small>Tarefa</small></label>
                <input type="text" name="tarefa" placeholder="Tarefa">
                <label><small>Horas (HH:MM)</small></label>
                <input type="time" name="horas" required>
                <label><small>Observação</small></label>
                <textarea name="observacao" rows="3" placeholder="Observação"></textarea>
                <label><small>Data</small></label>
                <input type="date" name="dlancamento" required>
                <button type="submit" style="margin-top:10px;"><i class="fa-solid fa-save"></i> Salvar</button>
            </form>
            <?php
            // Captura valores dos filtros com fallback
            $mesSelecionado = $_GET['mes'] ?? date('m');
            $anoSelecionado = $_GET['ano'] ?? date('Y');

            // Garante formato correto
            $mesSelecionado = str_pad($mesSelecionado, 2, '0', STR_PAD_LEFT);
            $anoSelecionado = (int) $anoSelecionado;
            ?>

            <?php if ($usuarioLogado === 'Felipe Santos'): ?>
                <form method="post" enctype="multipart/form-data" style="margin-top:8px;">
                    <h2><i class="fa-solid fa-file-import"></i> Importar Planilha</h2>
                    <input type="file" name="arquivo" accept=".csv,.xlsx">
                    <button type="submit" name="importar" style="margin-top:8px;">
                        <i class="fa-solid fa-upload"></i> Importar
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- direita: tabela -->
        <div class="right-panel">
            <h2><i class="fa-solid fa-table"></i> Lançamentos</h2>
            <form method="GET" id="filtroForm"
                style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
                <label for="mes">Mês:</label>
                <select name="mes" id="mes" onchange="document.getElementById('filtroForm').submit()">
                    <?php
                    setlocale(LC_TIME, 'pt_BR.utf8', 'pt_BR', 'Portuguese_Brazil');
                    for ($m = 1; $m <= 12; $m++):
                        $mesNome = ucfirst(strftime('%B', mktime(0, 0, 0, $m, 1)));
                        $mesFormatado = str_pad($m, 2, '0', STR_PAD_LEFT);
                        $selected = ($mesSelecionado == $mesFormatado) ? 'selected' : '';
                        echo "<option value='$mesFormatado' $selected>$mesNome</option>";
                    endfor;
                    ?>
                </select>

                <label for="ano">Ano:</label>
                <select name="ano" id="ano" onchange="document.getElementById('filtroForm').submit()">
                    <?php
                    $anoAtual = date('Y');
                    for ($a = $anoAtual - 5; $a <= $anoAtual + 1; $a++):
                        $selected = ($anoSelecionado == $a) ? 'selected' : '';
                        echo "<option value='$a' $selected>$a</option>";
                    endfor;
                    ?>
                </select>
                <button type="button"
                    onclick="window.location='lancamentos.php?mes=<?php echo date('m'); ?>&ano=<?php echo date('Y'); ?>'"
                    style="padding:6px 10px; border-radius:6px; background:#c82333; color:white; border:none; cursor:pointer;">
                    <i class="fa-solid fa-eraser"></i> Limpar
                </button>


            </form>


            <table>
                <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Projeto</th>
                    <th>Tarefa</th>
                    <th>Horas</th>
                    <th>Observação</th>
                    <th>Data</th>
                    <th>Ações</th>
                </tr>

                <?php if (count($movs) === 0): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:20px; color:#ccc;">Nenhum registro encontrado.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movs as $m): ?>
                        <tr>
                            <td><?php echo h($m['id']); ?></td>
                            <td><?php echo h($m['cliente']); ?></td>
                            <td><?php echo h($m['projeto']); ?></td>
                            <td><?php echo h($m['tarefa']); ?></td>
                            <td><?php echo h($m['horas']); ?></td>
                            <td><?php echo h($m['observacao']); ?></td>
                            <td><?php echo h($m['dlancamento']); ?></td>
                            <td>
                                <a class="action-btn delete" href="?excluir=<?php echo h($m['id']); ?>" title="Excluir"
                                    onclick="return confirm('Confirma exclusão?');"><i class="fa-solid fa-trash"></i></a>

                                <button type="button" class="action-btn edit editarBtn" data-id="<?php echo h($m['id']); ?>"
                                    data-cliente="<?php echo h($m['cliente']); ?>"
                                    data-projeto="<?php echo h($m['projeto']); ?>" data-tarefa="<?php echo h($m['tarefa']); ?>"
                                    data-horas="<?php echo h($m['horas']); ?>"
                                    data-observacao="<?php echo h($m['observacao']); ?>"
                                    data-dlancamento="<?php echo h($m['dlancamento']); ?>" title="Editar"><i
                                        class="fa-solid fa-pen"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>

            <!-- paginação -->
            <div class="paginacao">
                <?php
                // construir query string mantendo filtros
                $qs = [];
                if ($mes)
                    $qs['mes'] = $mes;
                if ($ano)
                    $qs['ano'] = $ano;
                ?>
                <?php if ($pagina > 1): ?>
                    <?php $prev_qs = http_build_query(array_merge($qs, ['pagina' => $pagina - 1])); ?>
                    <a href="?<?php echo $prev_qs; ?>">&laquo; Anterior</a>
                <?php endif; ?>

                <span style="margin:0 10px;">Página <?php echo $pagina; ?> de <?php echo $total_paginas; ?></span>

                <?php if ($pagina < $total_paginas): ?>
                    <?php $next_qs = http_build_query(array_merge($qs, ['pagina' => $pagina + 1])); ?>
                    <a href="?<?php echo $next_qs; ?>">Próxima &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer>
        <p>Sistema desenvolvido por <strong>Felipe Santos</strong> - Action Tech</p>
        <p>&copy; 2025 Todos os direitos reservados</p>
    </footer>

    <script>
        // preencher formulário para edição
        document.querySelectorAll('.editarBtn').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('editar_id').value = this.dataset.id || '';
                document.querySelector('[name=cliente]').value = this.dataset.cliente || '';
                document.querySelector('[name=projeto]').value = this.dataset.projeto || '';
                document.querySelector('[name=tarefa]').value = this.dataset.tarefa || '';
                // some browsers require HH:MM for input type time; keep the value if compatible
                document.querySelector('[name=horas]').value = this.dataset.horas || '';
                document.querySelector('[name=observacao]').value = this.dataset.observacao || '';
                document.querySelector('[name=dlancamento]').value = this.dataset.dlancamento || '';
                // scroll to top where form is
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    </script>

</body>

</html>