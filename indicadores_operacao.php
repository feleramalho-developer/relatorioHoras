<?php

include('protect.php');

// ===============================

// CONEXÃO COM BANCO

// ===============================

require_once __DIR__ . '/conexao.php';

$usuarioLogado = $_SESSION['nome'] ?? null;

if (!$usuarioLogado) {

    header("Location: login.php");

    exit;

}

// Bloqueia acesso direto para usuários sem permissão

try {

    $stmtPermissao = $pdo->prepare("

        SELECT liberacao 

        FROM usuario 

        WHERE nome = :nome 

        LIMIT 1

    ");

    $stmtPermissao->execute([

        ':nome' => $usuarioLogado

    ]);

    $liberacaoUsuario = $stmtPermissao->fetchColumn();

    if ((int) $liberacaoUsuario !== 5) {

        echo "<h2 style='color:white; background:#121221; padding:30px; font-family:Arial;'>

                Acesso negado. Você não tem permissão para visualizar os indicadores.

              </h2>";

        exit;

    }

} catch (Exception $e) {

    echo "<h2 style='color:white; background:#121221; padding:30px; font-family:Arial;'>

            Erro ao validar permissão de acesso.

          </h2>";

    exit;

}

function h($v)
{

    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

}

function textoMinusculoSeguro($v)
{

    return function_exists('mb_strtolower')

        ? mb_strtolower(trim((string) $v), 'UTF-8')

        : strtolower(trim((string) $v));

}

function isActionProcess($cliente)
{

    return textoMinusculoSeguro($cliente) === 'action process';

}

function horasDecimalParaHHMM($decimal)
{

    $decimal = (float) $decimal;

    $negativo = $decimal < 0;

    $decimal = abs($decimal);

    $horas = floor($decimal);

    $minutos = round(($decimal - $horas) * 60);

    if ($minutos == 60) {

        $horas++;

        $minutos = 0;

    }

    return ($negativo ? '-' : '') . sprintf('%02d:%02d', $horas, $minutos);

}

function dataBR($data)
{

    if (empty($data)) {

        return '-';

    }

    return date('d/m/Y', strtotime($data));

}

// ===============================

// FILTROS

// ===============================

$mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int) $_GET['mes'] : (int) date('m');

$ano = isset($_GET['ano']) && $_GET['ano'] !== '' ? (int) $_GET['ano'] : (int) date('Y');

$clienteFiltro = $_GET['cliente'] ?? '';

$consultorFiltro = $_GET['consultor'] ?? '';

$clienteActionProcess = isActionProcess($clienteFiltro);

$filtroFaturavelPrincipal = $clienteActionProcess ? 0 : 1;

$labelHorasPrincipal = $clienteActionProcess ? 'Horas Internas' : 'Horas Faturáveis';

$labelHorasPrincipalCard = $clienteActionProcess ? 'Horas Internas Action Process' : 'Horas Lançadas Faturáveis';

$labelDetalhamento = $clienteActionProcess ? 'Internas' : 'Fat.';

// ===============================

// CLIENTES PARA FILTRO

// Mostra somente clientes com lançamento no mês/ano selecionado.

// Se houver consultor selecionado, também filtra os clientes desse consultor.

// ===============================

$sqlClientesFiltro = "

    SELECT DISTINCT cliente

    FROM lancamentos

    WHERE cliente IS NOT NULL

    AND cliente <> ''

    AND MONTH(dlancamento) = :mes_cliente

    AND YEAR(dlancamento) = :ano_cliente

";

$paramsClientesFiltro = [

    ':mes_cliente' => $mes,

    ':ano_cliente' => $ano

];

if ($consultorFiltro !== '') {

    $sqlClientesFiltro .= " AND usuario = :consultor_cliente ";

    $paramsClientesFiltro[':consultor_cliente'] = $consultorFiltro;

}

$sqlClientesFiltro .= " ORDER BY cliente ";

$stmtClientes = $pdo->prepare($sqlClientesFiltro);

$stmtClientes->execute($paramsClientesFiltro);

$clientes = $stmtClientes->fetchAll(PDO::FETCH_COLUMN);

// Mantém a opção selecionada visível caso exista no GET, mesmo em troca de filtros.

if ($clienteFiltro !== '' && !in_array($clienteFiltro, $clientes, true)) {

    array_unshift($clientes, $clienteFiltro);

}

// ===============================

// CONSULTORES PARA FILTRO

// Mostra somente consultores com lançamento no mês/ano selecionado.

// Se houver cliente selecionado, também filtra os consultores desse cliente.

// ===============================

$sqlConsultoresFiltro = "

    SELECT DISTINCT usuario

    FROM lancamentos

    WHERE usuario IS NOT NULL

    AND usuario <> ''

    AND MONTH(dlancamento) = :mes_consultor

    AND YEAR(dlancamento) = :ano_consultor

";

$paramsConsultoresFiltro = [

    ':mes_consultor' => $mes,

    ':ano_consultor' => $ano

];

if ($clienteFiltro !== '') {

    $sqlConsultoresFiltro .= " AND cliente = :cliente_consultor ";

    $paramsConsultoresFiltro[':cliente_consultor'] = $clienteFiltro;

}

$sqlConsultoresFiltro .= " ORDER BY usuario ";

$stmtConsultores = $pdo->prepare($sqlConsultoresFiltro);

$stmtConsultores->execute($paramsConsultoresFiltro);

$consultores = $stmtConsultores->fetchAll(PDO::FETCH_COLUMN);

// Mantém a opção selecionada visível caso exista no GET, mesmo em troca de filtros.

if ($consultorFiltro !== '' && !in_array($consultorFiltro, $consultores, true)) {

    array_unshift($consultores, $consultorFiltro);

}

// ===============================

// RESUMO POR CLIENTE

// ===============================

$sqlResumo = "

    SELECT 

        c.id,

        c.cliente,

        c.atuacao,

        c.cidade_uf,

        c.produto_servico,

        c.visitas_mensais,

        c.horas_mes,

        c.valor_mensal,

        c.contato_responsavel,

        c.data_inicio,

        c.data_vencimento,

        c.segmento,

        c.status,

        COALESCE(SUM(CASE WHEN COALESCE(l.faturavel, 0) = 1 THEN TIME_TO_SEC(l.horas) ELSE 0 END) / 3600, 0) AS horas_lancadas,

        COALESCE(SUM(CASE WHEN COALESCE(l.faturavel, 0) = 0 THEN TIME_TO_SEC(l.horas) ELSE 0 END) / 3600, 0) AS horas_nao_faturaveis,

        COALESCE(SUM(TIME_TO_SEC(l.horas)) / 3600, 0) AS horas_total_lancadas,

        c.horas_mes - COALESCE(SUM(CASE WHEN COALESCE(l.faturavel, 0) = 1 THEN TIME_TO_SEC(l.horas) ELSE 0 END) / 3600, 0) AS saldo_horas,

        CASE 

            WHEN c.horas_mes > 0 THEN 

                (COALESCE(SUM(CASE WHEN COALESCE(l.faturavel, 0) = 1 THEN TIME_TO_SEC(l.horas) ELSE 0 END) / 3600, 0) / c.horas_mes) * 100

            ELSE 0

        END AS percentual_consumido,

        CASE 

            WHEN c.horas_mes > 0 THEN 

                c.valor_mensal / c.horas_mes

            ELSE 0

        END AS valor_hora

    FROM clientes_consultoria c

    LEFT JOIN lancamentos l 

        ON l.cliente = c.cliente

        AND MONTH(l.dlancamento) = :mes

        AND YEAR(l.dlancamento) = :ano

