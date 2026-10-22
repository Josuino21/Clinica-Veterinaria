-- Sistema de Gestão de Clínicas Veterinárias
-- Script de criação do banco de dados

CREATE DATABASE IF NOT EXISTS clinica_veterinaria
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clinica_veterinaria;

-- ------------------------------------------------------------
-- USUÁRIOS DO SISTEMA (login)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario (
    id_usuario   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(100) NOT NULL,
    email        VARCHAR(100) NOT NULL UNIQUE,
    senha_hash   VARCHAR(255) NOT NULL,
    perfil       ENUM('admin','recepcionista','veterinario') NOT NULL DEFAULT 'recepcionista',
    ativo        TINYINT(1) NOT NULL DEFAULT 1,
    criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Senha padrão: password (bcrypt)
INSERT INTO usuario (nome, email, senha_hash, perfil) VALUES
('Administrador', 'admin@clinica.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Ana Recepção', 'recepcao@clinica.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recepcionista');

-- ------------------------------------------------------------
-- CLIENTES / TUTORES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cliente (
    id_cliente   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(100) NOT NULL,
    cpf          CHAR(11)     NOT NULL UNIQUE,
    telefone     VARCHAR(20),
    email        VARCHAR(100),
    endereco     VARCHAR(200),
    criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cpf CHECK (CHAR_LENGTH(cpf) = 11)
);

-- ------------------------------------------------------------
-- PACIENTES / ANIMAIS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paciente (
    id_paciente      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente       INT UNSIGNED NOT NULL,
    nome             VARCHAR(100) NOT NULL,
    especie          VARCHAR(50)  NOT NULL,
    raca             VARCHAR(50),
    sexo             ENUM('M','F') NOT NULL DEFAULT 'M',
    data_nascimento  DATE,
    peso             DECIMAL(5,2) CHECK (peso > 0),
    observacoes      TEXT,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pac_cli FOREIGN KEY (id_cliente)
        REFERENCES cliente(id_cliente) ON DELETE RESTRICT ON UPDATE CASCADE
);
CREATE INDEX idx_pac_cliente ON paciente(id_cliente);

-- ------------------------------------------------------------
-- VETERINÁRIOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS veterinario (
    id_veterinario   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome             VARCHAR(100) NOT NULL,
    crmv             VARCHAR(20)  NOT NULL UNIQUE,
    especialidade    VARCHAR(80),
    telefone         VARCHAR(20),
    email            VARCHAR(100),
    ativo            TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- AGENDAMENTOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agendamento (
    id_agendamento   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_paciente      INT UNSIGNED NOT NULL,
    id_veterinario   INT UNSIGNED NOT NULL,
    data_hora        DATETIME     NOT NULL,
    motivo           VARCHAR(200),
    status           ENUM('agendado','confirmado','cancelado','realizado') NOT NULL DEFAULT 'agendado',
    observacoes      TEXT,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ag_pac FOREIGN KEY (id_paciente)
        REFERENCES paciente(id_paciente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ag_vet FOREIGN KEY (id_veterinario)
        REFERENCES veterinario(id_veterinario) ON DELETE RESTRICT ON UPDATE CASCADE
);
CREATE INDEX idx_ag_data      ON agendamento(data_hora);
CREATE INDEX idx_ag_status    ON agendamento(status);
CREATE INDEX idx_ag_paciente  ON agendamento(id_paciente);
CREATE INDEX idx_ag_vet       ON agendamento(id_veterinario);

-- ------------------------------------------------------------
-- CONSULTAS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consulta (
    id_consulta      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_paciente      INT UNSIGNED NOT NULL,
    id_veterinario   INT UNSIGNED NOT NULL,
    id_agendamento   INT UNSIGNED,
    data_consulta    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    peso_consulta    DECIMAL(5,2),
    temperatura      DECIMAL(4,1),
    diagnostico      TEXT,
    tratamento       TEXT,
    prescricao       TEXT,
    observacoes      TEXT,
    retorno_em       DATE,
    criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_con_pac FOREIGN KEY (id_paciente)
        REFERENCES paciente(id_paciente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_con_vet FOREIGN KEY (id_veterinario)
        REFERENCES veterinario(id_veterinario) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_con_ag  FOREIGN KEY (id_agendamento)
        REFERENCES agendamento(id_agendamento) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_con_paciente ON consulta(id_paciente);
CREATE INDEX idx_con_data     ON consulta(data_consulta);

-- ------------------------------------------------------------
-- VIEW DE HISTÓRICO COMPLETO
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW vw_historico_completo AS
SELECT p.nome AS paciente, c.nome AS dono, v.nome AS veterinario,
       co.data_consulta, co.diagnostico, co.tratamento
FROM consulta co
JOIN paciente p    ON p.id_paciente    = co.id_paciente
JOIN cliente c     ON c.id_cliente     = p.id_cliente
JOIN veterinario v ON v.id_veterinario = co.id_veterinario;

-- ------------------------------------------------------------
-- DADOS DE EXEMPLO
-- ------------------------------------------------------------
INSERT INTO veterinario (nome, crmv, especialidade, telefone, email) VALUES
('Dr. Carlos Mendes',  '12345-SP', 'Clínica Geral',  '(11) 99999-0001', 'carlos@clinica.com'),
('Dra. Ana Paula Souza','54321-SP', 'Dermatologia',   '(11) 99999-0002', 'ana@clinica.com'),
('Dr. Roberto Lima',   '98765-SP', 'Ortopedia',      '(11) 99999-0003', 'roberto@clinica.com');

INSERT INTO cliente (nome, cpf, telefone, email, endereco) VALUES
('Maria Silva',    '12345678901', '(11) 91111-0001', 'maria@email.com',   'Rua das Flores, 100'),
('João Pereira',   '98765432100', '(11) 91111-0002', 'joao@email.com',    'Av. Central, 200'),
('Lucia Ferreira', '11122233344', '(11) 91111-0003', 'lucia@email.com',   'Rua Verde, 300');

INSERT INTO paciente (id_cliente, nome, especie, raca, sexo, data_nascimento, peso) VALUES
(1, 'Rex',     'Cão',   'Labrador',     'M', '2020-03-15', 28.5),
(1, 'Mia',     'Gato',  'Persa',        'F', '2021-07-20', 4.2),
(2, 'Bolinha', 'Cão',   'Poodle',       'M', '2019-11-05', 6.8),
(3, 'Luna',    'Gato',  'Siamês',       'F', '2022-01-10', 3.9);

-- Usuário da aplicação com privilégios mínimos necessários.
CREATE USER IF NOT EXISTS 'app_clinica'@'localhost' IDENTIFIED BY 'SUBSTITUA_POR_UMA_SENHA_SEGURA';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica_veterinaria.* TO 'app_clinica'@'localhost';
FLUSH PRIVILEGES;
