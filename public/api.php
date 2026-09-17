<?php
/**
 * API do Sistema de Reserva de Hotel
 * PHP puro + PDO — instruções SQL diretas.
 *
 * Rotas (query string "acao"):
 *   GET  ?acao=listar_quartos
 *   POST ?acao=atualizar_status_quarto  (body JSON, precisa de "quarto_id" e "status")
 *
 *   GET  ?acao=listar_reservas
 *   POST ?acao=criar_reserva            (body JSON)
 *   POST ?acao=atualizar_reserva        (body JSON, precisa de "id")
 *   POST ?acao=excluir_reserva          (body JSON, precisa de "id")
 */

require_once __DIR__ . '/../src/conexao.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = conectar();
$acao = $_GET['acao'] ?? '';

// Lê o corpo da requisição (JSON) quando for POST
function corpoJson(): array
{
    $raw = file_get_contents('php://input');
    $dados = json_decode($raw, true);
    return is_array($dados) ? $dados : [];
}

function responder($dados, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// VALIDAÇÕES DE ENTRADA
// ------------------------------------------------------------

/**
 * Garante que todos os campos da lista vieram preenchidos.
 * Para o campo passar, ele precisa existir e não ser string vazia.
 */
function exigirCampos(array $dados, array $campos): void
{
    $faltando = [];
    foreach ($campos as $campo) {
        if (!isset($dados[$campo]) || trim((string) $dados[$campo]) === '') {
            $faltando[] = $campo;
        }
    }
    if ($faltando) {
        responder(['erro' => 'Campos obrigatórios não preenchidos: ' . implode(', ', $faltando)], 400);
    }
}

/**
 * Valida o formato AAAA-MM-DD e se a data realmente existe no calendário.
 * checkdate() rejeita coisas como 2026-02-31.
 */
function validarFormatoData(string $data, string $rotulo): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        responder(['erro' => "A data de $rotulo deve estar no formato AAAA-MM-DD."], 400);
    }
    [$ano, $mes, $dia] = array_map('intval', explode('-', $data));
    if (!checkdate($mes, $dia, $ano)) {
        responder(['erro' => "A data de $rotulo não existe no calendário."], 400);
    }
}

/**
 * Regra de negócio: o check-out precisa ser depois do check-in.
 * Diária de zero noite não faz sentido para um hotel.
 */
function validarPeriodo(string $entrada, string $saida): void
{
    validarFormatoData($entrada, 'check-in');
    validarFormatoData($saida, 'check-out');

    // Datas em AAAA-MM-DD podem ser comparadas como texto: a ordem
    // alfabética coincide com a ordem cronológica nesse formato.
    if ($saida <= $entrada) {
        responder(['erro' => 'A data de check-out precisa ser posterior à data de check-in.'], 400);
    }
}

function validarTelefone(string $telefone): void
{
    // Conta só os dígitos: telefone brasileiro tem 10 (fixo) ou 11 (celular).
    $digitos = preg_replace('/\D/', '', $telefone);
    if (strlen($digitos) < 10 || strlen($digitos) > 11) {
        responder(['erro' => 'Telefone inválido. Informe DDD + número (10 ou 11 dígitos).'], 400);
    }
}

function validarNome(string $nome): void
{
    if (mb_strlen(trim($nome)) < 3) {
        responder(['erro' => 'O nome do cliente deve ter pelo menos 3 caracteres.'], 400);
    }
}

function validarStatusReserva(string $status): void
{
    $permitidos = ['agendado', 'ocupado', 'finalizado', 'cancelado'];
    if (!in_array($status, $permitidos, true)) {
        responder(['erro' => 'Status de reserva inválido. Use: ' . implode(', ', $permitidos)], 400);
    }
}

function validarStatusQuarto(string $status): void
{
    $permitidos = ['disponivel', 'agendado', 'ocupado'];
    if (!in_array($status, $permitidos, true)) {
        responder(['erro' => 'Status de quarto inválido. Use: ' . implode(', ', $permitidos)], 400);
    }
}

/**
 * Confere se o quarto existe antes de amarrar uma reserva nele.
 * Evita o erro de chave estrangeira estourar na cara do usuário.
 */
function exigirQuartoExistente(PDO $pdo, $quartoId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM quartos WHERE id = :id');
    $stmt->execute([':id' => $quartoId]);
    if ((int) $stmt->fetchColumn() === 0) {
        responder(['erro' => 'O quarto informado não existe.'], 400);
    }
}