";

$params = [

    ':mes' => $mes,

    ':ano' => $ano

];

$where = " WHERE (c.status = 'Ativo' OR TRIM(c.cliente) = 'Action Process') ";

if ($clienteFiltro !== '') {

    $where .= " AND c.cliente = :cliente ";

    $params[':cliente'] = $clienteFiltro;

}

if ($consultorFiltro !== '') {

    $where .= " AND l.usuario = :consultor ";

    $params[':consultor'] = $consultorFiltro;

}

$sqlResumo .= $where;

$sqlResumo .= "

    GROUP BY 

        c.id,

        c.cliente,

        c.atuacao,

        c.cidade_uf,

        c.produto_servico,

        c.visitas_mensais,

        c.horas_mes,

        c.valor_mensal,

        c.contato_responsavel,

        c.data_inicio,

        c.data_vencimento,

        c.segmento,

        c.status

    ORDER BY percentual_consumido DESC

";

$stmt = $pdo->prepare($sqlResumo);

$stmt->execute($params);

$resumoClientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================

// RESUMO HISTÓRICO PARA CARDS SUPERIORES

// Calcula contratos ativos e lançamentos acumulados da data de início do projeto

// até o último dia do mês/ano selecionado.

// Regras:
// - Quando não há cliente selecionado: considera somente contratos ativos e exclui Action Process.
// - Quando o cliente selecionado é Action Process: contabiliza horas internas/não faturáveis.
// - Saldo e % consumido consideram somente horas faturáveis para clientes de contrato.
// - Action Process não entra como contrato/saldo, pois representa horas internas.

// ===============================

$dataInicioMesSelecionado = sprintf('%04d-%02d-01', $ano, $mes);

$dataFimMesSelecionado = date('Y-m-t', strtotime($dataInicioMesSelecionado));

$resumoHistoricoCards = [];

$sqlClientesCards = "
    SELECT
        id,
        cliente,
        horas_mes,
        valor_mensal,
        data_inicio,
        status
    FROM clientes_consultoria
    WHERE 1 = 1
";

$paramsClientesCards = [];

if ($clienteFiltro !== '') {
    $sqlClientesCards .= " AND cliente = :cliente_card ";
    $paramsClientesCards[':cliente_card'] = $clienteFiltro;
} else {
    $sqlClientesCards .= " AND status = 'Ativo' AND TRIM(cliente) <> 'Action Process' ";
}

$sqlClientesCards .= " ORDER BY cliente ";

$stmtClientesCards = $pdo->prepare($sqlClientesCards);
$stmtClientesCards->execute($paramsClientesCards);
$clientesCards = $stmtClientesCards->fetchAll(PDO::FETCH_ASSOC);

$stmtLancamentosHistorico = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN COALESCE(faturavel, 0) = 1 THEN TIME_TO_SEC(horas) ELSE 0 END) / 3600, 0) AS horas_lancadas_periodo,
        COALESCE(SUM(CASE WHEN COALESCE(faturavel, 0) = 0 THEN TIME_TO_SEC(horas) ELSE 0 END) / 3600, 0) AS horas_nao_faturaveis_periodo,
        COALESCE(SUM(TIME_TO_SEC(horas)) / 3600, 0) AS horas_total_lancadas_periodo
    FROM lancamentos
    WHERE cliente = :cliente_hist
    AND dlancamento >= :data_inicio_hist
    AND dlancamento <= :data_fim_hist
    " . ($consultorFiltro !== '' ? " AND usuario = :consultor_hist " : "") . "
");

foreach ($clientesCards as $clienteResumo) {
    $nomeClienteCard = $clienteResumo['cliente'] ?? '';
    $isClienteCardActionProcess = isActionProcess($nomeClienteCard);
    $dataInicioCliente = $clienteResumo['data_inicio'] ?? null;

    if (empty($dataInicioCliente) || $dataInicioCliente === '0000-00-00') {
        $dataInicioHistorico = $dataInicioMesSelecionado;
        $mesesContratados = $isClienteCardActionProcess ? 0 : 1;
    } else {
        $dataInicioHistorico = $dataInicioCliente;

        if (strtotime($dataInicioCliente) > strtotime($dataFimMesSelecionado)) {
            $mesesContratados = 0;
        } else {
            $inicioContratoMes = new DateTime(date('Y-m-01', strtotime($dataInicioCliente)));
            $mesReferencia = new DateTime($dataInicioMesSelecionado);
            $diffMeses = $inicioContratoMes->diff($mesReferencia);
            $mesesContratados = ($diffMeses->y * 12) + $diffMeses->m + 1;
        }

        if ($isClienteCardActionProcess) {
            $mesesContratados = 0;
        }
    }

    $paramsLancamentosHistorico = [
        ':cliente_hist' => $nomeClienteCard,
        ':data_inicio_hist' => $dataInicioHistorico,
        ':data_fim_hist' => $dataFimMesSelecionado
    ];

    if ($consultorFiltro !== '') {
        $paramsLancamentosHistorico[':consultor_hist'] = $consultorFiltro;
    }

    $stmtLancamentosHistorico->execute($paramsLancamentosHistorico);
    $hist = $stmtLancamentosHistorico->fetch(PDO::FETCH_ASSOC) ?: [];

    $resumoHistoricoCards[] = [
        'id' => $clienteResumo['id'] ?? null,
        'cliente' => $nomeClienteCard,
        'horas_mes' => (float) ($clienteResumo['horas_mes'] ?? 0),
        'valor_mensal' => (float) ($clienteResumo['valor_mensal'] ?? 0),
        'data_inicio' => $dataInicioCliente,
        'meses_contratados' => $mesesContratados,
        'horas_contratadas_periodo' => $isClienteCardActionProcess ? 0 : ((float) ($clienteResumo['horas_mes'] ?? 0)) * $mesesContratados,
        'horas_lancadas_periodo' => (float) ($hist['horas_lancadas_periodo'] ?? 0),
        'horas_nao_faturaveis_periodo' => (float) ($hist['horas_nao_faturaveis_periodo'] ?? 0),
        'horas_total_lancadas_periodo' => (float) ($hist['horas_total_lancadas_periodo'] ?? 0),
        'is_action_process' => $isClienteCardActionProcess
    ];
}

// ===============================

// CARDS

// ===============================

// Os cards superiores usam visão acumulada: contratos ativos da data de início

// até o último dia do mês/ano selecionado. Action Process só entra quando selecionado.

$totalContratadas = 0;

$totalLancadas = 0; // faturáveis para clientes / internas para Action Process selecionado

$totalNaoFaturadas = 0;

$totalGeralLancadas = 0;

$totalValorMensal = 0;

$clientesAcimaContrato = 0;

$clientesSemLancamento = 0;

