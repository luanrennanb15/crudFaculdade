-- ============================================================
-- Banco de Dados: Sistema de Reserva de Hotel (Manual)
-- SGBD: SQLite
-- ============================================================

PRAGMA foreign_keys = ON;

-- Tabela de Quartos
-- status: 'disponivel' (verde), 'agendado' (amarelo), 'ocupado' (vermelho)
CREATE TABLE IF NOT EXISTS quartos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    numero TEXT NOT NULL UNIQUE,
    tipo TEXT NOT NULL,
    capacidade INTEGER NOT NULL DEFAULT 2
        CHECK (capacidade BETWEEN 1 AND 10),
    status TEXT NOT NULL DEFAULT 'disponivel'
        CHECK (status IN ('disponivel', 'agendado', 'ocupado'))
);

-- Tabela de Reservas
-- Cada reserva pertence a um quarto (FK). Ao criar/editar uma reserva,
-- o status do quarto correspondente é atualizado pelo sistema.
CREATE TABLE IF NOT EXISTS reservas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quarto_id INTEGER NOT NULL,
    cliente_nome TEXT NOT NULL,
    cliente_telefone TEXT NOT NULL,
    data_entrada TEXT NOT NULL,
    data_saida TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'agendado'
        CHECK (status IN ('agendado', 'ocupado', 'finalizado', 'cancelado')),
    observacoes TEXT,
    criado_em TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),

    -- Integridade de domínio: o check-out tem que ser depois do check-in.
    -- Datas em AAAA-MM-DD podem ser comparadas como texto.
    CHECK (data_saida > data_entrada),

    FOREIGN KEY (quarto_id) REFERENCES quartos(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- Índices para acelerar as consultas mais usadas pelo sistema
CREATE INDEX IF NOT EXISTS idx_reservas_quarto  ON reservas (quarto_id);
CREATE INDEX IF NOT EXISTS idx_reservas_periodo ON reservas (data_entrada, data_saida);

-- Dados iniciais de exemplo (quartos)
INSERT INTO quartos (numero, tipo, capacidade, status) VALUES
    ('101', 'Standard', 2, 'disponivel'),
    ('102', 'Standard', 2, 'disponivel'),
    ('103', 'Luxo', 3, 'disponivel'),
    ('104', 'Luxo', 3, 'disponivel'),
    ('201', 'Suite', 4, 'disponivel'),
    ('202', 'Suite', 4, 'disponivel');
