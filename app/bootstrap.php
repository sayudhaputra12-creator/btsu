<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('btsu_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '';
    $name = getenv('DB_NAME') ?: 'postgres';
    $user = getenv('DB_USER') ?: 'postgres';
    $password = getenv('DB_PASS') ?: '';
    $port = getenv('DB_PORT') ?: '5432';
    $sslmode = getenv('DB_SSLMODE') ?: 'require';
    if ($host === '' || $password === '') {
        throw new RuntimeException('Atur DB_HOST dan DB_PASS untuk koneksi PostgreSQL Supabase.');
    }

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$name};sslmode={$sslmode}",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");
    return $pdo;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function require_login(): void
{
    if (empty($_SESSION['admin'])) {
        redirect('index.php?page=login');
    }
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Sesi berakhir karena tidak aktif. Silakan masuk kembali.', 'error');
        redirect('index.php?page=login');
    }
    $stmt = db()->prepare('SELECT id, name, username, role, active FROM admins WHERE id = ?');
    $stmt->execute([(int) $_SESSION['admin']['id']]);
    $admin = $stmt->fetch();
    if (!$admin || !(int) $admin['active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Akun tidak aktif. Hubungi Super Admin kantor.');
        redirect('index.php?page=login');
    }
    $_SESSION['admin'] = [
        'id' => (int) $admin['id'],
        'name' => $admin['name'],
        'username' => $admin['username'],
        'role' => $admin['role'],
    ];
    $_SESSION['last_activity'] = time();
}

function require_super_admin(): void
{
    if (($_SESSION['admin']['role'] ?? '') !== 'Super Admin') {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke halaman ini.');
    }
}

function is_super_admin(): bool
{
    return ($_SESSION['admin']['role'] ?? '') === 'Super Admin';
}

function id(): int
{
    return (int) ($_SESSION['admin']['id'] ?? 0);
}

function log_activity(string $action, string $detail = ''): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (admin_id, action, detail, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([id() ?: null, $action, $detail, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}

function date_id(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y', $timestamp) : '—';
}

function status_badge(string $status): string
{
    $classes = [
        'Tersedia' => 'available',
        'Dipinjam' => 'borrowed',
        'Terlambat' => 'overdue',
        'Hilang' => 'lost',
        'Nonaktif' => 'inactive',
        'Selesai' => 'available',
    ];
    $class = $classes[$status] ?? 'inactive';
    return '<span class="badge ' . $class . '">' . h($status) . '</span>';
}

function page_url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

function request_string(string $key, int $maxLength = 500): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if (mb_strlen($value) > $maxLength) {
        throw new RuntimeException("Isian {$key} terlalu panjang.");
    }
    return $value;
}