foreach ($resumoHistoricoCards as $c) {
    $isCardActionProcess = !empty($c['is_action_process']);
    $horasContratadasPeriodo = (float) $c['horas_contratadas_periodo'];
    $horasFaturaveisPeriodo = (float) $c['horas_lancadas_periodo'];
    $horasNaoFaturaveisPeriodo = (float) $c['horas_nao_faturaveis_periodo'];
    $horasTotalPeriodo = (float) $c['horas_total_lancadas_periodo'];

    $horasPrincipalPeriodo = $isCardActionProcess
        ? $horasNaoFaturaveisPeriodo
        : $horasFaturaveisPeriodo;

    $saldoClientePeriodo = $isCardActionProcess
        ? 0
        : $horasContratadasPeriodo - $horasFaturaveisPeriodo;

    $totalContratadas += $horasContratadasPeriodo;
    $totalLancadas += $horasPrincipalPeriodo;
    $totalNaoFaturadas += $horasNaoFaturaveisPeriodo;
    $totalGeralLancadas += $horasTotalPeriodo;

    if (!$isCardActionProcess && $saldoClientePeriodo < 0) {
        $clientesAcimaContrato++;
    }

    if ($horasPrincipalPeriodo == 0) {
        $clientesSemLancamento++;
    }
}

// Valor mensal permanece conforme contratos exibidos na visão mensal atual.
foreach ($resumoHistoricoCards as $c) {
    if (empty($c['is_action_process'])) {
        $totalValorMensal += (float) $c['valor_mensal'];
    }
}

$saldoTotal = $clienteActionProcess
    ? 0
    : $totalContratadas - $totalLancadas;

$percentualConsumido = (!$clienteActionProcess && $totalContratadas > 0)
    ? ($totalLancadas / $totalContratadas) * 100
    : 0;

$clientesAtivos = count($resumoHistoricoCards);

// ===============================

// CAPACIDADE OPERACIONAL

// Considera somente consultores ativos na operação.

// Exclui o CEO Guilherme e desconsidera Action Process das horas contratadas.

// ===============================

try {

    $stmtCapacidadeOperacional = $pdo->prepare("

        SELECT COUNT(*)

        FROM usuario

        WHERE statusConsultor = 'Ativo'

        AND email <> :email_ceo

    ");

    $stmtCapacidadeOperacional->execute([

        ':email_ceo' => 'guilherme@actionpconsultoria.com.br'

    ]);

    $consultoresAtivosOperacao = (int) $stmtCapacidadeOperacional->fetchColumn();

} catch (Exception $e) {

    $consultoresAtivosOperacao = 0;

}

$capacidadeHorasPorConsultor = 160;

$capacidadeOperacionalHoras = $consultoresAtivosOperacao * $capacidadeHorasPorConsultor;

try {

    $stmtHorasContratadasOperacao = $pdo->query("

        SELECT COALESCE(SUM(horas_mes), 0)

        FROM clientes_consultoria

        WHERE status = 'Ativo'

        AND TRIM(cliente) <> 'Action Process'

    ");

    $horasContratadasOperacao = (float) $stmtHorasContratadasOperacao->fetchColumn();

} catch (Exception $e) {

    $horasContratadasOperacao = 0;

}

$percentualTimeAlocado = $capacidadeOperacionalHoras > 0

    ? ($horasContratadasOperacao / $capacidadeOperacionalHoras) * 100

    : 0;

// ===============================

// HORAS POR CLIENTE

// ===============================

$labelsCliente = [];

$valoresCliente = [];

$labelsClienteNaoFat = [];

$valoresClienteNaoFat = [];

foreach ($resumoClientes as $c) {

    $labelsCliente[] = $c['cliente'];

    $valorPrincipalCliente = $clienteActionProcess ? (float) $c['horas_nao_faturaveis'] : (float) $c['horas_lancadas'];

    $valoresCliente[] = round($valorPrincipalCliente, 2);

    $labelsClienteNaoFat[] = $c['cliente'];

    $valoresClienteNaoFat[] = round((float) $c['horas_nao_faturaveis'], 2);

}

// ===============================

// HORAS POR CONSULTOR

// ===============================

$sqlConsultor = "

    SELECT 

        usuario,

        COALESCE(SUM(TIME_TO_SEC(horas)) / 3600, 0) AS total

    FROM lancamentos

    WHERE MONTH(dlancamento) = :mes

    AND YEAR(dlancamento) = :ano

    AND COALESCE(faturavel, 0) = $filtroFaturavelPrincipal

";

$paramsConsultor = [

    ':mes' => $mes,

    ':ano' => $ano

];

if ($clienteFiltro !== '') {

    $sqlConsultor .= " AND cliente = :cliente ";

    $paramsConsultor[':cliente'] = $clienteFiltro;

}

if ($consultorFiltro !== '') {

    $sqlConsultor .= " AND usuario = :consultor ";

    $paramsConsultor[':consultor'] = $consultorFiltro;

}

$sqlConsultor .= "

    GROUP BY usuario

    ORDER BY total DESC

    LIMIT 10

";

$stmt = $pdo->prepare($sqlConsultor);

$stmt->execute($paramsConsultor);

$horasPorConsultor = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labelsConsultor = [];

$valoresConsultor = [];

foreach ($horasPorConsultor as $c) {

    $labelsConsultor[] = $c['usuario'];

    $valoresConsultor[] = round((float) $c['total'], 2);

}

// ===============================

// HORAS POR PROJETO

// ===============================

$sqlProjeto = "

    SELECT 

        projeto,

        COALESCE(SUM(TIME_TO_SEC(horas)) / 3600, 0) AS total

    FROM lancamentos

    WHERE MONTH(dlancamento) = :mes

    AND YEAR(dlancamento) = :ano

    AND COALESCE(faturavel, 0) = $filtroFaturavelPrincipal

";

$paramsProjeto = [

    ':mes' => $mes,

    ':ano' => $ano

];

if ($clienteFiltro !== '') {

    $sqlProjeto .= " AND cliente = :cliente ";

    $paramsProjeto[':cliente'] = $clienteFiltro;

}

if ($consultorFiltro !== '') {

    $sqlProjeto .= " AND usuario = :consultor ";

    $paramsProjeto[':consultor'] = $consultorFiltro;

}

$sqlProjeto .= "

    GROUP BY projeto

    ORDER BY total DESC

    LIMIT 10

";

$stmt = $pdo->prepare($sqlProjeto);

$stmt->execute($paramsProjeto);

$horasPorProjeto = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labelsProjeto = [];

$valoresProjeto = [];

foreach ($horasPorProjeto as $p) {

    $labelsProjeto[] = $p['projeto'] ?: 'Sem projeto';

    $valoresProjeto[] = round((float) $p['total'], 2);

}

// ===============================

// HORAS POR SEGMENTO

// ===============================

$sqlSegmento = "

    SELECT 

        c.segmento,

        COALESCE(SUM(TIME_TO_SEC(l.horas)) / 3600, 0) AS total

    FROM clientes_consultoria c

    LEFT JOIN lancamentos l 

        ON l.cliente = c.cliente

        AND MONTH(l.dlancamento) = :mes

        AND YEAR(l.dlancamento) = :ano

        AND COALESCE(l.faturavel, 0) = $filtroFaturavelPrincipal

    WHERE (c.status = 'Ativo' OR TRIM(c.cliente) = 'Action Process')

";

$paramsSegmento = [

    ':mes' => $mes,

    ':ano' => $ano

];

if ($clienteFiltro !== '') {

    $sqlSegmento .= " AND c.cliente = :cliente ";

    $paramsSegmento[':cliente'] = $clienteFiltro;

}

if ($consultorFiltro !== '') {

    $sqlSegmento .= " AND l.usuario = :consultor ";

    $paramsSegmento[':consultor'] = $consultorFiltro;

}

$sqlSegmento .= "

    GROUP BY c.segmento

    ORDER BY total DESC

    LIMIT 10

";

$stmt = $pdo->prepare($sqlSegmento);

$stmt->execute($paramsSegmento);

$horasPorSegmento = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labelsSegmento = [];

$valoresSegmento = [];

foreach ($horasPorSegmento as $s) {

    $labelsSegmento[] = $s['segmento'] ?: 'Sem segmento';

    $valoresSegmento[] = round((float) $s['total'], 2);

}

// ===============================

// EVOLUÇÃO MENSAL

// ===============================

$sqlEvolucao = "

    SELECT 

        YEAR(dlancamento) AS ano,

        MONTH(dlancamento) AS mes,

        COALESCE(SUM(TIME_TO_SEC(horas)) / 3600, 0) AS total

    FROM lancamentos

    WHERE dlancamento >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)

    AND COALESCE(faturavel, 0) = $filtroFaturavelPrincipal

";

$paramsEvolucao = [];

if ($clienteFiltro !== '') {

    $sqlEvolucao .= " AND cliente = :cliente ";

    $paramsEvolucao[':cliente'] = $clienteFiltro;

}

if ($consultorFiltro !== '') {

    $sqlEvolucao .= " AND usuario = :consultor ";

    $paramsEvolucao[':consultor'] = $consultorFiltro;

}

$sqlEvolucao .= "

    GROUP BY YEAR(dlancamento), MONTH(dlancamento)

    ORDER BY ano, mes

";

$stmt = $pdo->prepare($sqlEvolucao);

$stmt->execute($paramsEvolucao);

$evolucao = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labelsEvolucao = [];

$valoresEvolucao = [];

foreach ($evolucao as $e) {

    $labelsEvolucao[] = str_pad($e['mes'], 2, '0', STR_PAD_LEFT) . '/' . $e['ano'];

    $valoresEvolucao[] = round((float) $e['total'], 2);

}

// ===============================

// CONTRATOS PRÓXIMOS DO VENCIMENTO

// ===============================

$sqlVencimentos = "

    SELECT 

        cliente,

        contato_responsavel,

        data_vencimento,

        valor_mensal,

        status

    FROM clientes_consultoria

    WHERE status = 'Ativo'

    AND data_vencimento IS NOT NULL

    AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)

    ORDER BY data_vencimento ASC

