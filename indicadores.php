<?php
include('protect.php');

// CONFIGURAÇÃO BANCO
$host = "caboose.proxy.rlwy.net";
$user = "root";
$password = "GXccXsOkyfFEJUBWDwaALivuPWPHwYgP";
$port = 46551;
$db = "railway";

$pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$db;charset=utf8",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$usuario = $_SESSION['nome'];
$mes = $_GET['mes'] ?? date('m');
$ano = $_GET['ano'] ?? date('Y');

$meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

/* CLIENTES */
$stmt = $pdo->prepare("SELECT cliente,SUM(TIME_TO_SEC(horas))/3600 h FROM lancamentos WHERE usuario=:u AND MONTH(dlancamento)=:m AND YEAR(dlancamento)=:a GROUP BY cliente");
$stmt->execute(['u' => $usuario, 'm' => $mes, 'a' => $ano]);
$horasCliente = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* PROJETOS */
$stmt = $pdo->prepare("SELECT projeto,SUM(TIME_TO_SEC(horas))/3600 h FROM lancamentos WHERE usuario=:u AND MONTH(dlancamento)=:m AND YEAR(dlancamento)=:a GROUP BY projeto ORDER BY h DESC LIMIT 5");
$stmt->execute(['u' => $usuario, 'm' => $mes, 'a' => $ano]);
$horasProjeto = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* EVOLUÇÃO */
$stmt = $pdo->prepare("SELECT MONTH(dlancamento) m,SUM(TIME_TO_SEC(horas))/3600 h FROM lancamentos WHERE usuario=:u AND YEAR(dlancamento)=:a GROUP BY MONTH(dlancamento)");
$stmt->execute(['u' => $usuario, 'a' => $ano]);
$raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$evolucao = [];
for ($i = 1; $i <= 12; $i++)
    $evolucao[] = $raw[$i] ?? 0;

