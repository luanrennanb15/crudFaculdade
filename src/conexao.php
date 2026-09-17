<?php
/**
 * Conexão com o banco de dados SQLite via PDO puro (sem ORM).
 * Se o banco não existir ainda, ele é criado automaticamente
 * a partir do schema.sql na primeira execução.
 */

function conectar(): PDO
{
    $caminhoBanco = __DIR__ . '/../db/hotel.db';
    $caminhoSchema = __DIR__ . '/../db/schema.sql';

    $bancoExiste = file_exists($caminhoBanco);

    $pdo = new PDO('sqlite:' . $caminhoBanco);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Precisa ser ligado em toda conexão: no SQLite as chaves
    // estrangeiras vêm desativadas por padrão.
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if (!$bancoExiste) {
        $sql = file_get_contents($caminhoSchema);
        if ($sql === false) {
            throw new RuntimeException('Não foi possível ler o arquivo db/schema.sql.');
        }
        $pdo->exec($sql);
    }

    return $pdo;
}