";

$stmt = $pdo->query($sqlVencimentos);

$vencimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================

// COMPARATIVO: HORAS PROJETADAS X HORAS LANÇADAS

// NÍVEL: CONSULTOR + CLIENTE

// ===============================

$whereProjBase = " WHERE mes = :mes_proj_base AND ano = :ano_proj_base ";

$whereLancBase = " WHERE MONTH(dlancamento) = :mes_lanc_base AND YEAR(dlancamento) = :ano_lanc_base ";

$whereProjAgg = " WHERE mes = :mes_proj_agg AND ano = :ano_proj_agg ";

$whereLancAgg = " WHERE MONTH(dlancamento) = :mes_lanc_agg AND YEAR(dlancamento) = :ano_lanc_agg ";

$paramsComparativo = [

    ':mes_proj_base' => $mes,

    ':ano_proj_base' => $ano,

    ':mes_lanc_base' => $mes,

    ':ano_lanc_base' => $ano,

    ':mes_proj_agg' => $mes,

    ':ano_proj_agg' => $ano,

    ':mes_lanc_agg' => $mes,

    ':ano_lanc_agg' => $ano

];

if ($clienteFiltro !== '') {

    $whereProjBase .= " AND cliente = :cliente_proj_base ";

    $whereLancBase .= " AND cliente = :cliente_lanc_base ";

    $whereProjAgg .= " AND cliente = :cliente_proj_agg ";

    $whereLancAgg .= " AND cliente = :cliente_lanc_agg ";

    $paramsComparativo[':cliente_proj_base'] = $clienteFiltro;

    $paramsComparativo[':cliente_lanc_base'] = $clienteFiltro;

    $paramsComparativo[':cliente_proj_agg'] = $clienteFiltro;

    $paramsComparativo[':cliente_lanc_agg'] = $clienteFiltro;

}

if ($consultorFiltro !== '') {

    $whereProjBase .= " AND consultor = :consultor_proj_base ";

    $whereLancBase .= " AND usuario = :consultor_lanc_base ";

    $whereProjAgg .= " AND consultor = :consultor_proj_agg ";

    $whereLancAgg .= " AND usuario = :consultor_lanc_agg ";

    $paramsComparativo[':consultor_proj_base'] = $consultorFiltro;

    $paramsComparativo[':consultor_lanc_base'] = $consultorFiltro;

    $paramsComparativo[':consultor_proj_agg'] = $consultorFiltro;

    $paramsComparativo[':consultor_lanc_agg'] = $consultorFiltro;

}

$sqlComparativoConsultorCliente = "

    SELECT 

        base.consultor,

        base.cliente,

        COALESCE(p.horas_projetadas, 0) AS horas_projetadas,

        COALESCE(l.horas_lancadas, 0) AS horas_lancadas,

        COALESCE(l.horas_nao_faturaveis, 0) AS horas_nao_faturaveis,

        COALESCE(l.horas_total_lancadas, 0) AS horas_total_lancadas,

        COALESCE(p.horas_projetadas, 0) - COALESCE(l.horas_lancadas, 0) AS saldo_horas,

        CASE 

            WHEN COALESCE(p.horas_projetadas, 0) > 0 THEN

                (COALESCE(l.horas_lancadas, 0) / COALESCE(p.horas_projetadas, 0)) * 100

            ELSE 0

        END AS percentual_realizado

    FROM (

        SELECT 

            consultor,

            cliente

        FROM projecoes_consultores

        $whereProjBase

        UNION

        SELECT 

            usuario AS consultor,

            cliente

        FROM lancamentos

        $whereLancBase

    ) base

    LEFT JOIN (

        SELECT 

            consultor,

            cliente,

            SUM(horas_projetadas) AS horas_projetadas

        FROM projecoes_consultores

        $whereProjAgg

        GROUP BY consultor, cliente

    ) p 

        ON p.consultor = base.consultor

        AND p.cliente = base.cliente

    LEFT JOIN (

        SELECT 

            usuario AS consultor,

            cliente,

            COALESCE(SUM(CASE WHEN COALESCE(faturavel, 0) = $filtroFaturavelPrincipal THEN TIME_TO_SEC(horas) ELSE 0 END) / 3600, 0) AS horas_lancadas,

            COALESCE(SUM(CASE WHEN COALESCE(faturavel, 0) = 0 THEN TIME_TO_SEC(horas) ELSE 0 END) / 3600, 0) AS horas_nao_faturaveis,

            COALESCE(SUM(TIME_TO_SEC(horas)) / 3600, 0) AS horas_total_lancadas

        FROM lancamentos

        $whereLancAgg

        GROUP BY usuario, cliente

    ) l 

        ON l.consultor = base.consultor

        AND l.cliente = base.cliente

    ORDER BY base.consultor, base.cliente

";

$stmt = $pdo->prepare($sqlComparativoConsultorCliente);

$stmt->execute($paramsComparativo);

$comparativoConsultores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalProjetadoConsultores = 0;

$totalLancadoConsultores = 0; // somente faturáveis

$totalNaoFaturadoConsultores = 0;

$totalGeralLancadoConsultores = 0;

foreach ($comparativoConsultores as $cc) {

    $totalProjetadoConsultores += (float) $cc['horas_projetadas'];

    $totalLancadoConsultores += (float) $cc['horas_lancadas'];

    $totalNaoFaturadoConsultores += (float) $cc['horas_nao_faturaveis'];

    $totalGeralLancadoConsultores += (float) $cc['horas_total_lancadas'];

}

