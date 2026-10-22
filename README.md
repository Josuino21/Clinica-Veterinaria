=========================================================
  VetSystem — Sistema de Gestão de Clínica Veterinária
  APS — Algoritmos e Programação de Sistemas
=========================================================

TECNOLOGIAS
-----------
  • Backend:  PHP 8+ com PDO (prepared statements)
  • Banco:    MySQL 8+ / MariaDB
  • Frontend: HTML5, CSS3, JavaScript (Vanilla — sem frameworks)
  • Servidor: Apache / XAMPP

INSTALAÇÃO
----------
1. Instale o XAMPP (ou similar) e inicie Apache + MySQL.
2. Antes de executar banco.sql, substitua
   SUBSTITUA_POR_UMA_SENHA_SEGURA por uma senha forte para o usuário
   app_clinica. Use essa mesma senha no arquivo api/db.php.
3. No phpMyAdmin, execute banco.sql para criar o banco e as tabelas
   com dados de exemplo.
4. Copie a pasta "clinica_veterinaria" para:
     C:\xampp\htdocs\clinica_veterinaria
5. Copie api/db.example.php para api/db.php e confira a senha.
6. Acesse: http://localhost/clinica_veterinaria/login.html

CREDENCIAIS DE ACESSO
---------------------
  E-mail: admin@clinica.com
  Senha:  password

PÁGINAS DO SISTEMA
------------------
  login.html        — Tela de login
  index.html        — Dashboard com estatísticas e agenda
  clientes.html     — CRUD completo de clientes/tutores
  pacientes.html    — CRUD completo de pacientes + histórico
  veterinarios.html — CRUD completo de veterinários
  agendamentos.html — CRUD completo de agendamentos + filtros
  consultas.html    — CRUD completo de consultas + detalhes

ESTRUTURA DO BANCO DE DADOS
---------------------------
  usuario         — Usuários do sistema (login)
  cliente         — Tutores/donos dos animais
  paciente        — Animais atendidos (FK → cliente)
  veterinario     — Médicos veterinários
  agendamento     — Consultas agendadas (FK → paciente, veterinario)
  consulta        — Registros de atendimento (FK → paciente, veterinario, agendamento)

RECURSOS DA API (api/index.php)
--------------------------------
  POST   /login                  — Autenticação
  DELETE /login                  — Logout

  GET    /clientes               — Listar (suporta ?busca=)
  GET    /clientes/{id}          — Buscar por ID
  POST   /clientes               — Criar
  PUT    /clientes/{id}          — Atualizar
  DELETE /clientes/{id}          — Excluir

  (mesmos métodos para /pacientes, /veterinarios,
   /agendamentos, /consultas)

  GET    /historico/{id_paciente} — Histórico de consultas
  GET    /dashboard               — Totais e próximos agendamentos

CONCEITOS DE BD APLICADOS
--------------------------
  ✔ Chaves primárias AUTO_INCREMENT
  ✔ Chaves estrangeiras com ON DELETE RESTRICT / SET NULL
  ✔ UNIQUE (cpf, crmv, email do usuário)
  ✔ CHECK constraint (cpf, peso, temperatura)
  ✔ NOT NULL e DEFAULT
  ✔ ENUM (sexo, status, perfil)
  ✔ Índices estratégicos (data_hora, status, id_paciente…)
  ✔ JOINs em todas as consultas de listagem
  ✔ Prepared statements (prevenção de SQL Injection)
  ✔ Transação implícita no registro de consulta
    (marca o agendamento como 'realizado' automaticamente)
 