/**
 * Regra de negócio central: um quarto não pode ter duas reservas ativas
 * no mesmo período. Dois intervalos se sobrepõem quando
 * (entrada_nova < saida_existente) E (saida_nova > entrada_existente).
 *
 * Reservas finalizadas ou canceladas não bloqueiam o quarto.
 * $ignorarId serve para a edição não conflitar com ela mesma.
 */
function exigirPeriodoLivre(PDO $pdo, $quartoId, string $entrada, string $saida, $ignorarId = null): void
{
    $sql = "SELECT id, cliente_nome, data_entrada, data_saida
            FROM reservas
            WHERE quarto_id = :quarto_id
              AND status IN ('agendado', 'ocupado')
              AND data_entrada < :saida
              AND data_saida   > :entrada";

    $parametros = [
        ':quarto_id' => $quartoId,
        ':entrada' => $entrada,
        ':saida' => $saida,
    ];

    if ($ignorarId !== null) {
        $sql .= ' AND id <> :ignorar';
        $parametros[':ignorar'] = $ignorarId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $conflito = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($conflito) {
        responder([
            'erro' => sprintf(
                'Este quarto já está reservado para %s de %s até %s.',
                $conflito['cliente_nome'],
                $conflito['data_entrada'],
                $conflito['data_saida']
            ),
        ], 409);
    }
}

/**
 * Traduz o status da reserva para a cor do quarto no mapa.
 */
function statusQuartoPelaReserva(string $statusReserva): string
{
    $mapa = [
        'agendado'   => 'agendado',    // amarelo
        'ocupado'    => 'ocupado',     // vermelho
        'finalizado' => 'disponivel',  // verde
        'cancelado'  => 'disponivel',  // verde
    ];
    return $mapa[$statusReserva] ?? 'disponivel';
}

/**
 * Recalcula o status do quarto olhando as reservas ativas que ele ainda tem.
 * Usado depois de excluir/finalizar uma reserva, para o quarto não ficar
 * verde por engano quando ainda existe outra reserva válida nele.
 * "ocupado" tem prioridade sobre "agendado".
 */
function recalcularStatusQuarto(PDO $pdo, $quartoId): void
{
    $stmt = $pdo->prepare(
        "SELECT status FROM reservas
         WHERE quarto_id = :id AND status IN ('agendado', 'ocupado')
         ORDER BY CASE status WHEN 'ocupado' THEN 1 ELSE 2 END
         LIMIT 1"
    );
    $stmt->execute([':id' => $quartoId]);
    $statusReserva = $stmt->fetchColumn();

    $novoStatus = $statusReserva ? statusQuartoPelaReserva($statusReserva) : 'disponivel';

    $atualiza = $pdo->prepare('UPDATE quartos SET status = :status WHERE id = :id');
    $atualiza->execute([':status' => $novoStatus, ':id' => $quartoId]);
}

// ------------------------------------------------------------
// ROTEAMENTO
// ------------------------------------------------------------

try {
    switch ($acao) {

        // ==================== QUARTOS ====================

        case 'listar_quartos': {
            $sql = 'SELECT id, numero, tipo, capacidade, status
                    FROM quartos
                    ORDER BY numero';
            $stmt = $pdo->query($sql);
            responder($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        case 'atualizar_status_quarto': {
            $d = corpoJson();
            exigirCampos($d, ['quarto_id', 'status']);
            exigirQuartoExistente($pdo, $d['quarto_id']);
            validarStatusQuarto($d['status']);

            $stmt = $pdo->prepare('UPDATE quartos SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $d['status'], ':id' => $d['quarto_id']]);

            responder(['sucesso' => true]);
        }

        // ==================== RESERVAS ====================

        case 'listar_reservas': {
            $sql = 'SELECT r.id, r.quarto_id, q.numero AS quarto_numero, r.cliente_nome,
                           r.cliente_telefone, r.data_entrada, r.data_saida,
                           r.status, r.observacoes, r.criado_em
                    FROM reservas r
                    INNER JOIN quartos q ON q.id = r.quarto_id
                    ORDER BY r.data_entrada DESC';
            $stmt = $pdo->query($sql);
            responder($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        case 'criar_reserva': {
            $d = corpoJson();

            exigirCampos($d, ['quarto_id', 'cliente_nome', 'cliente_telefone', 'data_entrada', 'data_saida']);
            validarNome($d['cliente_nome']);
            validarTelefone($d['cliente_telefone']);
            validarPeriodo($d['data_entrada'], $d['data_saida']);
            exigirQuartoExistente($pdo, $d['quarto_id']);

            $status = $d['status'] ?? 'agendado';
            validarStatusReserva($status);

            // Só reservas ativas ocupam a agenda do quarto
            if (in_array($status, ['agendado', 'ocupado'], true)) {
                exigirPeriodoLivre($pdo, $d['quarto_id'], $d['data_entrada'], $d['data_saida']);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO reservas (quarto_id, cliente_nome, cliente_telefone, data_entrada, data_saida, status, observacoes)
                 VALUES (:quarto_id, :cliente_nome, :cliente_telefone, :data_entrada, :data_saida, :status, :observacoes)'
            );
            $stmt->execute([
                ':quarto_id' => $d['quarto_id'],
                ':cliente_nome' => trim($d['cliente_nome']),
                ':cliente_telefone' => trim($d['cliente_telefone']),
                ':data_entrada' => $d['data_entrada'],
                ':data_saida' => $d['data_saida'],
                ':status' => $status,
                ':observacoes' => trim($d['observacoes'] ?? ''),
            ]);
            $novoId = $pdo->lastInsertId();

            // Reflete o status da reserva na cor do quarto
            $stmt2 = $pdo->prepare('UPDATE quartos SET status = :status WHERE id = :id');
            $stmt2->execute([
                ':status' => statusQuartoPelaReserva($status),
                ':id' => $d['quarto_id'],
            ]);

            $pdo->commit();
            responder(['sucesso' => true, 'id' => $novoId], 201);
        }

        case 'atualizar_reserva': {
            $d = corpoJson();

            // Antes só o "id" era exigido aqui: os outros campos podiam chegar
            // vazios e gravar lixo no banco.
            exigirCampos($d, ['id', 'quarto_id', 'cliente_nome', 'cliente_telefone', 'data_entrada', 'data_saida', 'status']);
            validarNome($d['cliente_nome']);
            validarTelefone($d['cliente_telefone']);
            validarPeriodo($d['data_entrada'], $d['data_saida']);
            validarStatusReserva($d['status']);
            exigirQuartoExistente($pdo, $d['quarto_id']);

            $busca = $pdo->prepare('SELECT quarto_id FROM reservas WHERE id = :id');
            $busca->execute([':id' => $d['id']]);
            $quartoAntigo = $busca->fetchColumn();

            if ($quartoAntigo === false) {
                responder(['erro' => 'Reserva não encontrada.'], 404);
            }

            if (in_array($d['status'], ['agendado', 'ocupado'], true)) {
                exigirPeriodoLivre($pdo, $d['quarto_id'], $d['data_entrada'], $d['data_saida'], $d['id']);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE reservas
                    SET quarto_id = :quarto_id,
                        cliente_nome = :cliente_nome,
                        cliente_telefone = :cliente_telefone,
                        data_entrada = :data_entrada,
                        data_saida = :data_saida,
                        status = :status,
                        observacoes = :observacoes
                  WHERE id = :id'
            );
            $stmt->execute([
                ':quarto_id' => $d['quarto_id'],
                ':cliente_nome' => trim($d['cliente_nome']),
                ':cliente_telefone' => trim($d['cliente_telefone']),
                ':data_entrada' => $d['data_entrada'],
                ':data_saida' => $d['data_saida'],
                ':status' => $d['status'],
                ':observacoes' => trim($d['observacoes'] ?? ''),
                ':id' => $d['id'],
            ]);

            // Se a reserva mudou de quarto, o quarto antigo precisa ser reavaliado
            if ((int) $quartoAntigo !== (int) $d['quarto_id']) {
                recalcularStatusQuarto($pdo, $quartoAntigo);
            }
            recalcularStatusQuarto($pdo, $d['quarto_id']);

            $pdo->commit();
            responder(['sucesso' => true]);
        }

        case 'excluir_reserva': {
            $d = corpoJson();
            exigirCampos($d, ['id']);

            $busca = $pdo->prepare('SELECT quarto_id FROM reservas WHERE id = :id');
            $busca->execute([':id' => $d['id']]);
            $quartoId = $busca->fetchColumn();

            if ($quartoId === false) {
                responder(['erro' => 'Reserva não encontrada.'], 404);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('DELETE FROM reservas WHERE id = :id');
            $stmt->execute([':id' => $d['id']]);

            // Recalcula em vez de marcar como disponível direto: o quarto pode
            // ter outra reserva ativa que ainda deve manter a cor.
            recalcularStatusQuarto($pdo, $quartoId);

            $pdo->commit();
            responder(['sucesso' => true]);
        }

        default:
            responder(['erro' => 'Ação inválida.'], 404);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder(['erro' => 'Erro de banco de dados: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder(['erro' => 'Erro no servidor: ' . $e->getMessage()], 500);
}
