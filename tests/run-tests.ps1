$ErrorActionPreference = 'Stop'
$BaseUrl = 'http://localhost/clinica_veterinaria'
$ApiUrl = "$BaseUrl/api/index.php"
$Passed = 0
$Failed = 0

function Assert-That([bool]$Condition, [string]$Name) {
    if ($Condition) {
        $script:Passed++
        Write-Host "PASS [$Name]" -ForegroundColor Green
    } else {
        $script:Failed++
        Write-Host "FAIL [$Name]" -ForegroundColor Red
    }
}

function Invoke-TestRequest {
    param([string]$Uri, [string]$Method = 'GET', [object]$Session, [hashtable]$Headers, [string]$Body)
    try {
        $params = @{ Uri = $Uri; Method = $Method; UseBasicParsing = $true; ErrorAction = 'Stop' }
        if ($Session) { $params.WebSession = $Session }
        if ($Headers) { $params.Headers = $Headers }
        if ($Body) { $params.ContentType = 'application/json'; $params.Body = $Body }
        $response = Invoke-WebRequest @params
        return [PSCustomObject]@{ Status = [int]$response.StatusCode; Content = $response.Content; Headers = $response.Headers }
    } catch {
        return [PSCustomObject]@{ Status = [int]$_.Exception.Response.StatusCode; Content = $_.ErrorDetails.Message; Headers = $_.Exception.Response.Headers }
    }
}

function Get-TestSession {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrf = Invoke-TestRequest -Uri "$ApiUrl/csrf" -Session $session
    $token = ($csrf.Content | ConvertFrom-Json).csrf_token
    return [PSCustomObject]@{ Session = $session; Token = $token }
}

function Test-Cpf([string]$Value) {
    $cpf = $Value -replace '\D', ''
    if ($cpf.Length -ne 11 -or $cpf -match '^(\d)\1{10}$') { return $false }
    for ($index = 9; $index -lt 11; $index++) {
        $total = 0
        for ($position = 0; $position -lt $index; $position++) {
            $total += [int]::Parse($cpf.Substring($position, 1)) * ($index + 1 - $position)
        }
        $digit = (($total * 10) % 11) % 10
        if ($digit -ne [int]::Parse($cpf.Substring($index, 1))) { return $false }
    }
    return $true
}

Write-Host "VetSystem test suite: $BaseUrl" -ForegroundColor Cyan

# Unit tests: isolated validation behavior.
Assert-That (Test-Cpf '52998224725') 'Unidade - CPF válido'
Assert-That (-not (Test-Cpf '11111111111')) 'Unidade - CPF inválido'
Assert-That (-not (Test-Cpf '123')) 'Unidade - CPF com tamanho inválido'

# Integration and acceptance tests: browser/API/DB path.
$auth = Get-TestSession
Assert-That ($auth.Token.Length -eq 64) 'Integração - emissão de CSRF'
$loginBody = '{"email":"admin@clinica.com","senha":"password"}'
$login = Invoke-TestRequest -Uri "$ApiUrl/login" -Method POST -Session $auth.Session -Headers @{'X-CSRF-Token' = $auth.Token} -Body $loginBody
Assert-That ($login.Status -eq 200) 'Aceitação - login válido'
$dashboard = Invoke-TestRequest -Uri "$ApiUrl/dashboard" -Session $auth.Session
$dashboardJson = $dashboard.Content | ConvertFrom-Json
Assert-That ($dashboard.Status -eq 200 -and $null -ne $dashboardJson.totais) 'Integração - dashboard com banco'
$clientes = Invoke-TestRequest -Uri "$ApiUrl/clientes" -Session $auth.Session
Assert-That ($clientes.Status -eq 200) 'Aceitação - listagem de clientes'
$invalidCpf = Invoke-TestRequest -Uri "$ApiUrl/clientes" -Method POST -Session $auth.Session -Headers @{'X-CSRF-Token' = $auth.Token} -Body '{"nome":"Teste","cpf":"11111111111"}'
Assert-That ($invalidCpf.Status -eq 422) 'Aceitação - CPF inválido rejeitado'

