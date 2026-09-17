<?php
/**
 * Script de verificação de persistência.
 *
 * Abre o arquivo db/hotel.db em uma conexão NOVA (independente da aplicação
 * web) e lê o que está gravado nas tabelas. Serve como evidência de que os
 * dados cadastrados pela interface ficaram realmente salvos no banco, e não
 * apenas na memória do navegador.
 *
 * Uso:  php db/verificar_persistencia.php
 */

$caminhoBanco = __DIR__ . '/hotel.db';

if (!file_exists($caminhoBanco)) {
    exit("ERRO: o arquivo db/hotel.db nao existe. Rode a aplicacao primeiro.\n");
}

$pdo = new PDO('sqlite:' . $caminhoBanco);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "============================================================\n";
echo " VERIFICACAO DE PERSISTENCIA - SISTEMA DE RESERVA DE HOTEL\n";
echo "============================================================\n";
echo " Arquivo: " . realpath($caminhoBanco) . "\n";
echo " Tamanho: " . number_format(filesize($caminhoBanco)) . " bytes\n";
echo " Lido em: " . date('d/m/Y H:i:s') . "\n";
echo "============================================================\n\n";

// ---------- TABELA QUARTOS ----------
echo "TABELA: quartos\n";
echo str_repeat('-', 60) . "\n";
printf("%-4s %-8s %-10s %-11s %-12s\n", 'ID', 'NUMERO', 'TIPO', 'CAPACIDADE', 'STATUS');
echo str_repeat('-', 60) . "\n";

$quartos = $pdo->query('SELECT id, numero, tipo, capacidade, status FROM quartos ORDER BY numero');
$totalQuartos = 0;
foreach ($quartos as $q) {
    printf("%-4s %-8s %-10s %-11s %-12s\n",
        $q['id'], $q['numero'], $q['tipo'], $q['capacidade'], $q['status']);
    $totalQuartos++;
}
echo str_repeat('-', 60) . "\n";
echo "Total de quartos gravados: $totalQuartos\n\n";

// ---------- TABELA RESERVAS ----------
echo "TABELA: reservas (com INNER JOIN em quartos)\n";
echo str_repeat('-', 90) . "\n";
printf("%-4s %-7s %-22s %-14s %-11s %-11s %-11s\n",
    'ID', 'QUARTO', 'CLIENTE', 'TELEFONE', 'CHECK-IN', 'CHECK-OUT', 'STATUS');
echo str_repeat('-', 90) . "\n";

$sql = 'SELECT r.id, q.numero AS quarto, r.cliente_nome, r.cliente_telefone,
               r.data_entrada, r.data_saida, r.status
        FROM reservas r
        INNER JOIN quartos q ON q.id = r.quarto_id
        ORDER BY r.id';

$reservas = $pdo->query($sql);
$totalReservas = 0;
foreach ($reservas as $r) {
    printf("%-4s %-7s %-22s %-14s %-11s %-11s %-11s\n",
        $r['id'],
        $r['quarto'],
        mb_substr($r['cliente_nome'], 0, 21),
        $r['cliente_telefone'],
        $r['data_entrada'],
        $r['data_saida'],
        $r['status']);
    $totalReservas++;
}
echo str_repeat('-', 90) . "\n";
echo "Total de reservas gravadas: $totalReservas\n\n";

// ---------- INTEGRIDADE ----------
echo "VERIFICACAO DE INTEGRIDADE\n";
echo str_repeat('-', 60) . "\n";

$fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
echo "Violacoes de chave estrangeira: " . count($fk) . "\n";

$orfas = $pdo->query(
    'SELECT COUNT(*) FROM reservas r
     LEFT JOIN quartos q ON q.id = r.quarto_id
     WHERE q.id IS NULL'
)->fetchColumn();
echo "Reservas sem quarto correspondente: $orfas\n";

$integridade = $pdo->query('PRAGMA integrity_check')->fetchColumn();
echo "Integridade do arquivo: $integridade\n";
echo str_repeat('-', 60) . "\n";