$saldoTotalConsultores = $totalProjetadoConsultores - $totalLancadoConsultores;

$percentualTotalConsultores = $totalProjetadoConsultores > 0

    ? ($totalLancadoConsultores / $totalProjetadoConsultores) * 100

    : 0;

// ===============================

// AGRUPA COMPARATIVO POR CONSULTOR

// ===============================

$comparativoAgrupado = [];

foreach ($comparativoConsultores as $cc) {

    $consultor = $cc['consultor'] ?: 'Sem consultor';

    if (!isset($comparativoAgrupado[$consultor])) {

        $comparativoAgrupado[$consultor] = [

            'total_projetado' => 0,

            'total_lancado' => 0,

            'total_nao_faturado' => 0,

            'total_geral_lancado' => 0,

            'clientes' => []

        ];

    }

    $comparativoAgrupado[$consultor]['total_projetado'] += (float) $cc['horas_projetadas'];

    $comparativoAgrupado[$consultor]['total_lancado'] += (float) $cc['horas_lancadas'];

    $comparativoAgrupado[$consultor]['total_nao_faturado'] += (float) $cc['horas_nao_faturaveis'];

    $comparativoAgrupado[$consultor]['total_geral_lancado'] += (float) $cc['horas_total_lancadas'];

    $comparativoAgrupado[$consultor]['clientes'][] = $cc;

}



?>

<!DOCTYPE html>

<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <title>Indicadores da Operação</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * {

            margin: 0;

            padding: 0;

            box-sizing: border-box;

            font-family: "Segoe UI", sans-serif;

        }

        body {

            background: #121221;

            color: #fff;

            padding: 24px;

        }

        .menu {

            display: flex;

            gap: 10px;

            margin-bottom: 20px;

        }

        .menu a {

            background: #008070;

            color: #fff;

            text-decoration: none;

            padding: 10px 14px;

            border-radius: 8px;

            font-weight: 700;

            font-size: 14px;

        }

        .menu a.secundario {

            background: #2f3448;

        }





        .aviso-faturavel {

            background: rgba(0, 120, 215, .14);

            border: 1px solid rgba(38, 182, 230, .35);

            color: #dceeff;

            padding: 10px 14px;

            border-radius: 10px;

            margin-bottom: 18px;

            font-size: 13px;

            display: flex;

            align-items: center;

            gap: 8px;

        }

        .aviso-faturavel i {

            color: #26b6e6;

        }

        .topo {

            display: grid;

            grid-template-columns: 140px 1fr 280px;

            gap: 18px;

            margin-bottom: 18px;

            align-items: center;

        }

        .box {

            background: #202333;

            border-radius: 14px;

            padding: 18px;

            box-shadow: 0 10px 25px rgba(0, 0, 0, .25);

        }

        .logo {

            display: flex;

            justify-content: center;

            align-items: center;

            height: 110px;

        }

        .logo img {

            width: 90px;

            background: #fff;

            padding: 8px;

        }

        .usuario {

            display: flex;

            justify-content: center;

            align-items: center;

            height: 110px;

            text-align: center;

            font-size: 18px;

            font-weight: bold;

        }

        .filtros {

            display: grid;

            grid-template-columns: repeat(5, 1fr);

            gap: 12px;

            align-items: end;

        }

        label {

            font-size: 12px;

            color: #ddd;

            display: block;

            margin-bottom: 6px;

        }

        select,

        button {

            width: 100%;

            padding: 9px;

            border-radius: 8px;

            border: 1px solid #444;

            background: #050505;

            color: #fff;

            font-weight: 600;

        }

        button {

            background: #008070;

            border: none;

            cursor: pointer;

        }

        .cards {

            display: grid;

            grid-template-columns: repeat(4, 1fr);

            gap: 14px;

            margin-bottom: 18px;

        }

        .card {

            background: #202333;

            border-radius: 14px;

            padding: 18px;

            box-shadow: 0 10px 25px rgba(0, 0, 0, .25);

        }

        .card small {

            color: #cfcfcf;

            font-size: 15px;

        }

        .card strong {

            display: block;

            margin-top: 8px;

            font-size: 25px;

            color: #008b7a;

        }

        .card.negativo strong {

            color: #ff4b5c;

        }

        .card.alerta strong {

            color: #f0b429;

        }

        .card.capacidade small {

            display: block;

            margin-bottom: 10px;

        }

        .capacidade-linha {

            display: flex;

            justify-content: space-between;

            gap: 10px;

            margin-top: 6px;

            font-size: 15px;

            color: #d8d8d8;

        }

        .capacidade-linha span:last-child {

            font-weight: 900;

            color: #fff;

            white-space: nowrap;

        }

        .card.capacidade strong {

            font-size: 25px;

        }

        .barra {

            width: 100%;

            height: 10px;

            background: #34384a;

            border-radius: 20px;

            overflow: hidden;

            margin-top: 10px;

        }

        .barra span {

            display: block;

            height: 100%;

            background: #008b7a;

            width:

                <?php echo min(100, round($percentualConsumido, 2)); ?>

                %;

        }

        .grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 18px;

            margin-bottom: 18px;

        }

        .painel {

            background: #202333;

            border-radius: 14px;

            padding: 16px;

            box-shadow: 0 10px 25px rgba(0, 0, 0, .25);

        }

        .painel h2 {

            background: #008070;

            margin: -16px -16px 16px -16px;

            padding: 8px 14px;

            border-radius: 14px 14px 0 0;

            text-align: center;

            font-size: 20px;

        }

        .painel-grande {

            grid-column: span 2;

        }

        canvas {

            width: 100% !important;

            max-height: 320px;

        }

        table {

            width: 100%;

            border-collapse: collapse;

            font-size: 13px;

        }

        th,

        td {

            padding: 8px;

            border-bottom: 1px solid #008070;

            text-align: left;

        }

        th {

            color: #fff;

            font-weight: 700;

        }

        tr.negativo {

            background: rgba(255, 75, 92, .12);

        }

        tr.alerta {

            background: rgba(240, 180, 41, .12);

        }

        .tag {

            padding: 4px 8px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: 700;

            display: inline-block;

        }

        .tag.ok {

            background: #008b7a;

        }

        .tag.alerta {

            background: #f0b429;

            color: #111;

        }

        .tag.negativo {

            background: #ff4b5c;

        }

        @media(max-width: 1100px) {

            .topo,

            .filtros,

            .cards,

            .grid {

                grid-template-columns: 1fr;

            }

            .painel-grande {

                grid-column: span 1;

            }

        }

        /* ===============================

           DETALHAMENTO CONSULTOR / CLIENTE

        \================================ */

        .detalhamento {

            background: #1f1f2f;

            border-radius: 12px;

            overflow: hidden;

            border: 1px solid rgba(255, 255, 255, .08);

            box-shadow: 0 10px 25px rgba(0, 0, 0, .35);

        }

        .detalhamento details {

            border-bottom: 1px solid rgba(255, 255, 255, .08);

            background: #232638;

        }

        .detalhamento details:nth-child(even) {

            background: #282b3e;

        }

        .detalhamento summary {

            list-style: none;

            cursor: pointer;

            padding: 0;

        }

        .detalhamento summary::-webkit-details-marker {

            display: none;

        }

        .linha-consultor {

            display: grid;

            grid-template-columns: 42px minmax(220px, 1.5fr) repeat(5, minmax(120px, 1fr));

            align-items: center;

            gap: 12px;

            padding: 14px 16px;

            transition: *background* .2s ease;

        }

        .linha-consultor:hover {

            background: rgba(0, 128, 112, .18);

        }

        .icone-expandir {

            width: 26px;

            height: 26px;

            background: #008070;

            border-radius: 7px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 11px;

            font-weight: bold;

            color: #fff;

        }

        .icone-expandir::before {

            content: "▶";

        }

        details[open] .icone-expandir::before {

            content: "▼";

        }

        .nome-consultor {

            font-size: 15px;

            font-weight: 800;

            color: #fff;

            line-height: 1.25;

            word-break: normal;

            overflow-wrap: anywhere;

        }

        .metric-resumo {

            background: #1a1c2b;

            border-radius: 9px;

            padding: 8px 10px;

            min-height: 56px;

            border: 1px solid rgba(255, 255, 255, .06);

        }

        .metric-resumo small {

            display: block;

            color: #bfc5da;

            font-size: 15px;

            margin-bottom: 5px;

            text-transform: uppercase;

            letter-spacing: .35px;

        }

        .metric-resumo strong {

            display: block;

            font-size: 15px;

            color: #fff;

            white-space: nowrap;

        }

        .metric-resumo.projetado strong {

            color: #00a995;

        }

        .metric-resumo.lancado strong {

            color: #26b6e6;

        }

        .metric-resumo.nao-faturado strong {

            color: #f59e0b;

        }

        .metric-resumo.saldo-positivo strong {

            color: #00c48c;

        }

        .metric-resumo.saldo-negativo strong {

            color: #ff4b5c;

        }

        .metric-resumo.realizado strong {

            color: #f0b429;

        }

        .tabela-detalhe {

            width: 100%;

            border-collapse: collapse;

            font-size: 13px;

            background: #1b1d2b;

        }

        .tabela-detalhe th {

            background: #008070;

            color: #fff;

            padding: 10px 12px;

            text-align: left;

            text-transform: uppercase;

            font-size: 12px;

            letter-spacing: .3px;

        }

        .tabela-detalhe td {

            padding: 10px 12px;

            border-bottom: 1px solid rgba(255, 255, 255, .08);

            color: #fff;

        }

        .tabela-detalhe tr:hover {

            background: rgba(255, 255, 255, .04);

        }

        .tabela-detalhe td:nth-child(2) {

            color: #00a995;

            font-weight: 700;

        }

        .tabela-detalhe td:nth-child(3) {

            color: #26b6e6;

            font-weight: 700;

        }

        .tabela-detalhe td:nth-child(4) {

            color: #f59e0b;

            font-weight: 700;

        }

        .tabela-detalhe td:nth-child(5) {

            font-weight: 700;

        }

        .tabela-detalhe td:nth-child(6) {

            color: #f0b429;

            font-weight: 700;

        }

        tr.negativo td {

            background: rgba(255, 75, 92, .08);

        }

        tr.alerta td {

            background: rgba(240, 180, 41, .08);

        }

        tr.ok td {

            background: rgba(0, 128, 112, .05);

        }

        .total-geral-detalhe {

            padding: 16px 18px;

            background: #181927;

            text-align: right;

            font-size: 16px;

            font-weight: 800;

            border-top: 1px solid rgba(255, 255, 255, .08);

            color: #fff;

        }

        .total-geral-detalhe span {

            display: inline-block;

            margin-left: 18px;

        }

        .total-geral-detalhe .positivo {

            color: #00c48c;

        }

        .total-geral-detalhe .negativo {

            color: #ff4b5c;

        }

        @media(max-width: 1200px) {

            .linha-consultor {

                grid-template-columns: 42px minmax(220px, 1fr) repeat(2, minmax(120px, 1fr));

            }

            .metric-resumo {

                min-height: 52px;

            }

        }

        @media(max-width: 800px) {

            .linha-consultor {

                grid-template-columns: 34px 1fr;

            }

            .metric-resumo {

                grid-column: span 2;

            }

            .tabela-detalhe {

                font-size: 12px;

            }

            .tabela-detalhe th,

            .tabela-detalhe td {

                padding: 8px;

            }

            .total-geral-detalhe {

                text-align: left;

            }

            .total-geral-detalhe span {

                display: block;

                margin-left: 0;

                margin-top: 6px;

            }

        }
    </style>

