<?php
require_once 'db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'TRACE') {
    http_response_code(405);
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    exit;
}
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script = $_SERVER['SCRIPT_NAME'];
$path   = trim(str_replace(rtrim($script, '/'), '', $uri), '/');
$parts  = $path === '' ? [] : explode('/', $path);

$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$sub      = $parts[2] ?? null;

// helpers
function body(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : (is_array($_POST) ? $_POST : []);
}
function resp($data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function need(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
            resp(['erro' => "Campo obrigatório: $f"], 422);
        }
    }
}
function cpfValido(string $value): bool {
    $cpf = preg_replace('/\D/', '', $value);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($index = 9; $index < 11; $index++) {
        $total = 0;
        for ($position = 0; $position < $index; $position++) {
            $total += (int)$cpf[$position] * ($index + 1 - $position);
        }
        $digit = ($total * 10) % 11 % 10;
        if ($digit !== (int)$cpf[$index]) return false;
    }
    return true;
}
function authGuard(): void {
    if (empty($_SESSION['usuario_id'])) {
        resp(['erro' => 'Não autenticado.'], 401);
    }
}
function requirePerfil(array $permitidos): void {
    if (!in_array($_SESSION['usuario_perfil'] ?? '', $permitidos, true)) {
        resp(['erro' => 'Sem permissão para executar esta ação.'], 403);
    }
}
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrfGuard(): void {
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$provided || !hash_equals(csrfToken(), $provided)) {
        resp(['erro' => 'Token CSRF inválido.'], 403);
    }
}
function reserveLoginAttempt(): void {
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vetsystem-login-' . $key . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle) return;
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
    $now = time();
    $attempts = array_values(array_filter($state['attempts'] ?? [], fn($time) => $time > $now - 60));
    if (count($attempts) >= 5) {
        flock($handle, LOCK_UN);
        fclose($handle);
        header('Retry-After: 60');
        resp(['erro' => 'Muitas tentativas. Aguarde um minuto.'], 429);
    }
    // já reserva a tentativa aqui dentro, no mesmo lock — sem essa reserva,
    // duas requisições concorrentes passariam pela checagem acima antes de
    // qualquer uma delas gravar a falha, furando o limite de 5/min
    $attempts[] = $now;
    $state['attempts'] = $attempts;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    flock($handle, LOCK_UN);
    fclose($handle);
}
function clearLoginFailures(): void {
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vetsystem-login-' . $key . '.json');
}

