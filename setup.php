<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

try {
    $pdo = db();
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
} catch (Throwable $error) {
    http_response_code(500);
    exit('Database Supabase belum siap. Jalankan database/schema_supabase.sql dan periksa environment variable DB_HOST, DB_PORT, DB_NAME, DB_USER, dan DB_PASS.');
}

if ($adminCount > 0) {
    redirect('index.php?page=login');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'Nama wajib diisi (maksimal 120 karakter).';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username harus 3–60 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.';
    }
    if (strlen($password) < 12) {
        $errors[] = 'Kata sandi harus memiliki minimal 12 karakter.';
    }
    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO admins (name, username, password_hash, role) VALUES (?, ?, ?, 'Super Admin')");
        try {
            $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
            log_activity('Inisialisasi akun Super Admin', $username);
            flash('Akun Super Admin berhasil dibuat. Silakan masuk.');
            redirect('index.php?page=login');
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                $errors[] = 'Username tersebut sudah digunakan.';
            } else {
                throw $error;
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Penyiapan awal · BTSU</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="auth-page">
  <main class="auth-card">
    <a class="brand-mark" href="setup.php">BTSU</a>
    <p class="eyebrow">PENYIAPAN AWAL</p>
    <h1>Buat akun Super Admin</h1>
    <p class="muted">Akun ini hanya dibuat sekali untuk mengelola sistem.</p>
    <?php foreach ($errors as $error): ?><div class="notice error"><?= h($error) ?></div><?php endforeach; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Nama lengkap<input name="name" maxlength="120" required autocomplete="name" value="<?= h($_POST['name'] ?? '') ?>"></label>
      <label>Username<input name="username" maxlength="60" required autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>"></label>
      <label>Kata sandi <small>Minimal 12 karakter</small><input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
      <button class="button primary wide">Buat akun</button>
    </form>
  </main>
</body>
</html>