</head>

<body>

    <div class="menu">

        <a href="lancamentos.php">Lançamento de Horas</a>

        <a href="indicadores_operacao.php?mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>" class="secundario">

            KPI Gestão

        </a>

        <a href="projecoes_consultores.php?mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>" class="secundario"
            style="background:#0078d7;">

            <i class="fa-solid fa-calendar-check"></i> Projeções

        </a>

        <a href="logout.php" class="secundario">Sair</a>

    </div>

    <div class="topo">

        <div class="box logo">

            <img src="img/logoactionAP.png" alt="Action Process">

        </div>

        <div class="box">

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

                        <?php for ($a = date('Y') - 5; $a <= date('Y') + 1; $a++): ?>

                            <option value="<?php echo $a; ?>" <?php echo $ano == $a ? 'selected' : ''; ?>>

                                <?php echo $a; ?>

                            </option>

                        <?php endfor; ?>

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

                    <button type="submit">Filtrar</button>

                </div>

            </form>

        </div>

        <div class="box usuario">

            <?php echo h($usuarioLogado); ?>

        </div>

    </div>

    <div class="aviso-faturavel">

        <i class="fa-solid fa-circle-info"></i>

        Os cards superiores consideram o histórico acumulado da <strong>data de início do projeto</strong> até o mês

        selecionado. O saldo e o % consumido consideram somente <strong>horas faturáveis</strong>, exceto Action

        Process, que é tratado como <strong>horas internas</strong>.

    </div>

    <div class="cards">

        <div class="card">

            <small>Horas Contratadas Acumuladas</small>

            <strong>

                <?php echo horasDecimalParaHHMM($totalContratadas); ?>

            </strong>

        </div>

        <div class="card">

            <small><?php echo h($labelHorasPrincipalCard); ?></small>

            <strong>

                <?php echo horasDecimalParaHHMM($totalLancadas); ?>

            </strong>

        </div>

        <div class="card alerta">

            <small>Horas Não Faturáveis</small>

            <strong>

                <?php echo horasDecimalParaHHMM($totalNaoFaturadas); ?>

            </strong>

        </div>

        <div class="card">

            <small>Total Lançado</small>

            <strong>

                <?php echo horasDecimalParaHHMM($totalGeralLancadas); ?>

            </strong>

        </div>

        <div class="card <?php echo $saldoTotal < 0 ? 'negativo' : ''; ?>">

            <small>Saldo de Horas</small>

            <strong>

                <?php echo horasDecimalParaHHMM($saldoTotal); ?>

            </strong>

        </div>

        <div class="card <?php echo $percentualConsumido >= 90 ? 'alerta' : ''; ?>">

            <small>% Consumido</small>

            <strong>

                <?php echo number_format($percentualConsumido, 1, ',', '.'); ?>%

            </strong>

            <div class="barra">

                <span></span>

            </div>

        </div>

        <div class="card">

            <small>Clientes Ativos</small>

            <strong>

                <?php echo $clientesAtivos; ?>

            </strong>

        </div>

        <div class="card capacidade <?php echo $percentualTimeAlocado >= 90 ? 'alerta' : ''; ?>">

            <small>Capacidade Operacional</small>

            <strong>

                <?php echo horasDecimalParaHHMM($capacidadeOperacionalHoras); ?> Hrs

            </strong>

            <div class="capacidade-linha">

                <span>Consultores ativos</span>

                <span><?php echo $consultoresAtivosOperacao; ?></span>

            </div>

            <div class="capacidade-linha">

                <span>Horas contratadas</span>

                <span><?php echo horasDecimalParaHHMM($horasContratadasOperacao); ?> Hrs</span>

            </div>

            <div class="capacidade-linha">

                <span>Time alocado</span>

                <span><?php echo number_format($percentualTimeAlocado, 2, ',', '.'); ?>%</span>

            </div>

        </div>

    </div>

    <div class="grid">

        <div class="painel">

            <h2><?php echo h($labelHorasPrincipal); ?> por Cliente</h2>

            <canvas id="chartCliente"></canvas>

        </div>

        <div class="painel">

            <h2>Horas Não Faturáveis por Cliente</h2>

            <canvas id="chartClienteNaoFat"></canvas>

        </div>

        <div class="painel">

            <h2><?php echo h($labelHorasPrincipal); ?> por Consultor</h2>

            <canvas id="chartConsultor"></canvas>

        </div>

        <div class="painel">

            <h2><?php echo h($labelHorasPrincipal); ?> por Projeto</h2>

            <canvas id="chartProjeto"></canvas>

        </div>

        <div class="painel painel-grande">

            <h2>Evolução Mensal - <?php echo h($labelHorasPrincipal); ?></h2>

            <canvas id="chartEvolucao"></canvas>

        </div>

    </div>

    <div class="grid">

        <div class="painel painel-grande">

            <h2>Gestão por Cliente</h2>

            <table>

                <tr>

                    <th>Cliente</th>

                    <th>Segmento</th>

                    <th>Contratadas</th>

                    <th>Lançadas Fat.</th>

                    <th>Não Fat.</th>

                    <th>Total Lançado</th>

                    <th>Saldo</th>

                    <th>%</th>

                    <th>Valor Mensal</th>

                    <th>Valor/Hora</th>

                    <th>Vencimento</th>

                    <th>Status</th>

                </tr>

                <?php foreach ($resumoClientes as $c): ?>

                    <?php

                    $saldo = (float) $c['saldo_horas'];

                    $percentual = (float) $c['percentual_consumido'];

                    $horasLancadas = (float) $c['horas_lancadas'];

                    $horasNaoFaturaveis = (float) $c['horas_nao_faturaveis'];

                    $clienteLinhaAction = isActionProcess($c['cliente']);

                    if ($clienteLinhaAction && $horasNaoFaturaveis > 0) {

                        $statusGestao = 'Horas internas';

                        $classe = 'ok';

                    } elseif ($saldo < 0) {

                        $statusGestao = 'Acima';

                        $classe = 'negativo';

                    } elseif ($percentual >= 90) {

                        $statusGestao = 'Atenção';

                        $classe = 'alerta';

                    } elseif ($horasLancadas == 0) {

                        $statusGestao = 'Sem lançamento';

                        $classe = 'alerta';

                    } else {

                        $statusGestao = 'Normal';

                        $classe = 'ok';

                    }

                    ?>

                    <tr class="<?php echo $classe; ?>">

                        <td>

                            <?php echo h($c['cliente']); ?>

                        </td>

                        <td>

                            <?php echo h($c['segmento']); ?>

                        </td>

                        <td>

                            <?php echo horasDecimalParaHHMM($c['horas_mes']); ?>

                        </td>

                        <td>

                            <?php echo horasDecimalParaHHMM($c['horas_lancadas']); ?>

                        </td>

                        <td>

                            <?php echo horasDecimalParaHHMM($c['horas_nao_faturaveis']); ?>

                        </td>

                        <td>

                            <?php echo horasDecimalParaHHMM($c['horas_total_lancadas']); ?>

                        </td>

                        <td>

                            <?php echo horasDecimalParaHHMM($saldo); ?>

                        </td>

                        <td>

                            <?php echo number_format($percentual, 1, ',', '.'); ?>%

                        </td>

                        <td>R$

                            <?php echo number_format($c['valor_mensal'], 2, ',', '.'); ?>

                        </td>

                        <td>R$

                            <?php echo number_format($c['valor_hora'], 2, ',', '.'); ?>

                        </td>

                        <td>

                            <?php echo dataBR($c['data_vencimento']); ?>

                        </td>

                        <td>

                            <span class="tag <?php echo $classe; ?>">

                                <?php echo $statusGestao; ?>

                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </table>

        </div>

        <div class="painel painel-grande">

            <h2>Detalhamento por Consultor e Cliente - Projetado x <?php echo h($labelHorasPrincipal); ?></h2>

            <div class="cards" style="grid-template-columns: repeat(5, 1fr); margin-bottom:16px;">

                <div class="card">

                    <small>Total Projetado</small>

                    <strong><?php echo horasDecimalParaHHMM($totalProjetadoConsultores); ?></strong>

                </div>

                <div class="card">

                    <small>Total Lançado <?php echo h($labelDetalhamento); ?></small>

                    <strong><?php echo horasDecimalParaHHMM($totalLancadoConsultores); ?></strong>

                </div>

                <div class="card alerta">

                    <small>Total Não Fat.</small>

                    <strong><?php echo horasDecimalParaHHMM($totalNaoFaturadoConsultores); ?></strong>

                </div>

                <div class="card <?php echo $saldoTotalConsultores < 0 ? 'negativo' : ''; ?>">

                    <small>Saldo Projetado</small>

                    <strong><?php echo horasDecimalParaHHMM($saldoTotalConsultores); ?></strong>

                </div>

                <div class="card <?php echo $percentualTotalConsultores >= 90 ? 'alerta' : ''; ?>">

                    <small>% Realizado</small>

                    <strong><?php echo number_format($percentualTotalConsultores, 1, ',', '.'); ?>%</strong>

                </div>

            </div>

            <div class="detalhamento">

                <?php if (count($comparativoAgrupado) === 0): ?>

                    <div style="padding:20px; text-align:center;">

                        Nenhuma projeção ou lançamento encontrado para o período selecionado.

                    </div>

                <?php else: ?>

                    <?php foreach ($comparativoAgrupado as $consultor => $grupo): ?>

                        <?php

                        $totalProjetadoGrupo = (float) $grupo['total_projetado'];

                        $totalLancadoGrupo = (float) $grupo['total_lancado'];

                        $totalNaoFaturadoGrupo = (float) $grupo['total_nao_faturado'];

                        $saldoGrupo = $totalProjetadoGrupo - $totalLancadoGrupo;

                        $percentualGrupo = $totalProjetadoGrupo > 0

                            ? ($totalLancadoGrupo / $totalProjetadoGrupo) * 100

                            : 0;

                        ?>

                        <details>

                            <summary>

                                <div class="linha-consultor">

                                    <div class="icone-expandir"></div>

                                    <div class="nome-consultor">

                                        <?php echo h($consultor); ?>

                                    </div>

                                    <div class="metric-resumo projetado">

                                        <small>Projetado</small>

                                        <strong><?php echo horasDecimalParaHHMM($totalProjetadoGrupo); ?></strong>

                                    </div>

                                    <div class="metric-resumo lancado">

                                        <small>Lançado <?php echo h($labelDetalhamento); ?></small>

                                        <strong><?php echo horasDecimalParaHHMM($totalLancadoGrupo); ?></strong>

                                    </div>

                                    <div class="metric-resumo nao-faturado">

                                        <small>Não Fat.</small>

                                        <strong><?php echo horasDecimalParaHHMM($totalNaoFaturadoGrupo); ?></strong>

                                    </div>

                                    <div
                                        class="metric-resumo <?php echo $saldoGrupo < 0 ? 'saldo-negativo' : 'saldo-positivo'; ?>">

                                        <small>Saldo</small>

                                        <strong><?php echo horasDecimalParaHHMM($saldoGrupo); ?></strong>

                                    </div>

                                    <div class="metric-resumo realizado">

                                        <small>Realizado</small>

                                        <strong><?php echo number_format($percentualGrupo, 1, ',', '.'); ?>%</strong>

                                    </div>

                                </div>

                            </summary>

                            <table class="tabela-detalhe">

                                <tr>

                                    <th>Cliente</th>

                                    <th>Horas Projetadas</th>

                                    <th>Horas Lançadas <?php echo h($labelDetalhamento); ?></th>

                                    <th>Horas Não Fat.</th>

                                    <th>Saldo</th>

                                    <th>% Realizado</th>

                                    <th>Status</th>

                                </tr>

                                <?php foreach ($grupo['clientes'] as $cc): ?>

                                    <?php

                                    $horasProjetadas = (float) $cc['horas_projetadas'];

                                    $horasLancadas = (float) $cc['horas_lancadas'];

                                    $horasNaoFaturaveis = (float) $cc['horas_nao_faturaveis'];

                                    $saldoCliente = (float) $cc['saldo_horas'];

                                    $percentualRealizado = (float) $cc['percentual_realizado'];

                                    if ($horasProjetadas == 0 && $horasLancadas > 0) {

                                        $statusCliente = 'Sem projeção';

                                        $classeCliente = 'alerta';

                                    } elseif ($horasLancadas == 0 && $horasProjetadas > 0) {

                                        $statusCliente = 'Não iniciado';

                                        $classeCliente = 'alerta';

                                    } elseif ($saldoCliente < 0) {

                                        $statusCliente = 'Acima do projetado';

                                        $classeCliente = 'negativo';

                                    } elseif ($percentualRealizado >= 90) {

                                        $statusCliente = 'Atenção';

                                        $classeCliente = 'alerta';

                                    } else {

                                        $statusCliente = 'Dentro do planejado';

                                        $classeCliente = 'ok';

                                    }

                                    ?>

                                    <tr class="<?php echo $classeCliente; ?>">

                                        <td><?php echo h($cc['cliente']); ?></td>

                                        <td><?php echo horasDecimalParaHHMM($horasProjetadas); ?></td>

                                        <td><?php echo horasDecimalParaHHMM($horasLancadas); ?></td>

                                        <td><?php echo horasDecimalParaHHMM($horasNaoFaturaveis); ?></td>

                                        <td><?php echo horasDecimalParaHHMM($saldoCliente); ?></td>

                                        <td><?php echo number_format($percentualRealizado, 1, ',', '.'); ?>%</td>

                                        <td>

                                            <span class="tag <?php echo $classeCliente; ?>">

                                                <?php echo $statusCliente; ?>

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </table>

                        </details>

                    <?php endforeach; ?>

                <?php endif; ?>

                <div class="total-geral-detalhe">

                    <span>Total Geral Projetado: <?php echo horasDecimalParaHHMM($totalProjetadoConsultores); ?></span>

                    <span>Total Geral Lançado <?php echo h($labelDetalhamento); ?>:

                        <?php echo horasDecimalParaHHMM($totalLancadoConsultores); ?></span>

                    <span>Não Fat.: <?php echo horasDecimalParaHHMM($totalNaoFaturadoConsultores); ?></span>

                    <span>Total Lançado: <?php echo horasDecimalParaHHMM($totalGeralLancadoConsultores); ?></span>

                    <span class="<?php echo $saldoTotalConsultores < 0 ? 'negativo' : 'positivo'; ?>">

                        Saldo <?php echo h($labelDetalhamento); ?>:

                        <?php echo horasDecimalParaHHMM($saldoTotalConsultores); ?>

                    </span>

                </div>

            </div>

        </div>

        <div class="painel painel-grande">

            <h2>Contratos com Vencimento Próximo</h2>

            <table>

                <tr>

                    <th>Cliente</th>

                    <th>Responsável</th>

                    <th>Vencimento</th>

                    <th>Valor Mensal</th>

                    <th>Status</th>

                </tr>

                <?php if (count($vencimentos) === 0): ?>

                    <tr>

                        <td colspan="5" style="text-align:center;">Nenhum contrato próximo do vencimento.</td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($vencimentos as $v): ?>

                        <tr>

                            <td>

                                <?php echo h($v['cliente']); ?>

                            </td>

                            <td>

                                <?php echo h($v['contato_responsavel']); ?>

                            </td>

                            <td>

                                <?php echo dataBR($v['data_vencimento']); ?>

                            </td>

                            <td>R$

                                <?php echo number_format($v['valor_mensal'], 2, ',', '.'); ?>

                            </td>

                            <td>

                                <?php echo h($v['status']); ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </table>

        </div>

    </div>

    <script>

        Chart.defaults.color = '#ffffff';

        Chart.defaults.borderColor = '#33384a';

        const corPrincipal = '#008070';

        function barraHorizontal(id, labels, valores) {

            new Chart(document.getElementById(id), {

                type: 'bar',

                data: {

                    labels: labels,

                    datasets: [{

                        data: valores,

                        backgroundColor: corPrincipal

                    }]

                },

                options: {

                    indexAxis: 'y',

                    plugins: {

                        legend: {

                            display: false

                        }

                    },

                    scales: {

                        x: {

                            beginAtZero: true,

                            ticks: {

                                font: {

                                    size: 14

                                }

                            }

                        },

                        y: {

                            ticks: {

                                font: {

                                    size: 14

                                }

                            }

                        }

                    }

                }

            });

        }

        function linha(id, labels, valores) {

            new Chart(document.getElementById(id), {

                type: 'line',

                data: {

                    labels: labels,

                    datasets: [{

                        data: valores,

                        borderColor: corPrincipal,

                        backgroundColor: corPrincipal,

                        tension: .3

                    }]

                },

                options: {

                    plugins: {

                        legend: {

                            display: false

                        }

                    },

                    scales: {

                        y: {

                            beginAtZero: true

                        }

                    }

                }

            });

        }

        barraHorizontal(

            'chartCliente',

            <?php echo json_encode($labelsCliente); ?>,

            <?php echo json_encode($valoresCliente); ?>

        );

        barraHorizontal(

            'chartClienteNaoFat',

            <?php echo json_encode($labelsClienteNaoFat); ?>,

            <?php echo json_encode($valoresClienteNaoFat); ?>

        );

        barraHorizontal(

            'chartConsultor',

            <?php echo json_encode($labelsConsultor); ?>,

            <?php echo json_encode($valoresConsultor); ?>

        );

        barraHorizontal(

            'chartProjeto',

            <?php echo json_encode($labelsProjeto); ?>,

            <?php echo json_encode($valoresProjeto); ?>

        );

        barraHorizontal(

            'chartSegmento',

            <?php echo json_encode($labelsSegmento); ?>,

            <?php echo json_encode($valoresSegmento); ?>

        );

        linha(

            'chartEvolucao',

            <?php echo json_encode($labelsEvolucao); ?>,

            <?php echo json_encode($valoresEvolucao); ?>

        );

    </script>

</body>

</html>