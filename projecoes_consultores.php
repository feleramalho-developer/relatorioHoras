<?php
include('protect.php');

// ===============================
// CONEXÃO COM BANCO
// Use os mesmos dados do seu sistema atual
// ===============================
$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

$usuarioLogado = $_SESSION['nome'] ?? null;

if (!$usuarioLogado) {
    header("Location: login.php");
    exit;
}

function h($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function horasDecimalParaHHMM($decimal) {
    $decimal = (float) $decimal;
    $horas = floor($decimal);
    $minutos = round(($decimal - $horas) * 60);

    if ($minutos == 60) {
        $horas++;
        $minutos = 0;
    }

    return sprintf('%02d:%02d', $horas, $minutos);
}

// ===============================
// PERMISSÃO: SOMENTE liberação 5
// ===============================
$podeAcessar = false;

try {
    $stmtPermissao = $pdo->prepare("
        SELECT liberacao 
        FROM usuario 
        WHERE nome = :nome 
        LIMIT 1
    ");
    $stmtPermissao->execute([':nome' => $usuarioLogado]);
    $liberacaoUsuario = $stmtPermissao->fetchColumn();

    if ((int) $liberacaoUsuario === 5) {
        $podeAcessar = true;
    }
} catch (Exception $e) {
    $podeAcessar = false;
}

if (!$podeAcessar) {
    echo "<h2 style='color:white; background:#121221; padding:30px; font-family:Arial;'>
            Acesso negado. Você não tem permissão para lançar projeções.
          </h2>";
    exit;
}

// ===============================
// FILTROS PADRÃO
// ===============================
$mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int) $_GET['mes'] : (int) date('m');
$ano = isset($_GET['ano']) && $_GET['ano'] !== '' ? (int) $_GET['ano'] : (int) date('Y');
$consultorFiltro = $_GET['consultor'] ?? '';
$clienteFiltro = $_GET['cliente'] ?? '';

// ===============================
// LISTA CONSULTORES
// Usa tabela usuario
// ===============================
$stmtConsultores = $pdo->query("
    SELECT nome 
    FROM usuario 
    WHERE nome IS NOT NULL 
    AND nome <> ''
    ORDER BY nome
");
$consultores = $stmtConsultores->fetchAll(PDO::FETCH_COLUMN);

// ===============================
// LISTA CLIENTES ATIVOS
// ===============================
$stmtClientes = $pdo->query("
    SELECT cliente 
    FROM clientes_consultoria 
    WHERE status = 'Ativo'
    AND cliente IS NOT NULL
    AND cliente <> ''
    ORDER BY cliente
");
$clientes = $stmtClientes->fetchAll(PDO::FETCH_COLUMN);

// ===============================
// EXCLUIR
// ===============================
if (isset($_GET['excluir'])) {
    $idExcluir = (int) $_GET['excluir'];

    try {
        $stmtDel = $pdo->prepare("DELETE FROM projecoes_consultores WHERE id = :id");
        $stmtDel->execute([':id' => $idExcluir]);

        header("Location: projecoes_consultores.php?mes=$mes&ano=$ano");
        exit;
    } catch (Exception $e) {
        $erroGeral = "Erro ao excluir projeção: " . $e->getMessage();
    }
}

// ===============================
// INSERIR / EDITAR
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editarId = $_POST['editar_id'] ?? '';
    $consultor = trim($_POST['consultor'] ?? '');
    $cliente = trim($_POST['cliente'] ?? '');
    $projeto = trim($_POST['projeto'] ?? '');
    $mesPost = (int) ($_POST['mes'] ?? date('m'));
    $anoPost = (int) ($_POST['ano'] ?? date('Y'));
    $horasProjetadas = str_replace(',', '.', trim($_POST['horas_projetadas'] ?? '0'));
    $observacao = trim($_POST['observacao'] ?? '');

    if ($consultor === '' || $cliente === '' || $mesPost <= 0 || $anoPost <= 0 || $horasProjetadas === '') {
        $erroGeral = "Preencha consultor, cliente, mês, ano e horas projetadas.";
    } else {
        try {
            if (!empty($editarId)) {
                $stmt = $pdo->prepare("
                    UPDATE projecoes_consultores
                    SET consultor = :consultor,
                        cliente = :cliente,
                        projeto = :projeto,
                        mes = :mes,
                        ano = :ano,
                        horas_projetadas = :horas_projetadas,
                        observacao = :observacao
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':consultor' => $consultor,
                    ':cliente' => $cliente,
                    ':projeto' => $projeto,
                    ':mes' => $mesPost,
                    ':ano' => $anoPost,
                    ':horas_projetadas' => $horasProjetadas,
                    ':observacao' => $observacao,
                    ':id' => $editarId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO projecoes_consultores
                    (consultor, cliente, projeto, mes, ano, horas_projetadas, observacao, criado_por)
                    VALUES
                    (:consultor, :cliente, :projeto, :mes, :ano, :horas_projetadas, :observacao, :criado_por)
                ");

                $stmt->execute([
                    ':consultor' => $consultor,
                    ':cliente' => $cliente,
                    ':projeto' => $projeto,
                    ':mes' => $mesPost,
                    ':ano' => $anoPost,
                    ':horas_projetadas' => $horasProjetadas,
                    ':observacao' => $observacao,
                    ':criado_por' => $usuarioLogado
                ]);
            }

            header("Location: projecoes_consultores.php?mes=$mesPost&ano=$anoPost");
            exit;
        } catch (Exception $e) {
            $erroGeral = "Erro ao salvar projeção: " . $e->getMessage();
        }
    }
}

// ===============================
// CONSULTA DAS PROJEÇÕES
// ===============================
$where = " WHERE mes = :mes AND ano = :ano ";
$params = [
    ':mes' => $mes,
    ':ano' => $ano
];

if ($consultorFiltro !== '') {
    $where .= " AND consultor = :consultor ";
    $params[':consultor'] = $consultorFiltro;
}

if ($clienteFiltro !== '') {
    $where .= " AND cliente = :cliente ";
    $params[':cliente'] = $clienteFiltro;
}

$stmtProj = $pdo->prepare("
    SELECT *
    FROM projecoes_consultores
    $where
    ORDER BY consultor, cliente, projeto
");
$stmtProj->execute($params);
$projecoes = $stmtProj->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// CARDS
// ===============================
$totalProjetado = 0;
$totalRegistros = count($projecoes);
$consultoresUnicos = [];
$clientesUnicos = [];

foreach ($projecoes as $p) {
    $totalProjetado += (float) $p['horas_projetadas'];
    $consultoresUnicos[$p['consultor']] = true;
    $clientesUnicos[$p['cliente']] = true;
}

$totalConsultores = count($consultoresUnicos);
$totalClientes = count($clientesUnicos);

?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <title>Projeções de Consultores</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", sans-serif;
        }

        body {
            background: #121221;
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

        .menu {
            display: flex;
            gap: 10px;
        }

        .btn {
            color: #fff;
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
        }

        .btn-verde {
            background: #008070;
        }

        .btn-azul {
            background: #0078d7;
        }

        .btn-vermelho {
            background: #c82333;
        }

        .main {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 20px;
            align-items: start;
        }

        .panel {
            background: #1f1f2f;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 14px;
            box-shadow: rgba(0,0,0,0.35) 0 8px 20px;
        }

        .panel h2 {
            font-size: 1rem;
            color: #fff;
            margin-bottom: 10px;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        label {
            display: block;
            font-size: 12px;
            margin-top: 8px;
            margin-bottom: 4px;
        }

        input,
        select,
        textarea,
        button {
            width: 100%;
            margin-top: 4px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #444;
            background: #2b2b3b;
            color: #fff;
        }

        button {
            background: #0078d7;
            border: none;
            font-weight: 700;
            cursor: pointer;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 16px;
        }

        .card {
            background: #1f1f2f;
            padding: 16px;
            border-radius: 10px;
            box-shadow: rgba(0,0,0,0.35) 0 8px 20px;
        }

        .card small {
            color: #cfcfcf;
            font-size: 12px;
        }

        .card strong {
            display: block;
            margin-top: 8px;
            font-size: 24px;
            color: #008b7a;
        }

        .filtros {
            display: flex;
            gap: 10px;
            align-items: end;
            margin-bottom: 16px;
            background: #1f1f2f;
            padding: 14px;
            border-radius: 10px;
        }

        .filtros div {
            flex: 1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #1f1f2f;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: rgba(0,0,0,0.35) 0 8px 20px;
        }

        th,
        td {
            padding: 10px 12px;
            text-align: left;
            font-size: 0.88rem;
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
            padding: 6px 9px;
            border-radius: 6px;
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            width: auto;
        }

        .edit {
            background: #17a2b8;
        }

        .delete {
            background: #c82333;
        }

        .msg-erro {
            background: rgba(200, 35, 51, .15);
            border: 1px solid #c82333;
            color: #ffb3b3;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 14px;
        }

        @media(max-width: 1000px) {
            .main,
            .cards {
                grid-template-columns: 1fr;
            }

            .filtros {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header>
    <h1><i class="fa-solid fa-chart-line"></i> Projeções de Consultores</h1>

    <div class="menu">
        <a href="indicadores_operacao.php" class="btn btn-verde">
            <i class="fa-solid fa-chart-pie"></i> KPI Gestão
        </a>

        <a href="lancamentos.php" class="btn btn-azul">
            <i class="fa-solid fa-clock"></i> Lançamentos
        </a>

        <a href="logout.php" class="btn btn-vermelho">
            <i class="fa-solid fa-right-from-bracket"></i> Sair
        </a>
    </div>
</header>

<?php if (!empty($erroGeral)): ?>
    <div class="msg-erro"><?php echo h($erroGeral); ?></div>
<?php endif; ?>

<div class="cards">
    <div class="card">
        <small>Total Projetado</small>
        <strong><?php echo horasDecimalParaHHMM($totalProjetado); ?></strong>
    </div>

    <div class="card">
        <small>Consultores Planejados</small>
        <strong><?php echo $totalConsultores; ?></strong>
    </div>

    <div class="card">
        <small>Clientes Planejados</small>
        <strong><?php echo $totalClientes; ?></strong>
    </div>

    <div class="card">
        <small>Registros</small>
        <strong><?php echo $totalRegistros; ?></strong>
    </div>
</div>

<div class="main">

    <div>
        <div class="panel">
            <h2><i class="fa-solid fa-plus"></i> Nova Projeção</h2>

            <form method="POST" id="formProjecao">
                <input type="hidden" name="editar_id" id="editar_id">

                <label>Consultor</label>
                <select name="consultor" required>
                    <option value="">Selecione o consultor</option>
                    <?php foreach ($consultores as $consultor): ?>
                        <option value="<?php echo h($consultor); ?>">
                            <?php echo h($consultor); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Cliente</label>
                <select name="cliente" required>
                    <option value="">Selecione o cliente</option>
                    <?php foreach ($clientes as $cliente): ?>
                        <option value="<?php echo h($cliente); ?>">
                            <?php echo h($cliente); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Projeto</label>
                <input type="text" name="projeto" placeholder="Projeto ou frente">

                <label>Mês</label>
                <select name="mes" required>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $mes == $m ? 'selected' : ''; ?>>
                            <?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label>Ano</label>
                <select name="ano" required>
                    <?php for ($a = date('Y') - 2; $a <= date('Y') + 2; $a++): ?>
                        <option value="<?php echo $a; ?>" <?php echo $ano == $a ? 'selected' : ''; ?>>
                            <?php echo $a; ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label>Horas Projetadas</label>
                <input type="number" step="0.25" min="0" name="horas_projetadas" placeholder="Ex: 40 ou 40.5" required>

                <label>Observação</label>
                <textarea name="observacao" rows="3" placeholder="Observação da projeção"></textarea>

                <button type="submit" style="margin-top:10px;">
                    <i class="fa-solid fa-save"></i> Salvar Projeção
                </button>
            </form>
        </div>
    </div>

    <div>
        <form method="GET" class="filtros">
            <div>
                <label>Mês</label>
                <select name="mes">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $mes == $m ? 'selected' : ''; ?>>
                            <?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div>
                <label>Ano</label>
                <select name="ano">
                    <?php for ($a = date('Y') - 2; $a <= date('Y') + 2; $a++): ?>
                        <option value="<?php echo $a; ?>" <?php echo $ano == $a ? 'selected' : ''; ?>>
                            <?php echo $a; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div>
                <label>Consultor</label>
                <select name="consultor">
                    <option value="">Todos</option>
                    <?php foreach ($consultores as $consultor): ?>
                        <option value="<?php echo h($consultor); ?>" <?php echo $consultorFiltro == $consultor ? 'selected' : ''; ?>>
                            <?php echo h($consultor); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Cliente</label>
                <select name="cliente">
                    <option value="">Todos</option>
                    <?php foreach ($clientes as $cliente): ?>
                        <option value="<?php echo h($cliente); ?>" <?php echo $clienteFiltro == $cliente ? 'selected' : ''; ?>>
                            <?php echo h($cliente); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit">
                    <i class="fa-solid fa-filter"></i> Filtrar
                </button>
            </div>
        </form>

        <table>
            <tr>
                <th>ID</th>
                <th>Consultor</th>
                <th>Cliente</th>
                <th>Projeto</th>
                <th>Mês/Ano</th>
                <th>Horas Projetadas</th>
                <th>Observação</th>
                <th>Ações</th>
            </tr>

            <?php if (count($projecoes) === 0): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:20px;">
                        Nenhuma projeção encontrada.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($projecoes as $p): ?>
                    <tr>
                        <td><?php echo h($p['id']); ?></td>
                        <td><?php echo h($p['consultor']); ?></td>
                        <td><?php echo h($p['cliente']); ?></td>
                        <td><?php echo h($p['projeto']); ?></td>
                        <td><?php echo str_pad($p['mes'], 2, '0', STR_PAD_LEFT) . '/' . h($p['ano']); ?></td>
                        <td><?php echo horasDecimalParaHHMM($p['horas_projetadas']); ?></td>
                        <td><?php echo h($p['observacao']); ?></td>
                        <td>
                            <button 
                                type="button"
                                class="action-btn edit editarBtn"
                                data-id="<?php echo h($p['id']); ?>"
                                data-consultor="<?php echo h($p['consultor']); ?>"
                                data-cliente="<?php echo h($p['cliente']); ?>"
                                data-projeto="<?php echo h($p['projeto']); ?>"
                                data-mes="<?php echo h($p['mes']); ?>"
                                data-ano="<?php echo h($p['ano']); ?>"
                                data-horas="<?php echo h($p['horas_projetadas']); ?>"
                                data-observacao="<?php echo h($p['observacao']); ?>"
                                title="Editar"
                            >
                                <i class="fa-solid fa-pen"></i>
                            </button>

                            <a 
                                class="action-btn delete"
                                href="projecoes_consultores.php?excluir=<?php echo h($p['id']); ?>&mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>"
                                onclick="return confirm('Confirma exclusão desta projeção?');"
                                title="Excluir"
                            >
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>

</div>

<script>
document.querySelectorAll('.editarBtn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('editar_id').value = this.dataset.id || '';

        document.querySelector('[name=consultor]').value = this.dataset.consultor || '';
        document.querySelector('[name=cliente]').value = this.dataset.cliente || '';
        document.querySelector('[name=projeto]').value = this.dataset.projeto || '';
        document.querySelector('[name=mes]').value = this.dataset.mes || '';
        document.querySelector('[name=ano]').value = this.dataset.ano || '';
        document.querySelector('[name=horas_projetadas]').value = this.dataset.horas || '';
        document.querySelector('[name=observacao]').value = this.dataset.observacao || '';

        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>

</body>
</html>