/* DETALHAMENTO */
$stmt = $pdo->prepare("SELECT dlancamento,cliente,projeto,tarefa,TIME_TO_SEC(horas) s FROM lancamentos WHERE usuario=:u AND MONTH(dlancamento)=:m AND YEAR(dlancamento)=:a ORDER BY dlancamento");
$stmt->execute(['u' => $usuario, 'm' => $mes, 'a' => $ano]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$semanas = [];
$total = 0;
foreach ($rows as $r) {
    $sem = ceil(date('d', strtotime($r['dlancamento'])) / 7);
    $semanas[$sem]['total'] = ($semanas[$sem]['total'] ?? 0) + $r['s'];
    $semanas[$sem]['linhas'][] = $r;
    $total += $r['s'];
}
function h($s)
{
    return sprintf('%02d:%02d', floor($s / 3600), ($s / 60) % 60);
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Indicadores</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --bg: #121221;
            --card: #1f1f2f;
            --primary: #1f7071;
            --primary-soft: #2fa4a4;
            --text: #ffffffff;
            --muted: #ffffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Segoe UI;
            padding: 14px;
        }

        /* ================= HEADER ================= */

        .page-header {
            background: linear-gradient(135deg, #202323ff, #1a1e27ff);
            color: white;
            padding: 10px 15px;
            border-radius: 12px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 14px;
            box-shadow: rgba(0, 0, 0, 0.4) 0 10px 25px;
        }

        /* esquerda */
        .header-left select {
            background: var(--card);
            color: var(--text);
            border: 1px solid #34344a;
            padding: 8px 12px;
            font-size: .95rem;
            border-radius: 8px;
            margin-right: 6px;
        }

        /* centro */
        .header-center {
            text-align: center;
        }

        .header-center h1 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
        }

        .header-center small {
            color: var(--muted);
            font-size: .90rem;
        }

        /* direita */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: .8rem;
            font-weight: 500;
        }

        .btn-back:hover {
            background: var(--primary-soft);
        }

        /* ================= DASHBOARD ================= */

        .dashboard {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 14px;
            height: calc(92vh - 140px);
        }

        .card {
            background: var(--card);
            border-radius: 12px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .card h3 {
            margin: 0 0 6px;
            font-size: .85rem;
        }

        canvas {
            flex: 1;
            width: 100% !important;
            height: 100% !important;
        }

        /* ================= TABLE ================= */

        .table-wrapper {
            flex: 1;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .75rem;
        }

        th {
            background: var(--primary);
            padding: 6px;
        }

        td {
            padding: 6px;
            border-bottom: 1px solid #2c2c3f;
        }

        .week {
            background: #26263a;
            font-weight: 600;
            cursor: pointer;
        }

        .total-fixo {
            margin-top: 6px;
            padding: 6px;
            background: #1b1b2b;
            border-radius: 6px;
            text-align: right;
            font-weight: 600;
        }

        /* ================= FOOTER ================= */

        footer {
            margin-top: 30px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        /* ================= RESPONSIVO ================= */

        @media (max-width: 1100px) {
            .dashboard {
                grid-template-columns: 1fr 1fr;
                grid-template-rows: auto;
                height: auto;
            }
        }

        @media (max-width: 900px) {
            .page-header {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .header-left,
            .header-right {
                justify-self: center;
            }
        }

        @media (max-width: 700px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <header class="page-header">

        <!-- ESQUERDA: MÊS / ANO -->
        <div class="header-left">
            <form>
                <select name="mes" onchange="this.form.submit()">
                    <?php for ($i = 1; $i <= 12; $i++):
                        $m = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                        <option value="<?= $m ?>" <?= $m == $mes ? 'selected' : '' ?>>
                            <?= $meses[$i - 1] ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <select name="ano" onchange="this.form.submit()">
                    <?php for ($a = date('Y') - 2; $a <= date('Y'); $a++): ?>
                        <option value="<?= $a ?>" <?= $a == $ano ? 'selected' : '' ?>>
                            <?= $a ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </form>
            <small>
                <i class="fa-solid fa-user"></i>
                Consultor: <strong><?= $usuario ?></strong>
            </small>
        </div>

        <!-- CENTRO: TÍTULO -->
        <div class="header-center">
            <h1>
                <i class="fa-solid fa-clock"></i>
                Indicadores de Horas
            </h1>
        </div>

        <a href="lancamentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Voltar
        </a>


    </header>


    <div class="dashboard">

        <div class="card">
            <h3>Horas por Cliente</h3><canvas id="c1"></canvas>
        </div>
        <div class="card">
            <h3>Top Projetos</h3><canvas id="c2"></canvas>
        </div>

        <div class="card">
            <h3>Detalhamento Semanal</h3>
            <div class="table-wrapper">
                <table>
                    <tr>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Projeto</th>
                        <th>Tarefa</th>
                        <th>Horas</th>
                    </tr>
                    <?php foreach ($semanas as $s => $i): ?>
                        <tr class="week" onclick="t(<?= $s ?>)">
                            <td colspan="5">▶ <?= $s ?>ª Semana — Total <?= h($i['total']) ?></td>
                        </tr>
                        <tbody id="w<?= $s ?>" style="display:none">
                            <?php foreach ($i['linhas'] as $l): ?>
                                <tr>
                                    <td><?= date('d/m', strtotime($l['dlancamento'])) ?></td>
                                    <td><?= $l['cliente'] ?></td>
                                    <td><?= $l['projeto'] ?></td>
                                    <td><?= $l['tarefa'] ?></td>
                                    <td><?= h($l['s']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="total-fixo">Total Geral: <?= h($total) ?></div>
        </div>

        <div class="card">
            <h3>Evolução Mensal</h3><canvas id="c3"></canvas>
        </div>

    </div>

    <script>
        function t(w) {
            const e = document.getElementById('w' + w);
            e.style.display = e.style.display === 'none' ? 'table-row-group' : 'none';
        }

        new Chart(c1, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($horasCliente, 'cliente')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($horasCliente, 'h')) ?>,
                    backgroundColor: '#1f7071',
                    borderColor: '#2aa6a6',
                    borderWidth: 1,
                    hoverBackgroundColor: '#2aa6a6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#e0e0e0' }, grid: { color: '#2c2c3f' } },
                    y: { ticks: { color: '#e0e0e0' }, grid: { color: '#2c2c3f' } }
                }
            }
        });
        new Chart(c2, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($horasProjeto, 'projeto')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($horasProjeto, 'h')) ?>,
                    backgroundColor: '#1f7071',
                    borderColor: '#2aa6a6',
                    borderWidth: 1,
                    hoverBackgroundColor: '#2aa6a6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#e0e0e0' }, grid: { color: '#2c2c3f' } },
                    y: { ticks: { color: '#e0e0e0' }, grid: { color: '#2c2c3f' } }
                }
            }
        });
        new Chart(c3, {
            type: 'bar',
            data: {
                labels: <?= json_encode($meses) ?>,
                datasets: [{
                    data: <?= json_encode($evolucao) ?>,
                    backgroundColor: '#1f7071',
                    borderColor: '#2aa6a6',
                    borderWidth: 1,
                    hoverBackgroundColor: '#2aa6a6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#e0e0e0' }, grid: { color: '#2c2c3f' } },
                    y: { ticks: { color: '#e0e0e0' }, grid: { color: '#2c2c3f' } }
                }
            }
        });
    </script>

    <footer>
        <p>Sistema desenvolvido por <strong>Felipe Santos</strong> - Action Tech</p>
        <p>&copy; 2025 Todos os direitos reservados</p>
    </footer>

</body>

</html>