// router
try {

    if ($resource === 'csrf' && $method === 'GET') {
        resp(['csrf_token' => csrfToken()]);
    }
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && $resource !== 'login') {
        csrfGuard();
    }

    // login
    if ($resource === 'login') {
        if ($method === 'POST') {
            reserveLoginAttempt();
            $d = body();
            need($d, ['email', 'senha']);
            $s = $pdo->prepare("SELECT * FROM usuario WHERE email = ? AND ativo = 1");
            $s->execute([trim($d['email'])]);
            $u = $s->fetch();
            // roda password_verify mesmo quando o e-mail não existe, contra um hash
            // fixo, só pra gastar o mesmo tempo — senão dá pra descobrir quais
            // e-mails têm conta cadastrada comparando o tempo de resposta
            $hashParaComparar = $u['senha_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            $senhaOk = password_verify($d['senha'], $hashParaComparar);
            if (!$u || !$senhaOk) {
                resp(['erro' => 'E-mail ou senha inválidos.'], 401);
            }
            clearLoginFailures();
            session_regenerate_id(true);
            $_SESSION['usuario_id']   = $u['id_usuario'];
            $_SESSION['usuario_nome'] = $u['nome'];
            $_SESSION['usuario_perfil'] = $u['perfil'];
            unset($u['senha_hash']);
            resp(['mensagem' => 'Login realizado.', 'usuario' => $u]);
        }
        if ($method === 'DELETE') {
            session_destroy();
            resp(['mensagem' => 'Logout realizado.']);
        }
    }

    // clientes
    if ($resource === 'clientes') {
        authGuard();
        if ($method === 'GET') {
            if ($id) {
                $s = $pdo->prepare("SELECT * FROM cliente WHERE id_cliente = ?");
                $s->execute([$id]);
                $r = $s->fetch();
                resp($r ?: ['erro' => 'Cliente não encontrado'], $r ? 200 : 404);
            }
            $busca = $_GET['busca'] ?? '';
            if ($busca) {
                $s = $pdo->prepare("SELECT * FROM cliente WHERE nome LIKE ? OR cpf LIKE ? ORDER BY nome");
                $s->execute(["%$busca%", "%$busca%"]);
            } else {
                $s = $pdo->query("SELECT * FROM cliente ORDER BY nome");
            }
            resp($s->fetchAll());
        }
        if ($method === 'POST') {
            $d = body(); need($d, ['nome', 'cpf']);
            $cpf = preg_replace('/\D/', '', (string)$d['cpf']);
            if (!cpfValido($cpf)) resp(['erro' => 'CPF inválido.'], 422);
            $s = $pdo->prepare("INSERT INTO cliente(nome,cpf,telefone,email,endereco) VALUES(?,?,?,?,?)");
            $s->execute([$d['nome'], $cpf, $d['telefone'] ?? null, $d['email'] ?? null, $d['endereco'] ?? null]);
            resp(['mensagem' => 'Cliente criado.', 'id' => $pdo->lastInsertId()], 201);
        }
        if ($method === 'PUT' && $id) {
            $d = body(); need($d, ['nome', 'cpf']);
            $cpf = preg_replace('/\D/', '', (string)$d['cpf']);
            if (!cpfValido($cpf)) resp(['erro' => 'CPF inválido.'], 422);
            $s = $pdo->prepare("UPDATE cliente SET nome=?,cpf=?,telefone=?,email=?,endereco=? WHERE id_cliente=?");
            $s->execute([$d['nome'], $cpf, $d['telefone'] ?? null, $d['email'] ?? null, $d['endereco'] ?? null, $id]);
            resp(['mensagem' => 'Cliente atualizado.']);
        }
        if ($method === 'DELETE' && $id) {
            requirePerfil(['admin']);
            $s = $pdo->prepare("DELETE FROM cliente WHERE id_cliente=?");
            $s->execute([$id]);
            resp(['mensagem' => 'Cliente excluído.']);
        }
    }

    // pacientes
    if ($resource === 'pacientes') {
        authGuard();
        if ($method === 'GET') {
            if ($id) {
                $s = $pdo->prepare(
                    "SELECT p.*, c.nome AS dono, c.telefone AS dono_telefone
                     FROM paciente p JOIN cliente c ON c.id_cliente = p.id_cliente
                     WHERE p.id_paciente = ?");
                $s->execute([$id]);
                $r = $s->fetch();
                resp($r ?: ['erro' => 'Paciente não encontrado'], $r ? 200 : 404);
            }
            $busca = $_GET['busca'] ?? '';
            if ($busca) {
                $s = $pdo->prepare(
                    "SELECT p.*, c.nome AS dono FROM paciente p
                     JOIN cliente c ON c.id_cliente = p.id_cliente
                     WHERE p.nome LIKE ? OR p.especie LIKE ? OR c.nome LIKE ?
                     ORDER BY p.nome");
                $s->execute(["%$busca%", "%$busca%", "%$busca%"]);
            } else {
                $s = $pdo->query(
                    "SELECT p.*, c.nome AS dono FROM paciente p
                     JOIN cliente c ON c.id_cliente = p.id_cliente
                     ORDER BY p.nome");
            }
            resp($s->fetchAll());
        }
        if ($method === 'POST') {
            $d = body(); need($d, ['id_cliente', 'nome', 'especie']);
            $s = $pdo->prepare(
                "INSERT INTO paciente(id_cliente,nome,especie,raca,sexo,data_nascimento,peso,observacoes)
                 VALUES(?,?,?,?,?,?,?,?)");
            $s->execute([
                $d['id_cliente'], $d['nome'], $d['especie'],
                $d['raca'] ?? null, $d['sexo'] ?? 'M',
                $d['data_nascimento'] ?: null,
                $d['peso'] ?: null, $d['observacoes'] ?? null
            ]);
            resp(['mensagem' => 'Paciente criado.', 'id' => $pdo->lastInsertId()], 201);
        }
        if ($method === 'PUT' && $id) {
            $d = body(); need($d, ['id_cliente', 'nome', 'especie']);
            $s = $pdo->prepare(
                "UPDATE paciente SET id_cliente=?,nome=?,especie=?,raca=?,sexo=?,
                 data_nascimento=?,peso=?,observacoes=? WHERE id_paciente=?");
            $s->execute([
                $d['id_cliente'], $d['nome'], $d['especie'],
                $d['raca'] ?? null, $d['sexo'] ?? 'M',
                $d['data_nascimento'] ?: null,
                $d['peso'] ?: null, $d['observacoes'] ?? null, $id
            ]);
            resp(['mensagem' => 'Paciente atualizado.']);
        }
        if ($method === 'DELETE' && $id) {
            requirePerfil(['admin']);
            $s = $pdo->prepare("DELETE FROM paciente WHERE id_paciente=?");
            $s->execute([$id]);
            resp(['mensagem' => 'Paciente excluído.']);
        }
    }

    // veterinarios
    if ($resource === 'veterinarios') {
        authGuard();
        if ($method === 'GET') {
            if ($id) {
                $s = $pdo->prepare("SELECT * FROM veterinario WHERE id_veterinario=?");
                $s->execute([$id]);
                $r = $s->fetch();
                resp($r ?: ['erro' => 'Veterinário não encontrado'], $r ? 200 : 404);
            }
            $s = $pdo->query("SELECT * FROM veterinario ORDER BY nome");
            resp($s->fetchAll());
        }
        if ($method === 'POST') {
            requirePerfil(['admin']);
            $d = body(); need($d, ['nome', 'crmv']);
            $s = $pdo->prepare(
                "INSERT INTO veterinario(nome,crmv,especialidade,telefone,email,ativo)
                 VALUES(?,?,?,?,?,?)");
            $s->execute([
                $d['nome'], $d['crmv'], $d['especialidade'] ?? null,
                $d['telefone'] ?? null, $d['email'] ?? null,
                isset($d['ativo']) ? (int)$d['ativo'] : 1
            ]);
            resp(['mensagem' => 'Veterinário criado.', 'id' => $pdo->lastInsertId()], 201);
        }
        if ($method === 'PUT' && $id) {
            requirePerfil(['admin']);
            $d = body(); need($d, ['nome', 'crmv']);
            $s = $pdo->prepare(
                "UPDATE veterinario SET nome=?,crmv=?,especialidade=?,telefone=?,email=?,ativo=?
                 WHERE id_veterinario=?");
            $s->execute([
                $d['nome'], $d['crmv'], $d['especialidade'] ?? null,
                $d['telefone'] ?? null, $d['email'] ?? null,
                isset($d['ativo']) ? (int)$d['ativo'] : 1, $id
            ]);
            resp(['mensagem' => 'Veterinário atualizado.']);
        }
        if ($method === 'DELETE' && $id) {
            requirePerfil(['admin']);
            $s = $pdo->prepare("DELETE FROM veterinario WHERE id_veterinario=?");
            $s->execute([$id]);
            resp(['mensagem' => 'Veterinário excluído.']);
        }
    }

    // agendamentos
    if ($resource === 'agendamentos') {
        authGuard();
        if ($method === 'GET') {
            if ($id) {
                $s = $pdo->prepare(
                    "SELECT a.*, p.nome AS paciente, c.nome AS dono, v.nome AS veterinario
                     FROM agendamento a
                     JOIN paciente p ON p.id_paciente = a.id_paciente
                     JOIN cliente  c ON c.id_cliente  = p.id_cliente
                     JOIN veterinario v ON v.id_veterinario = a.id_veterinario
                     WHERE a.id_agendamento = ?");
                $s->execute([$id]);
                $r = $s->fetch();
                resp($r ?: ['erro' => 'Agendamento não encontrado'], $r ? 200 : 404);
            }
            $status = $_GET['status'] ?? '';
            $data   = $_GET['data']   ?? '';
            $where  = []; $params = [];
            if ($status) { $where[] = "a.status = ?"; $params[] = $status; }
            if ($data)   { $where[] = "DATE(a.data_hora) = ?"; $params[] = $data; }
            $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $s = $pdo->prepare(
                "SELECT a.*, p.nome AS paciente, c.nome AS dono, v.nome AS veterinario
                 FROM agendamento a
                 JOIN paciente p ON p.id_paciente = a.id_paciente
                 JOIN cliente  c ON c.id_cliente  = p.id_cliente
                 JOIN veterinario v ON v.id_veterinario = a.id_veterinario
                 $w ORDER BY a.data_hora DESC");
            $s->execute($params);
            resp($s->fetchAll());
        }
        if ($method === 'POST') {
            $d = body(); need($d, ['id_paciente', 'id_veterinario', 'data_hora']);
            $s = $pdo->prepare(
                "INSERT INTO agendamento(id_paciente,id_veterinario,data_hora,motivo,status,observacoes)
                 VALUES(?,?,?,?,?,?)");
            $s->execute([
                $d['id_paciente'], $d['id_veterinario'], $d['data_hora'],
                $d['motivo'] ?? null,
                $d['status'] ?? 'agendado',
                $d['observacoes'] ?? null
            ]);
            resp(['mensagem' => 'Agendamento criado.', 'id' => $pdo->lastInsertId()], 201);
        }
        if ($method === 'PUT' && $id) {
            $d = body(); need($d, ['id_paciente', 'id_veterinario', 'data_hora']);
            $s = $pdo->prepare(
                "UPDATE agendamento SET id_paciente=?,id_veterinario=?,data_hora=?,
                 motivo=?,status=?,observacoes=? WHERE id_agendamento=?");
            $s->execute([
                $d['id_paciente'], $d['id_veterinario'], $d['data_hora'],
                $d['motivo'] ?? null, $d['status'] ?? 'agendado',
                $d['observacoes'] ?? null, $id
            ]);
            resp(['mensagem' => 'Agendamento atualizado.']);
        }
        if ($method === 'DELETE' && $id) {
            requirePerfil(['admin']);
            $s = $pdo->prepare("DELETE FROM agendamento WHERE id_agendamento=?");
            $s->execute([$id]);
            resp(['mensagem' => 'Agendamento excluído.']);
        }
    }

    // consultas
    if ($resource === 'consultas') {
        authGuard();
        if ($method === 'GET') {
            if ($id) {
                $s = $pdo->prepare(
                    "SELECT co.*, p.nome AS paciente, p.especie, c.nome AS dono, v.nome AS veterinario
                     FROM consulta co
                     JOIN paciente p    ON p.id_paciente    = co.id_paciente
                     JOIN cliente c     ON c.id_cliente     = p.id_cliente
                     JOIN veterinario v ON v.id_veterinario = co.id_veterinario
                     WHERE co.id_consulta = ?");
                $s->execute([$id]);
                $r = $s->fetch();
                resp($r ?: ['erro' => 'Consulta não encontrada'], $r ? 200 : 404);
            }
            $paciente = $_GET['paciente'] ?? '';
            $where = []; $params = [];
            if ($paciente) { $where[] = "co.id_paciente = ?"; $params[] = $paciente; }
            $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $s = $pdo->prepare(
                "SELECT co.*, p.nome AS paciente, p.especie, c.nome AS dono, v.nome AS veterinario
                 FROM consulta co
                 JOIN paciente p    ON p.id_paciente    = co.id_paciente
                 JOIN cliente c     ON c.id_cliente     = p.id_cliente
                 JOIN veterinario v ON v.id_veterinario = co.id_veterinario
                 $w ORDER BY co.data_consulta DESC");
            $s->execute($params);
            resp($s->fetchAll());
        }
        if ($method === 'POST') {
            $d = body(); need($d, ['id_paciente', 'id_veterinario', 'data_consulta']);
            $s = $pdo->prepare(
                "INSERT INTO consulta(id_paciente,id_veterinario,id_agendamento,data_consulta,
                 peso_consulta,temperatura,diagnostico,tratamento,prescricao,observacoes,retorno_em)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([
                $d['id_paciente'], $d['id_veterinario'],
                $d['id_agendamento'] ?: null, $d['data_consulta'],
                $d['peso_consulta'] ?: null, $d['temperatura'] ?: null,
                $d['diagnostico'] ?? null, $d['tratamento'] ?? null,
                $d['prescricao'] ?? null,   $d['observacoes'] ?? null,
                $d['retorno_em'] ?: null
            ]);
            // marcar agendamento como realizado
            if (!empty($d['id_agendamento'])) {
                $pdo->prepare("UPDATE agendamento SET status='realizado' WHERE id_agendamento=?")
                    ->execute([$d['id_agendamento']]);
            }
            resp(['mensagem' => 'Consulta registrada.', 'id' => $pdo->lastInsertId()], 201);
        }
        if ($method === 'PUT' && $id) {
            $d = body(); need($d, ['id_paciente', 'id_veterinario', 'data_consulta']);
            $s = $pdo->prepare(
                "UPDATE consulta SET id_paciente=?,id_veterinario=?,data_consulta=?,
                 peso_consulta=?,temperatura=?,diagnostico=?,tratamento=?,
                 prescricao=?,observacoes=?,retorno_em=? WHERE id_consulta=?");
            $s->execute([
                $d['id_paciente'], $d['id_veterinario'], $d['data_consulta'],
                $d['peso_consulta'] ?: null, $d['temperatura'] ?: null,
                $d['diagnostico'] ?? null, $d['tratamento'] ?? null,
                $d['prescricao'] ?? null, $d['observacoes'] ?? null,
                $d['retorno_em'] ?: null, $id
            ]);
            resp(['mensagem' => 'Consulta atualizada.']);
        }
        if ($method === 'DELETE' && $id) {
            requirePerfil(['admin']);
            $s = $pdo->prepare("DELETE FROM consulta WHERE id_consulta=?");
            $s->execute([$id]);
            resp(['mensagem' => 'Consulta excluída.']);
        }
    }

    // historico do paciente
    if ($resource === 'historico' && $id && $method === 'GET') {
        authGuard();
        $s = $pdo->prepare(
            "SELECT co.id_consulta, co.data_consulta, co.diagnostico, co.tratamento,
                    co.prescricao, co.observacoes, co.peso_consulta, co.temperatura, co.retorno_em,
                    v.nome AS veterinario, p.nome AS paciente, c.nome AS dono
             FROM consulta co
             JOIN paciente p    ON p.id_paciente    = co.id_paciente
             JOIN cliente  c    ON c.id_cliente     = p.id_cliente
             JOIN veterinario v ON v.id_veterinario = co.id_veterinario
             WHERE co.id_paciente = ?
             ORDER BY co.data_consulta DESC");
        $s->execute([$id]);
        resp($s->fetchAll());
    }

    // dashboard
    if ($resource === 'dashboard' && $method === 'GET') {
        authGuard();
        $totais = [
            'clientes'      => $pdo->query("SELECT COUNT(*) FROM cliente")->fetchColumn(),
            'pacientes'     => $pdo->query("SELECT COUNT(*) FROM paciente")->fetchColumn(),
            'veterinarios'  => $pdo->query("SELECT COUNT(*) FROM veterinario WHERE ativo=1")->fetchColumn(),
            'agendamentos_hoje' => $pdo->query(
                "SELECT COUNT(*) FROM agendamento WHERE DATE(data_hora)=CURDATE() AND status IN('agendado','confirmado')"
            )->fetchColumn(),
            'consultas_mes' => $pdo->query(
                "SELECT COUNT(*) FROM consulta WHERE MONTH(data_consulta)=MONTH(CURDATE()) AND YEAR(data_consulta)=YEAR(CURDATE())"
            )->fetchColumn(),
        ];
        $proximos = $pdo->query(
            "SELECT a.data_hora, a.status, a.motivo, p.nome AS paciente, v.nome AS veterinario
             FROM agendamento a
             JOIN paciente p ON p.id_paciente=a.id_paciente
             JOIN veterinario v ON v.id_veterinario=a.id_veterinario
             WHERE a.data_hora >= NOW() AND a.status IN('agendado','confirmado')
             ORDER BY a.data_hora LIMIT 5"
        )->fetchAll();

        // ranking de consultas por veterinario
        $ranking = $pdo->query(
            "SELECT v.nome AS veterinario, v.especialidade,
                    COUNT(co.id_consulta) AS total_consultas
             FROM veterinario v
             LEFT JOIN consulta co ON co.id_veterinario = v.id_veterinario
             GROUP BY v.id_veterinario, v.nome, v.especialidade
             HAVING COUNT(co.id_consulta) > 0
             ORDER BY total_consultas DESC"
        )->fetchAll();

        resp(['totais' => $totais, 'proximos' => $proximos, 'ranking' => $ranking]);
    }

    resp(['erro' => 'Rota não encontrada.'], 404);

} catch (PDOException $e) {
    $code = $e->errorInfo[1] ?? 0;
    if ($code == 1062) resp(['erro' => 'Registro duplicado (CPF ou CRMV já cadastrado).'], 409);
    if ($code == 1451) resp(['erro' => 'Não é possível excluir: há registros vinculados.'], 409);
    if ($code == 1452) resp(['erro' => 'Cliente, paciente, veterinário ou agendamento informado não existe.'], 422);
    resp(['erro' => 'Erro interno no banco de dados.'], 500);
}