# Usability smoke tests: pages and shared assets are reachable.
foreach ($page in @('login.html','index.html','clientes.html','pacientes.html','veterinarios.html','agendamentos.html','consultas.html')) {
    $pageResponse = Invoke-TestRequest -Uri "$BaseUrl/$page"
    Assert-That ($pageResponse.Status -eq 200 -and $pageResponse.Content.Length -gt 200) "Usabilidade - $page carregada"
}
$css = Invoke-TestRequest -Uri "$BaseUrl/assets/style.css"
$js = Invoke-TestRequest -Uri "$BaseUrl/assets/app.js"
Assert-That ($css.Status -eq 200 -and $js.Status -eq 200) 'Usabilidade - assets compartilhados'

# Security tests: authorization, CSRF, injection, sensitive files and TRACE.
$unauth = Invoke-TestRequest -Uri "$ApiUrl/clientes"
Assert-That ($unauth.Status -eq 401) 'Segurança - endpoint sem sessão'
$csrfSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-TestRequest -Uri "$ApiUrl/csrf" -Session $csrfSession
$csrfMissing = Invoke-TestRequest -Uri "$ApiUrl/clientes" -Method POST -Session $csrfSession -Body '{}'
Assert-That ($csrfMissing.Status -eq 403) 'Segurança - CSRF ausente'
$sqli = Invoke-TestRequest -Uri "$ApiUrl/clientes?busca=%27%20OR%201%3D1%20--" -Session $auth.Session
Assert-That ($sqli.Status -eq 200 -and $sqli.Content -notmatch 'senha_hash') 'Segurança - filtro contra SQL injection'
foreach ($sensitive in @('api/db.php','api/db.example.php','banco.sql')) {
    $sensitiveResponse = Invoke-TestRequest -Uri "$BaseUrl/$sensitive"
    Assert-That ($sensitiveResponse.Status -eq 403) "Segurança - $sensitive bloqueado"
}
$trace = Invoke-TestRequest -Uri "$ApiUrl/login" -Method TRACE
Assert-That ($trace.Status -eq 405) 'Segurança - TRACE bloqueado'

# Performance smoke test: 30 concurrent static requests.
$jobs = 1..30 | ForEach-Object {
    Start-Job -ScriptBlock {
        param($Url)
        try { $r = Invoke-WebRequest $Url -UseBasicParsing -ErrorAction Stop; [int]$r.StatusCode }
        catch { 0 }
    } -ArgumentList "$BaseUrl/login.html"
}
Wait-Job $jobs | Out-Null
$loadResults = @(Receive-Job $jobs)
Remove-Job $jobs
Assert-That ($loadResults.Count -eq 30 -and @($loadResults | Where-Object { $_ -eq 200 }).Count -eq 30) 'Desempenho - 30 requisições concorrentes'

# Rate-limit acceptance/security test; remove only the temporary limiter files afterward.
Get-ChildItem ([System.IO.Path]::GetTempPath()) -Filter 'vetsystem-login-*.json' -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
$invalidStatuses = 1..6 | ForEach-Object {
    $badBody = '{"email":"admin@clinica.com","senha":"suite-invalid-' + $_ + '"}'
    (Invoke-TestRequest -Uri "$ApiUrl/login" -Method POST -Body $badBody).Status
}
Get-ChildItem ([System.IO.Path]::GetTempPath()) -Filter 'vetsystem-login-*.json' -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
Assert-That (($invalidStatuses -join ',') -eq '401,401,401,401,401,429') 'Segurança - rate limiting do login'

Write-Host "`nResultado: $Passed aprovados; $Failed falhos." -ForegroundColor Cyan
if ($Failed -gt 0) { exit 1 }
exit 0
