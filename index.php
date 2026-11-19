<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

try {
    $pdo = db();
} catch (Throwable $error) {
    http_response_code(500);
    exit('Koneksi PostgreSQL Supabase gagal. Periksa environment variable DB_HOST, DB_PORT, DB_NAME, DB_USER, dan DB_PASS.');
}

function setting(string $key, string $fallback): string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    return (string) ($stmt->fetchColumn() ?: $fallback);
}

function valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function positive_archive_number(string $value, ?int $maximum = null): ?string
{
    if (!preg_match('/^\d+$/D', $value)) {
        return null;
    }

    $normalized = ltrim($value, '0');
    if ($normalized === '' || ($maximum !== null && (strlen($normalized) > strlen((string) $maximum) || (int) $normalized > $maximum))) {
        return null;
    }

    return $normalized;
}

function loan_status(array $loan): string
{
    if ((int) $loan['active_items'] === 0) {
        return 'Selesai';
    }
    return $loan['due_date'] < date('Y-m-d') ? 'Terlambat' : 'Dipinjam';
}

function get_loan(int $loanId): ?array
{
    $stmt = db()->prepare(
        'SELECT l.*, b.name AS borrower_name, u.name AS unit_name, a.name AS admin_name,
                (SELECT COUNT(*) FROM loan_items li WHERE li.loan_id = l.id AND li.returned_at IS NULL) AS active_items,
                (SELECT COUNT(*) FROM loan_extensions le WHERE le.loan_id = l.id) AS extension_count
         FROM loans l
         JOIN borrowers b ON b.id = l.borrower_id
         LEFT JOIN units u ON u.id = b.unit_id
         JOIN admins a ON a.id = l.admin_id
         WHERE l.id = ?'
    );
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch();
    return $loan ?: null;
}

function doc_status_sql(): string
{
    return "CASE
        WHEN d.status IN ('Hilang','Nonaktif') THEN d.status
        WHEN EXISTS (
          SELECT 1 FROM loan_items li JOIN loans l ON l.id = li.loan_id
          WHERE li.document_id = d.id AND li.returned_at IS NULL AND l.due_date < CURRENT_DATE
        ) THEN 'Terlambat'
        WHEN EXISTS (SELECT 1 FROM loan_items li WHERE li.document_id = d.id AND li.returned_at IS NULL) THEN 'Dipinjam'
        ELSE 'Tersedia' END";
}

function audit_logs(string $action, string $detail = ''): void
{
    log_activity($action, $detail);
}

$page = (string) ($_GET['page'] ?? 'dashboard');
$allowedPages = ['login', 'forgot', 'dashboard', 'documents', 'document-form', 'document-detail', 'document-import', 'borrowers', 'borrower-form', 'borrower-detail', 'loans', 'loan-form', 'loan-detail', 'loan-manage', 'reports', 'settings', 'password'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}
$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'login') {
            $username = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $password === '') {
                throw new RuntimeException('Username dan kata sandi wajib diisi.');
            }
            $attempts = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND failed_at >= CURRENT_TIMESTAMP - INTERVAL '15 minutes'");
            $attempts->execute([$username]);
            if ((int) $attempts->fetchColumn() >= 5) {
                throw new RuntimeException('Terlalu banyak percobaan masuk. Coba kembali 15 menit lagi.');
            }
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? AND active = 1 LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                $pdo->prepare('INSERT INTO login_attempts (username) VALUES (?)')->execute([$username]);
                throw new RuntimeException('Username atau kata sandi tidak sesuai.');
            }
            $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
            }
            $pdo->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$admin['id']]);
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => (int) $admin['id'], 'name' => $admin['name'], 'username' => $admin['username'], 'role' => $admin['role']];
            $_SESSION['last_activity'] = time();
            audit_logs('Masuk', $admin['username']);
            redirect('index.php?page=dashboard');
        }

        if ($action === 'logout') {
            audit_logs('Keluar');
            $_SESSION = [];
            session_regenerate_id(true);
            flash('Anda telah keluar dari sistem.');
            redirect('index.php?page=login');
        }

        require_login();

        switch ($action) {
            case 'save_document':
                $docId = (int) ($_POST['id'] ?? 0);
                $district = request_string('district', 100);
                $village = request_string('village', 120);
                $categoryId = (int) ($_POST['right_category_id'] ?? 0);
                $number = request_string('right_number', 80);
                $documentDate = trim((string) ($_POST['document_date'] ?? ''));
                $floor = positive_archive_number(request_string('floor', 20), 4294967295);
                $cabinet = positive_archive_number(request_string('cabinet', 20), 4294967295);
                $rackNumber = positive_archive_number(request_string('rack_number', 20));
                $rackSide = strtolower(request_string('rack_side', 1));
                $rack = ($rackNumber ?? '') . $rackSide;
                $condition = request_string('condition_state', 40);
                $album = request_string('album', 80);
                $notes = request_string('notes', 5000);
                if ($district === '' || $village === '' || $number === '' || $categoryId < 1) {
                    throw new RuntimeException('Kecamatan, desa/kelurahan, jenis hak, dan nomor hak wajib diisi.');
                }
                if ($floor === null || $cabinet === null || $rackNumber === null || !in_array($rackSide, ['a','b'], true)) {
                    throw new RuntimeException('Lantai, lemari, dan nomor rak harus berupa angka positif; sisi rak harus A atau B.');
                }
                if (!in_array($condition, ['Baik','Perlu perbaikan','Rusak'], true)) {
                    throw new RuntimeException('Kondisi dokumen tidak valid.');
                }
                if ($documentDate !== '' && !valid_date($documentDate)) {
                    throw new RuntimeException('Tanggal dokumen tidak valid.');
                }
                $categoryCheck = $pdo->prepare('SELECT id FROM right_categories WHERE id = ? AND active = 1');
                $categoryCheck->execute([$categoryId]);
                if (!$categoryCheck->fetchColumn()) {
                    throw new RuntimeException('Kategori jenis hak tidak tersedia.');
                }
                $villageCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM villages v JOIN districts d ON d.id=v.district_id
                     WHERE d.active=1 AND v.active=1 AND d.name=? AND v.name=?'
                );
                $villageCheck->execute([$district, $village]);
                $validVillage = (int) $villageCheck->fetchColumn() > 0;
                if ($docId) {
                    $current = $pdo->prepare('SELECT status,district,village FROM documents WHERE id = ?');
                    $current->execute([$docId]);
                    $existing = $current->fetch();
                    if (!$existing) {
                        throw new RuntimeException('Dokumen tidak ditemukan.');
                    }
                    $unchangedLegacyVillage = mb_strtolower($existing['district']) === mb_strtolower($district)
                        && mb_strtolower($existing['village']) === mb_strtolower($village);
                    if (!$validVillage && !$unchangedLegacyVillage) {
                        throw new RuntimeException('Kelurahan harus dipilih sesuai kecamatan.');
                    }
                    $stmt = $pdo->prepare('UPDATE documents SET district=?, village=?, right_category_id=?, right_number=?, document_date=?, floor=?, cabinet=?, rack=?, album=?, condition_state=?, notes=? WHERE id=?');
                    $stmt->execute([$district, $village, $categoryId, $number, $documentDate ?: null, $floor, $cabinet, $rack, $album ?: null, $condition, $notes ?: null, $docId]);
                    audit_logs('Ubah dokumen', $number);
                } else {
                    if (!$validVillage) {
                        throw new RuntimeException('Kelurahan harus dipilih sesuai kecamatan.');
                    }
                    $stmt = $pdo->prepare("INSERT INTO documents (district, village, right_category_id, right_number, document_date, floor, cabinet, rack, album, condition_state, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$district, $village, $categoryId, $number, $documentDate ?: null, $floor, $cabinet, $rack, $album ?: null, $condition, $notes ?: null]);
                    audit_logs('Tambah dokumen', $number);
                }
                flash('Data dokumen berhasil disimpan.');
                redirect('index.php?page=documents');

            case 'import_documents':
                $file = $_FILES['csv_file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) $file['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('Pilih file CSV berukuran maksimal 5 MB.');
                }
                if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv' || !is_uploaded_file($file['tmp_name'])) {
                    throw new RuntimeException('File yang diunggah harus berupa CSV.');
                }
                $handle = fopen($file['tmp_name'], 'rb');
                if (!$handle) {
                    throw new RuntimeException('File CSV tidak dapat dibaca.');
                }
                $header = fgetcsv($handle, 0, ',', '"', '\\');
                if (!$header) {
                    fclose($handle);
                    throw new RuntimeException('File CSV kosong atau tidak memiliki baris judul.');
                }
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                $expected = ['district','village','right_category','right_number','document_date','floor','cabinet','rack','album','condition_state','notes'];
                if (array_map(static fn($v) => trim((string) $v), $header) !== $expected) {
                    fclose($handle);
                    throw new RuntimeException('Urutan kolom tidak sesuai. Unduh dan gunakan template CSV yang disediakan.');
                }
                $districtMap = [];
                foreach ($pdo->query('SELECT name FROM districts WHERE active=1') as $row) {
                    $districtMap[mb_strtolower($row['name'])] = $row['name'];
                }
                $categoryMap = [];
                foreach ($pdo->query('SELECT id,name FROM right_categories WHERE active=1') as $row) {
                    $categoryMap[mb_strtolower($row['name'])] = (int) $row['id'];
                }
                $villageMap = [];
                foreach ($pdo->query('SELECT d.name AS district,v.name AS village FROM villages v JOIN districts d ON d.id=v.district_id WHERE d.active=1 AND v.active=1') as $row) {
                    $villageMap[mb_strtolower($row['district'])][mb_strtolower($row['village'])] = true;
                }
                $insert = $pdo->prepare('INSERT INTO documents (district,village,right_category_id,right_number,document_date,floor,cabinet,rack,album,condition_state,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $imported = 0;
                $errors = [];
                $rowNumber = 1;
                $pdo->beginTransaction();
                while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;
                    if ($row === [null] || count(array_filter($row, static fn($value) => trim((string) $value) !== '')) === 0) {
                        continue;
                    }
                    if ($rowNumber > 10001) {
                        $errors[] = 'Impor dibatasi maksimal 10.000 baris.';
                        break;
                    }
                    if (count($row) !== count($expected)) {
                        $errors[] = "Baris {$rowNumber}: jumlah kolom tidak sesuai.";
                        continue;
                    }
                    [$district,$village,$category,$number,$date,$floor,$cabinet,$rack,$album,$condition,$notes] = array_map(static fn($value) => trim((string) $value), $row);
                    $categoryId = $categoryMap[mb_strtolower($category)] ?? 0;
                    $floorNumber = positive_archive_number($floor, 4294967295);
                    $cabinetNumber = positive_archive_number($cabinet, 4294967295);
                    $rackNumber = null;
                    $rackSide = '';
                    if (preg_match('/^(\d+)([ab])$/iD', $rack, $rackParts)) {
                        $rackNumber = strlen($rackParts[1]) <= 20 ? positive_archive_number($rackParts[1]) : null;
                        $rackSide = strtolower($rackParts[2]);
                    }
                    if (!isset($districtMap[mb_strtolower($district)]) || !isset($villageMap[mb_strtolower($district)][mb_strtolower($village)]) || $categoryId < 1 || $number === '' ||
                        $floorNumber === null || $cabinetNumber === null || $rackNumber === null ||
                        !in_array($condition, ['Baik','Perlu perbaikan','Rusak'], true) ||
                        ($date !== '' && !valid_date($date))) {
                        $errors[] = "Baris {$rowNumber}: nilai kecamatan, jenis hak, nomor, tanggal, lokasi, atau kondisi tidak valid.";
                        continue;
                    }
                    try {
                        $insert->execute([$districtMap[mb_strtolower($district)],$village,$categoryId,$number,$date ?: null,$floorNumber,$cabinetNumber,$rackNumber . $rackSide,$album ?: null,$condition,$notes ?: null]);
                        $imported++;
                    } catch (PDOException $error) {
                        if ($error->getCode() !== '23000') {
                            throw $error;
                        }
                        $errors[] = "Baris {$rowNumber}: nomor hak duplikat pada desa dan jenis hak tersebut.";
                    }
                }
                fclose($handle);
                $pdo->commit();
                $detail = "{$imported} dokumen diimpor.";
                if ($errors) {
                    $detail .= ' ' . count($errors) . ' baris bermasalah: ' . implode(' ', array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $detail .= ' dan lainnya.';
                    }
                }
                audit_logs('Impor dokumen CSV', $detail);
                flash($detail, $errors ? 'warning' : 'success');
                redirect('index.php?page=documents');

            case 'set_document_status':
                $docId = (int) ($_POST['id'] ?? 0);
                $status = (string) ($_POST['status'] ?? '');
                if (!in_array($status, ['Tersedia','Hilang','Nonaktif'], true)) {
                    throw new RuntimeException('Status dokumen tidak valid.');
                }
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT status FROM documents WHERE id = ? FOR UPDATE');
                $lock->execute([$docId]);
                if (!$lock->fetch()) {
                    throw new RuntimeException('Dokumen tidak ditemukan.');
                }
                $active = $pdo->prepare('SELECT COUNT(*) FROM loan_items WHERE document_id = ? AND returned_at IS NULL');
                $active->execute([$docId]);
                if ((int) $active->fetchColumn() > 0) {
                    throw new RuntimeException('Dokumen masih dipinjam. Catat pengembaliannya terlebih dahulu.');
                }
                $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([$status, $docId]);
                $pdo->commit();
                audit_logs('Ubah status dokumen', "Dokumen #{$docId}: {$status}");
                flash('Status dokumen diperbarui.');
                redirect(page_url('document-detail', ['id' => $docId]));

            case 'save_borrower':
                $borrowerId = (int) ($_POST['id'] ?? 0);
                if (!$borrowerId) {
                    throw new RuntimeException('Peminjam didaftarkan melalui formulir Peminjaman Baru.');
                }
                $name = request_string('name', 160);
                $employeeId = request_string('employee_id', 60);
                $unitId = (int) ($_POST['unit_id'] ?? 0);
                $phone = request_string('phone', 40);
                $email = request_string('email', 190);
                if ($name === '') {
                    throw new RuntimeException('Nama peminjam wajib diisi.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Format email tidak valid.');
                }
                if ($unitId) {
                    $unitCheck = $pdo->prepare('SELECT id FROM units WHERE id = ? AND active = 1');
                    $unitCheck->execute([$unitId]);
                    if (!$unitCheck->fetchColumn()) {
                        throw new RuntimeException('Unit kerja tidak tersedia.');
                    }
                }
                if ($borrowerId) {
                    $pdo->prepare('UPDATE borrowers SET name=?, employee_id=?, unit_id=?, phone=?, email=? WHERE id=?')
                        ->execute([$name, $employeeId ?: null, $unitId ?: null, $phone ?: null, $email ?: null, $borrowerId]);
                    audit_logs('Ubah peminjam', $name);
                }
                flash('Data peminjam berhasil disimpan.');
                redirect('index.php?page=borrowers');

            case 'toggle_borrower':
                $borrowerId = (int) ($_POST['id'] ?? 0);
                $pdo->prepare('UPDATE borrowers SET active = 1 - active WHERE id = ?')->execute([$borrowerId]);
                audit_logs('Ubah status peminjam', "Peminjam #{$borrowerId}");
                flash('Status peminjam diperbarui.');
                redirect(page_url('borrower-detail', ['id' => $borrowerId]));

            case 'delete_borrower':
                $borrowerId = (int) ($_POST['id'] ?? 0);
                $history = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE borrower_id = ?');
                $history->execute([$borrowerId]);
                if ((int) $history->fetchColumn() > 0) {
                    throw new RuntimeException('Peminjam yang memiliki riwayat tidak dapat dihapus. Nonaktifkan peminjam sebagai gantinya.');
                }
                $pdo->prepare('DELETE FROM borrowers WHERE id = ?')->execute([$borrowerId]);
                audit_logs('Hapus peminjam', "Peminjam #{$borrowerId}");
                flash('Data peminjam berhasil dihapus.');
                redirect('index.php?page=borrowers');

            case 'create_loan':
                $borrowerName = request_string('name', 160);
                $employeeId = request_string('employee_id', 60);
                $unitId = (int) ($_POST['unit_id'] ?? 0);
                $phone = request_string('phone', 40);
                $email = request_string('email', 190);
                $loanDate = trim((string) ($_POST['loan_date'] ?? ''));
                $dueDate = trim((string) ($_POST['due_date'] ?? ''));
                $purpose = request_string('purpose', 255);
                $notes = request_string('notes', 5000);
                $documentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['document_ids'] ?? [])))));
                if ($borrowerName === '') {
                    throw new RuntimeException('Nama peminjam wajib diisi.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Format email peminjam tidak valid.');
                }
                if (!$documentIds || count($documentIds) > 100) {
                    throw new RuntimeException('Pilih minimal satu dokumen dan maksimal 100 dokumen per transaksi.');
                }
                if (!valid_date($loanDate) || !valid_date($dueDate) || $dueDate < $loanDate) {
                    throw new RuntimeException('Tanggal pinjam dan tenggat wajib valid. Tenggat tidak boleh sebelum tanggal pinjam.');
                }
                if ($purpose === '') {
                    throw new RuntimeException('Keperluan peminjaman wajib diisi.');
                }
                $pdo->beginTransaction();
                if ($unitId) {
                    $unitCheck = $pdo->prepare('SELECT id FROM units WHERE id=? AND active=1');
                    $unitCheck->execute([$unitId]);
                    if (!$unitCheck->fetchColumn()) {
                        throw new RuntimeException('Unit kerja peminjam tidak tersedia.');
                    }
                }
                if ($employeeId !== '') {
                    $borrowerCheck = $pdo->prepare('SELECT id FROM borrowers WHERE employee_id = ? FOR UPDATE');
                    $borrowerCheck->execute([$employeeId]);
                    $borrowerId = (int) ($borrowerCheck->fetchColumn() ?: 0);
                } else {
                    $borrowerId = 0;
                }
                if ($borrowerId) {
                    $pdo->prepare('UPDATE borrowers SET name=?, unit_id=?, phone=?, email=?, active=1 WHERE id=?')
                        ->execute([$borrowerName, $unitId ?: null, $phone ?: null, $email ?: null, $borrowerId]);
                    audit_logs('Perbarui data peminjam via transaksi', $borrowerName);
                } else {
                    $insertBorrower = $pdo->prepare('INSERT INTO borrowers (name, employee_id, unit_id, phone, email) VALUES (?, ?, ?, ?, ?) RETURNING id');
                    $insertBorrower->execute([$borrowerName, $employeeId ?: null, $unitId ?: null, $phone ?: null, $email ?: null]);
                    $borrowerId = (int) $insertBorrower->fetchColumn();
                    audit_logs('Tambah peminjam via transaksi', $borrowerName);
                }
                $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
                $lock = $pdo->prepare("SELECT id, status FROM documents WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
                $lock->execute($documentIds);
                $documents = $lock->fetchAll();
                if (count($documents) !== count($documentIds)) {
                    throw new RuntimeException('Sebagian dokumen tidak ditemukan.');
                }
                foreach ($documents as $document) {
                    if ($document['status'] !== 'Tersedia') {
                        throw new RuntimeException("Dokumen #{$document['id']} tidak tersedia untuk dipinjam.");
                    }
                }
                $stmt = $pdo->prepare('INSERT INTO loans (borrower_id, admin_id, loan_date, due_date, purpose, notes) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
                $stmt->execute([$borrowerId, id(), $loanDate, $dueDate, $purpose, $notes ?: null]);
                $loanId = (int) $stmt->fetchColumn();
                $addItem = $pdo->prepare('INSERT INTO loan_items (loan_id, document_id) VALUES (?, ?)');
                $markBorrowed = $pdo->prepare("UPDATE documents SET status = 'Dipinjam' WHERE id = ?");
                foreach ($documents as $document) {
                    $addItem->execute([$loanId, $document['id']]);
                    $markBorrowed->execute([$document['id']]);
                }
                $pdo->commit();
                audit_logs('Peminjaman baru', "Transaksi #{$loanId}");
                flash("Peminjaman #{$loanId} berhasil dicatat.");
                redirect(page_url('loan-detail', ['id' => $loanId]));

            case 'return_items':
                $loanId = (int) ($_POST['loan_id'] ?? 0);
                $itemIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['item_ids'] ?? [])))));
                if (!$itemIds) {
                    throw new RuntimeException('Pilih setidaknya satu dokumen yang dikembalikan.');
                }
                $pdo->beginTransaction();
                $loan = get_loan($loanId);
                if (!$loan || (int) $loan['active_items'] === 0) {
                    throw new RuntimeException('Transaksi tidak ditemukan atau sudah selesai.');
                }
                $today = date('Y-m-d');
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $lock = $pdo->prepare("SELECT li.id, li.document_id FROM loan_items li WHERE li.loan_id = ? AND li.returned_at IS NULL AND li.id IN ({$placeholders}) FOR UPDATE");
                $lock->execute(array_merge([$loanId], $itemIds));
                $items = $lock->fetchAll();
                if (count($items) !== count($itemIds)) {
                    throw new RuntimeException('Item pengembalian tidak valid atau telah diproses.');
                }
                foreach ($items as $item) {
                    $itemId = (int) $item['id'];
                    $date = trim((string) ($_POST['return_date'][$itemId] ?? $today));
                    $condition = (string) ($_POST['return_condition'][$itemId] ?? 'Baik');
                    $note = trim((string) ($_POST['return_note'][$itemId] ?? ''));
                    if (mb_strlen($note) > 1000) {
                        throw new RuntimeException('Catatan pengembalian maksimal 1.000 karakter.');
                    }
                    if (!valid_date($date) || $date < $loan['loan_date'] || $date > $today) {
                        throw new RuntimeException('Tanggal kembali harus berada di antara tanggal pinjam dan hari ini.');
                    }
                    if (!in_array($condition, ['Baik','Perlu perbaikan','Rusak','Hilang'], true)) {
                        throw new RuntimeException('Kondisi dokumen saat kembali tidak valid.');
                    }
                    $pdo->prepare('UPDATE loan_items SET returned_at=?, return_condition=?, return_note=? WHERE id=?')
                        ->execute([$date, $condition, $note ?: null, $itemId]);
                    $newStatus = $condition === 'Hilang' ? 'Hilang' : 'Tersedia';
                    $pdo->prepare('UPDATE documents SET status=?, condition_state=? WHERE id=?')
                        ->execute([$newStatus, in_array($condition, ['Baik','Perlu perbaikan','Rusak'], true) ? $condition : 'Rusak', $item['document_id']]);
                }
                $pdo->commit();
                audit_logs('Pengembalian dokumen', "Transaksi #{$loanId}");
                flash('Pengembalian berhasil dicatat.');
                redirect(page_url('loan-detail', ['id' => $loanId]));

            case 'extend_loan':
                $loanId = (int) ($_POST['loan_id'] ?? 0);
                $newDue = trim((string) ($_POST['due_date'] ?? ''));
                $reason = request_string('reason', 255);
                $maximum = (int) setting('max_extensions', '2');
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT * FROM loans WHERE id = ? FOR UPDATE');
                $lock->execute([$loanId]);
                $loan = $lock->fetch();
                if (!$loan) {
                    throw new RuntimeException('Transaksi tidak ditemukan.');
                }
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM loan_extensions WHERE loan_id = ?');
                $countStmt->execute([$loanId]);
                $count = (int) $countStmt->fetchColumn();
                if ($count >= $maximum) {
                    throw new RuntimeException("Batas perpanjangan ({$maximum} kali) telah tercapai.");
                }
                if (!valid_date($newDue) || $newDue <= $loan['due_date'] || $reason === '') {
                    throw new RuntimeException('Tenggat baru harus setelah tenggat saat ini dan alasan wajib diisi.');
                }
                $activeCheck = $pdo->prepare('SELECT COUNT(*) FROM loan_items WHERE loan_id = ? AND returned_at IS NULL');
                $activeCheck->execute([$loanId]);
                if ((int) $activeCheck->fetchColumn() === 0) {
                    throw new RuntimeException('Transaksi yang sudah selesai tidak dapat diperpanjang.');
                }
                $pdo->prepare('INSERT INTO loan_extensions (loan_id, admin_id, old_due_date, new_due_date, reason) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$loanId, id(), $loan['due_date'], $newDue, $reason]);
                $pdo->prepare('UPDATE loans SET due_date = ? WHERE id = ?')->execute([$newDue, $loanId]);
                $pdo->commit();
                audit_logs('Perpanjangan peminjaman', "Transaksi #{$loanId}: {$newDue}");
                flash('Tenggat peminjaman berhasil diperpanjang.');
                redirect(page_url('loan-detail', ['id' => $loanId]));

            case 'save_admin':
                require_super_admin();
                $adminId = (int) ($_POST['id'] ?? 0);
                $name = request_string('name', 120);
                $username = trim(mb_strtolower(request_string('username', 60)));
                $role = (string) ($_POST['role'] ?? 'Admin');
                $password = (string) ($_POST['password'] ?? '');
                if ($name === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                    throw new RuntimeException('Nama wajib diisi dan username harus 3–60 karakter (huruf, angka, titik, garis bawah, atau tanda hubung).');
                }
                if (!in_array($role, ['Admin','Super Admin'], true)) {
                    throw new RuntimeException('Peran admin tidak valid.');
                }
                if ($adminId === id() && $role !== ($_SESSION['admin']['role'] ?? '')) {
                    throw new RuntimeException('Peran akun yang sedang digunakan tidak dapat diubah.');
                }
                if (!$adminId && strlen($password) < 12) {
                    throw new RuntimeException('Kata sandi admin baru minimal 12 karakter.');
                }
                if ($adminId) {
                    $pdo->prepare('UPDATE admins SET name=?, username=?, role=? WHERE id=?')->execute([$name, $username, $role, $adminId]);
                    if ($password !== '') {
                        if (strlen($password) < 12) {
                            throw new RuntimeException('Kata sandi minimal 12 karakter.');
                        }
                        $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $adminId]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO admins (name, username, password_hash, role) VALUES (?, ?, ?, ?)')
                        ->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
                }
                audit_logs($adminId ? 'Ubah akun admin' : 'Tambah akun admin', $username);
                flash('Data admin berhasil disimpan.');
                redirect('index.php?page=settings');

            case 'toggle_admin':
                require_super_admin();
                $adminId = (int) ($_POST['id'] ?? 0);
                if ($adminId === id()) {
                    throw new RuntimeException('Akun yang sedang digunakan tidak dapat dinonaktifkan.');
                }
                $admin = $pdo->prepare('SELECT role, active FROM admins WHERE id = ?');
                $admin->execute([$adminId]);
                $target = $admin->fetch();
                if (!$target) {
                    throw new RuntimeException('Akun admin tidak ditemukan.');
                }
                if ($target['role'] === 'Super Admin' && (int) $target['active'] === 1) {
                    $others = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role='Super Admin' AND active=1 AND id<>?");
                    $others->execute([$adminId]);
                    if ((int) $others->fetchColumn() === 0) {
                        throw new RuntimeException('Harus ada minimal satu akun Super Admin aktif.');
                    }
                }
                $pdo->prepare('UPDATE admins SET active = 1 - active WHERE id = ?')->execute([$adminId]);
                audit_logs('Ubah status akun admin', "Admin #{$adminId}");
                flash('Status akun admin diperbarui.');
                redirect('index.php?page=settings');

            case 'reset_admin_password':
                require_super_admin();
                $adminId = (int) ($_POST['id'] ?? 0);
                $password = (string) ($_POST['new_password'] ?? '');
                if ($adminId === id()) {
                    throw new RuntimeException('Gunakan menu Ubah kata sandi untuk akun Anda sendiri.');
                }
                if (strlen($password) < 12) {
                    throw new RuntimeException('Kata sandi baru minimal 12 karakter.');
                }
                $target = $pdo->prepare('SELECT username FROM admins WHERE id=?');
                $target->execute([$adminId]);
                $targetUsername = $target->fetchColumn();
                if (!$targetUsername) {
                    throw new RuntimeException('Akun admin tidak ditemukan.');
                }
                $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $adminId]);
                audit_logs('Reset kata sandi admin', (string) $targetUsername);
                flash('Kata sandi akun admin berhasil direset.');
                redirect('index.php?page=settings');

            case 'save_setting':
                require_super_admin();
                $days = (int) ($_POST['default_loan_days'] ?? 14);
                $maximum = (int) ($_POST['max_extensions'] ?? 2);
                if ($days < 1 || $days > 365 || $maximum < 0 || $maximum > 10) {
                    throw new RuntimeException('Durasi harus 1–365 hari dan maksimum perpanjangan 0–10 kali.');
                }
                $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value');
                $stmt->execute(['default_loan_days', (string) $days]);
                $stmt->execute(['max_extensions', (string) $maximum]);
                audit_logs('Ubah aturan peminjaman', "{$days} hari, maksimal {$maximum} perpanjangan");
                flash('Aturan peminjaman berhasil disimpan.');
                redirect('index.php?page=settings');

            case 'add_master':
                require_super_admin();
                $type = (string) ($_POST['master_type'] ?? '');
                $name = request_string('name', 120);
                $tables = ['unit' => 'units', 'category' => 'right_categories', 'district' => 'districts'];
                if (!isset($tables[$type]) || $name === '') {
                    throw new RuntimeException('Data master tidak valid.');
                }
                $pdo->prepare("INSERT INTO {$tables[$type]} (name) VALUES (?)")->execute([$name]);
                audit_logs('Tambah master data', "{$type}: {$name}");
                flash('Data master berhasil ditambahkan.');
                redirect('index.php?page=settings');

            case 'deactivate_master':
                require_super_admin();
                $type = (string) ($_POST['master_type'] ?? '');
                $masterId = (int) ($_POST['id'] ?? 0);
                if ($type === 'category') {
                    $used = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE right_category_id = ?');
                    $used->execute([$masterId]);
                    if ((int) $used->fetchColumn() > 0) {
                        throw new RuntimeException('Kategori yang telah digunakan dokumen tidak dapat dinonaktifkan.');
                    }
                    $table = 'right_categories';
                } elseif ($type === 'unit') {
                    $table = 'units';
                } elseif ($type === 'district') {
                    $used = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE district = (SELECT name FROM districts WHERE id=?)');
                    $used->execute([$masterId]);
                    if ((int) $used->fetchColumn() > 0) {
                        throw new RuntimeException('Kecamatan yang telah digunakan dokumen tidak dapat dinonaktifkan.');
                    }
                    $table = 'districts';
                } else {
                    throw new RuntimeException('Data master tidak valid.');
                }
                $pdo->prepare("UPDATE {$table} SET active=0 WHERE id=?")->execute([$masterId]);
                audit_logs('Nonaktifkan master data', "{$type} #{$masterId}");
                flash('Data master dinonaktifkan.');
                redirect('index.php?page=settings');

            case 'change_password':
                $current = (string) ($_POST['current_password'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');
                $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ? AND active = 1');
                $stmt->execute([id()]);
                $hash = (string) $stmt->fetchColumn();
                if (!password_verify($current, $hash)) {
                    throw new RuntimeException('Kata sandi saat ini tidak sesuai.');
                }
                if (strlen($new) < 12 || $new !== $confirm) {
                    throw new RuntimeException('Kata sandi baru harus minimal 12 karakter dan kedua isian harus sama.');
                }
                $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), id()]);
                audit_logs('Ubah kata sandi');
                flash('Kata sandi berhasil diubah.');
                redirect('index.php?page=password');

            default:
                throw new RuntimeException('Aksi tidak dikenali.');
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log((string) $error);
        $actionError = $error instanceof RuntimeException ? $error->getMessage() : 'Perubahan tidak dapat disimpan. Periksa input, lalu coba lagi.';
    }
}

if ($page === 'login') {
    if (!empty($_SESSION['admin'])) {
        redirect('index.php?page=dashboard');
    }
    $notice = take_flash();
    ?>
    <!doctype html>
    <html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Masuk · BTSU</title><link rel="stylesheet" href="assets/styles.css"></head>
    <body class="auth-page"><main class="auth-card">
      <a class="brand-mark" href="index.php?page=login">BTSU</a>
      <p class="eyebrow">TATA KELOLA ARSIP PERTANAHAN</p>
      <h1>Selamat datang</h1><p class="muted">Masuk untuk mengelola Buku Tanah dan Surat Ukur.</p>
      <?php if ($notice): ?><div class="notice <?= h($notice['type']) ?>"><?= h($notice['message']) ?></div><?php endif; ?>
      <?php if ($actionError): ?><div class="notice error"><?= h($actionError) ?></div><?php endif; ?>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="login">
        <label>Username<input name="username" required autocomplete="username" autofocus></label>
        <label>Kata sandi<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="button primary wide">Masuk</button>
      </form>
      <p class="auth-foot"><a href="index.php?page=forgot">Lupa kata sandi?</a> · Sesi berakhir setelah 30 menit tidak aktif.</p>
    </main></body></html>
    <?php
    exit;
}

if ($page === 'forgot') {
    ?>
    <!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bantuan kata sandi · BTSU</title><link rel="stylesheet" href="assets/styles.css"></head>
    <body class="auth-page"><main class="auth-card"><a class="brand-mark" href="index.php?page=login">BTSU</a><p class="eyebrow">BANTUAN AKUN</p><h1>Lupa kata sandi?</h1><p class="muted">Hubungi Super Admin kantor untuk meminta reset kata sandi. Demi keamanan, sistem tidak mengirimkan tautan reset ke alamat yang belum diverifikasi.</p><a class="button primary wide button-link" href="index.php?page=login">Kembali ke halaman masuk</a></main></body></html>
    <?php
    exit;
}

require_login();
if ($page === 'settings') {
    require_super_admin();
}
$notice = take_flash();
$currentAdmin = $_SESSION['admin'];
$content = '';
$clientData = [];
$pageTitle = 'Dashboard';

function page_head(string $title, string $subtitle = '', string $actions = ''): string
{
    return '<div class="page-head"><div><p class="eyebrow">SISTEM ADMINISTRASI DOKUMEN</p><h1>' . h($title) . '</h1>' .
        ($subtitle !== '' ? '<p class="muted">' . h($subtitle) . '</p>' : '') .
        '</div><div class="head-actions">' . $actions . '</div></div>';
}

function button_link(string $text, string $href, string $class = 'button'): string
{
    return '<a class="' . h($class) . '" href="' . h($href) . '">' . h($text) . '</a>';
}

function form_button(string $text, string $action, array $fields = [], string $class = 'button'): string
{
    $html = '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $key => $value) {
        $html .= '<input type="hidden" name="' . h((string) $key) . '" value="' . h((string) $value) . '">';
    }
    return $html . '<button class="' . h($class) . '">' . h($text) . '</button></form>';
}

function table_html(array $headers, array $rows, string $empty = 'Belum ada data.'): string
{
    if (!$rows) {
        return '<div class="empty-state"><strong>' . h($empty) . '</strong><span>Data akan muncul di sini setelah dicatat.</span></div>';
    }
    $html = '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . h($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . $cell . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

function pagination(string $page, int $current, int $perPage, int $total, array $params = []): string
{
    $last = max(1, (int) ceil($total / $perPage));
    if ($last <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Halaman daftar"><span>Halaman ' . h($current) . ' dari ' . h($last) . ' · ' . h($total) . ' data</span><div>';
    if ($current > 1) {
        $html .= '<a class="button small" href="' . h(page_url($page, $params + ['page_number' => $current - 1])) . '">Sebelumnya</a>';
    }
    if ($current < $last) {
        $html .= '<a class="button small" href="' . h(page_url($page, $params + ['page_number' => $current + 1])) . '">Berikutnya</a>';
    }
    return $html . '</div></nav>';
}

function active_loan_items(int $loanId): array
{
    $stmt = db()->prepare('SELECT li.id AS item_id, li.document_id, d.right_number, c.name AS category, d.district, d.village, li.returned_at, li.return_condition FROM loan_items li JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id WHERE li.loan_id=? ORDER BY li.id');
    $stmt->execute([$loanId]);
    return $stmt->fetchAll();
}

switch ($page) {
    case 'dashboard':
        $pageTitle = 'Dashboard';
        $counts = $pdo->query(
            "SELECT
              COUNT(*) AS total,
              COUNT(*) FILTER (WHERE d.status='Dipinjam') AS borrowed,
              COUNT(*) FILTER (WHERE d.status='Dipinjam' AND EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE)) AS overdue,
              COUNT(*) FILTER (WHERE d.status='Dipinjam' AND EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '3 days')) AS due_soon
             FROM documents d WHERE d.status <> 'Nonaktif'"
        )->fetch();
        $cards = [
            ['Total dokumen', (int) $counts['total'], 'Arsip aktif terdaftar', ''],
            ['Sedang dipinjam', (int) $counts['borrowed'], 'Dokumen dalam transaksi aktif', ''],
            ['Terlambat', (int) $counts['overdue'], 'Perlu ditindaklanjuti', 'overdue-text'],
            ['Jatuh tempo ≤ 3 hari', (int) $counts['due_soon'], 'Pengembalian mendekati tenggat', 'due-text'],
        ];
        $content = page_head('Dashboard', 'Ringkasan Buku Tanah dan Surat Ukur kantor Semarang.') . '<section class="stats-grid">';
        foreach ($cards as [$title, $number, $caption, $class]) {
            $content .= '<article class="stat-card"><span>' . h($title) . '</span><strong class="' . h($class) . '">' . h($number) . '</strong><small>' . h($caption) . '</small></article>';
        }
        $content .= '</section><div class="dashboard-grid"><section class="panel"><div class="section-title"><h2>Daftar keterlambatan</h2><a href="' . h(page_url('loans', ['filter' => 'Terlambat'])) . '">Lihat semua</a></div>';
        $late = $pdo->query(
            "SELECT l.id, b.name AS borrower_name, l.due_date, CURRENT_DATE - l.due_date AS late_days,
                STRING_AGG(d.right_number, ', ' ORDER BY d.right_number) AS numbers
             FROM loans l JOIN borrowers b ON b.id=l.borrower_id
             JOIN loan_items li ON li.loan_id=l.id AND li.returned_at IS NULL
             JOIN documents d ON d.id=li.document_id
             WHERE l.due_date<CURRENT_DATE GROUP BY l.id, b.name ORDER BY l.due_date LIMIT 6"
        )->fetchAll();
        $rows = [];
        foreach ($late as $loan) {
            $rows[] = [
                '<a href="' . h(page_url('loan-detail', ['id' => $loan['id']])) . '">' . h($loan['borrower_name']) . '</a>',
                h($loan['numbers']),
                h(date_id($loan['due_date'])),
                '<span class="overdue-text">' . h($loan['late_days']) . ' hari</span>',
            ];
        }
        $content .= table_html(['Peminjam', 'Nomor hak', 'Tenggat', 'Keterlambatan'], $rows, 'Tidak ada dokumen terlambat.');
        $content .= '</section><section class="panel"><div class="section-title"><h2>Peminjaman aktif terbaru</h2><a href="' . h(page_url('loans')) . '">Lihat semua</a></div>';
        $active = $pdo->query(
            "SELECT l.id, b.name AS borrower_name, l.loan_date, l.due_date,
                (SELECT COUNT(*) FROM loan_items x WHERE x.loan_id=l.id AND x.returned_at IS NULL) AS active_items
             FROM loans l JOIN borrowers b ON b.id=l.borrower_id
             WHERE EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)
             ORDER BY l.due_date LIMIT 6"
        )->fetchAll();
        $rows = [];
        foreach ($active as $loan) {
            $rows[] = [
                '<a href="' . h(page_url('loan-detail', ['id' => $loan['id']])) . '">' . h($loan['borrower_name']) . '</a>',
                h($loan['active_items']) . ' dokumen',
                h(date_id($loan['loan_date'])),
                h(date_id($loan['due_date'])),
                status_badge(loan_status($loan)),
            ];
        }
        $content .= table_html(['Peminjam', 'Dokumen', 'Tanggal pinjam', 'Tenggat', 'Status'], $rows, 'Belum ada peminjaman aktif.');
        $activities = $pdo->query('SELECT a.action, a.detail, a.created_at, ad.name FROM audit_logs a LEFT JOIN admins ad ON ad.id=a.admin_id ORDER BY a.created_at DESC LIMIT 8')->fetchAll();
        $content .= '</section><section class="panel activity-panel"><h2>Aktivitas terbaru</h2>';
        if (!$activities) {
            $content .= '<p class="muted">Belum ada aktivitas tercatat.</p>';
        } else {
            $content .= '<ul class="activity-list">';
            foreach ($activities as $activity) {
                $content .= '<li><strong>' . h($activity['action']) . '</strong><span>' . h($activity['detail'] ?: $activity['name'] ?: 'Sistem') . '</span><small>' . h(date('d M Y, H:i', strtotime($activity['created_at']))) . '</small></li>';
            }
            $content .= '</ul>';
        }
        $content .= '</section></div>';
        break;

    case 'documents':
        $pageTitle = 'Dokumen';
        $query = trim((string) ($_GET['q'] ?? ''));
        $statusFilter = trim((string) ($_GET['status'] ?? ''));
        $categoryFilter = (int) ($_GET['category'] ?? 0);
        $where = [];
        $params = [];
        if ($query !== '') {
            $where[] = '(d.right_number ILIKE ? OR c.name ILIKE ? OR d.village ILIKE ? OR d.district ILIKE ?)';
            array_push($params, "{$query}%", "{$query}%", "{$query}%", "{$query}%");
        }
        if ($categoryFilter) {
            $where[] = 'd.right_category_id=?';
            $params[] = $categoryFilter;
        }
        if ($statusFilter !== '') {
            if ($statusFilter === 'Dipinjam') {
                $where[] = "d.status='Dipinjam' AND NOT EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE)";
            } elseif ($statusFilter === 'Terlambat') {
                $where[] = "EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE)";
            } else {
                $where[] = 'd.status=?';
                $params[] = $statusFilter;
            }
        }
        $fromSql = ' FROM documents d JOIN right_categories c ON c.id=d.right_category_id';
        $filterSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $totalStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $filterSql);
        $totalStmt->execute($params);
        $totalDocuments = (int) $totalStmt->fetchColumn();
        $perPage = 100;
        $lastPage = max(1, (int) ceil($totalDocuments / $perPage));
        $pageNumber = min($lastPage, max(1, (int) ($_GET['page_number'] ?? 1)));
        $offset = ($pageNumber - 1) * $perPage;
        $sql = 'SELECT d.*, c.name AS category_name, ' . doc_status_sql() . ' AS effective_status' . $fromSql . $filterSql;
        $sql .= " ORDER BY d.updated_at DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
        $categories = $pdo->query('SELECT id,name FROM right_categories WHERE active=1 ORDER BY name')->fetchAll();
        $content = page_head('Dokumen', 'Inventaris Buku Tanah dan Surat Ukur.', button_link('Impor CSV', page_url('document-import')) . button_link('Tambah dokumen', page_url('document-form'), 'button primary'));
        $content .= '<form method="get" class="filter-bar"><input type="hidden" name="page" value="documents"><label class="search-field">Cari nomor hak, jenis hak, kecamatan, desa<input name="q" value="' . h($query) . '" placeholder="Ketik kata kunci…"></label><label>Jenis hak<select name="category"><option value="">Semua jenis hak</option>';
        foreach ($categories as $category) {
            $content .= '<option value="' . h($category['id']) . '"' . ($categoryFilter === (int) $category['id'] ? ' selected' : '') . '>' . h($category['name']) . '</option>';
        }
        $content .= '</select></label><label>Status<select name="status"><option value="">Semua status</option>';
        foreach (['Tersedia','Dipinjam','Terlambat','Hilang','Nonaktif'] as $status) {
            $content .= '<option' . ($statusFilter === $status ? ' selected' : '') . '>' . h($status) . '</option>';
        }
        $content .= '</select></label><button class="button">Terapkan</button></form><section class="panel">';
        $rows = [];
        foreach ($documents as $document) {
            $rows[] = [
                '<a href="' . h(page_url('document-detail', ['id' => $document['id']])) . '"><strong>' . h($document['right_number']) . '</strong></a>',
                h($document['category_name']),
                h($document['district'] . ', ' . $document['village']),
                h("L{$document['floor']} · Lemari {$document['cabinet']} · Rak {$document['rack']}"),
                status_badge($document['effective_status']),
                '<a class="button small" href="' . h(page_url('document-detail', ['id' => $document['id']])) . '">Detail</a>',
            ];
        }
        $content .= '<div class="section-title"><h2>Daftar dokumen</h2><span class="muted">' . h($totalDocuments) . ' hasil</span></div>' . table_html(['Nomor hak','Jenis hak','Kecamatan / desa','Lokasi arsip','Status',''], $rows, 'Dokumen belum ditambahkan.');
        $content .= pagination('documents', $pageNumber, $perPage, $totalDocuments, ['q' => $query, 'status' => $statusFilter, 'category' => $categoryFilter]) . '</section>';
        break;

    case 'document-form':
        $pageTitle = 'Form dokumen';
        $docId = (int) ($_GET['id'] ?? 0);
        $document = null;
        if ($docId) {
            $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=?');
            $stmt->execute([$docId]);
            $document = $stmt->fetch();
            if (!$document) {
                http_response_code(404);
                $content = page_head('Dokumen tidak ditemukan');
                break;
            }
        }
        $districtStmt = $pdo->prepare('SELECT name FROM districts WHERE active=1 OR name=? ORDER BY name');
        $districtStmt->execute([$document['district'] ?? '']);
        $districts = $districtStmt->fetchAll();
        $villageRows = $pdo->query('SELECT d.name AS district,v.name AS village FROM villages v JOIN districts d ON d.id=v.district_id WHERE v.active=1 ORDER BY d.name,v.name')->fetchAll();
        $villagesByDistrict = [];
        foreach ($villageRows as $villageRow) {
            $villagesByDistrict[$villageRow['district']][] = $villageRow['village'];
        }
        $clientData['villages'] = $villagesByDistrict;
        $selectedDistrictName = '';
        foreach ($districts as $district) {
            if (mb_strtolower($district['name']) === mb_strtolower((string) ($document['district'] ?? ''))) {
                $selectedDistrictName = $district['name'];
                break;
            }
        }
        $selectedVillages = $villagesByDistrict[$selectedDistrictName] ?? [];
        $categories = $pdo->query('SELECT id,name FROM right_categories WHERE active=1 OR id=' . (int) ($document['right_category_id'] ?? 0) . ' ORDER BY name')->fetchAll();
        $pageTitle = $document ? 'Ubah dokumen' : 'Tambah dokumen';
        $content = page_head($pageTitle, 'Lokasi wilayah ditetapkan untuk Jawa Tengah · Kota Semarang.');
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<form method="post" class="panel form-panel">' . csrf_field() . '<input type="hidden" name="action" value="save_document"><input type="hidden" name="id" value="' . h($docId) . '"><div class="form-section"><h2>Wilayah dokumen</h2><div class="form-grid"><label>Provinsi<input value="Jawa Tengah" readonly></label><label>Kabupaten/Kota<input value="Semarang" readonly></label><label>Kecamatan<select name="district" id="district-select" required><option value="">Pilih kecamatan</option>';
        foreach ($districts as $district) {
            $selected = $selectedDistrictName === $district['name'] ? ' selected' : '';
            $content .= '<option' . $selected . '>' . h($district['name']) . '</option>';
        }
        $content .= '</select></label><label>Desa/Kelurahan<select name="village" id="village-select" required><option value="">Pilih kelurahan</option>';
        $currentVillageFound = false;
        foreach ($selectedVillages as $villageName) {
            $selected = mb_strtolower($villageName) === mb_strtolower((string) ($document['village'] ?? '')) ? ' selected' : '';
            if ($selected !== '') {
                $currentVillageFound = true;
            }
            $content .= '<option' . $selected . '>' . h($villageName) . '</option>';
        }
        if ($document && !$currentVillageFound) {
            $content .= '<option value="' . h($document['village']) . '" selected>' . h($document['village']) . ' (data tersimpan)</option>';
        }
        $content .= '</select><small>Pilihan kelurahan mengikuti kecamatan yang dipilih.</small></label></div></div><div class="form-section"><h2>Identitas dan lokasi arsip</h2><div class="form-grid"><label>Jenis hak<select name="right_category_id" required><option value="">Pilih jenis hak</option>';
        foreach ($categories as $category) {
            $content .= '<option value="' . h($category['id']) . '"' . ((int) ($document['right_category_id'] ?? 0) === (int) $category['id'] ? ' selected' : '') . '>' . h($category['name']) . '</option>';
        }
        $content .= '</select></label><label>Nomor hak<input name="right_number" maxlength="80" required value="' . h($document['right_number'] ?? '') . '"></label><label>Tanggal dokumen<input type="date" name="document_date" value="' . h($document['document_date'] ?? '') . '"></label><label>Kondisi<select name="condition_state">';
        foreach (['Baik','Perlu perbaikan','Rusak'] as $condition) {
            $content .= '<option' . (($document['condition_state'] ?? 'Baik') === $condition ? ' selected' : '') . '>' . h($condition) . '</option>';
        }
        $content .= '</select></label>';
        foreach (['floor' => 'Lantai', 'cabinet' => 'Lemari'] as $field => $label) {
            $content .= '<label>' . h($label) . '<input type="number" name="' . h($field) . '" min="1" max="4294967295" step="1" required value="' . h($document[$field] ?? '1') . '"></label>';
        }
        $rackMatch = [];
        preg_match('/^(\d+)([ab])$/iD', (string) ($document['rack'] ?? '1a'), $rackMatch);
        $currentRackNumber = positive_archive_number($rackMatch[1] ?? '') ?? '1';
        $currentRackSide = strtolower($rackMatch[2] ?? 'a');
        if (!in_array($currentRackSide, ['a','b'], true)) {
            $currentRackSide = 'a';
        }
        $content .= '<label>Nomor rak<input type="number" name="rack_number" min="1" step="1" required value="' . h($currentRackNumber) . '"></label><label>Sisi rak<select name="rack_side"><option value="a"' . ($currentRackSide === 'a' ? ' selected' : '') . '>A</option><option value="b"' . ($currentRackSide === 'b' ? ' selected' : '') . '>B</option></select></label><label>Album<input name="album" maxlength="80" value="' . h($document['album'] ?? '') . '"></label><label class="full">Catatan<textarea name="notes" rows="3" maxlength="5000">' . h($document['notes'] ?? '') . '</textarea></label></div></div><div class="form-actions"><button class="button primary">Simpan dokumen</button><a class="button" href="' . h(page_url('documents')) . '">Batal</a></div></form>';
        break;

    case 'document-import':
        $pageTitle = 'Impor dokumen';
        $content = page_head('Impor dokumen', 'Tambahkan banyak dokumen sekaligus menggunakan file CSV.');
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<section class="panel form-panel narrow"><h2>Format file</h2><p class="muted">Gunakan header dan urutan kolom pada template. Kecamatan dan jenis hak harus sudah tersedia di master data. Baris valid diimpor; baris duplikat atau tidak valid akan dilaporkan.</p><p><a class="button" href="database/template_dokumen.csv" download>Unduh template CSV</a></p><form method="post" enctype="multipart/form-data" class="stack">' . csrf_field() . '<input type="hidden" name="action" value="import_documents"><label>Pilih file CSV<input type="file" name="csv_file" accept=".csv,text/csv" required><small>Maksimal 5 MB dan 10.000 baris.</small></label><button class="button primary">Mulai impor</button></form></section>';
        break;

    case 'document-detail':
        $pageTitle = 'Detail dokumen';
        $stmt = $pdo->prepare('SELECT d.*, c.name AS category_name, ' . doc_status_sql() . ' AS effective_status FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.id=?');
        $stmt->execute([(int) ($_GET['id'] ?? 0)]);
        $document = $stmt->fetch();
        if (!$document) {
            http_response_code(404);
            $content = page_head('Dokumen tidak ditemukan');
            break;
        }
        $pageTitle = 'Nomor hak ' . $document['right_number'];
        $historyStmt = $pdo->prepare('SELECT l.id,l.loan_date,l.due_date,l.borrower_id,b.name AS borrower_name,li.returned_at FROM loan_items li JOIN loans l ON l.id=li.loan_id JOIN borrowers b ON b.id=l.borrower_id WHERE li.document_id=? ORDER BY l.loan_date DESC');
        $historyStmt->execute([$document['id']]);
        $history = $historyStmt->fetchAll();
        $actions = button_link('Ubah', page_url('document-form', ['id' => $document['id']])) . button_link('Kembali', page_url('documents'));
        $content = page_head('Nomor hak ' . $document['right_number'], $document['category_name'] . ' · ' . $document['district'] . ', ' . $document['village'] . ' · ' . $document['effective_status'], $actions);
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<section class="panel"><div class="section-title"><h2>Informasi dokumen</h2>' . status_badge($document['effective_status']) . '</div><dl class="detail-grid">';
        $details = [
            'Provinsi' => $document['province'], 'Kabupaten/Kota' => $document['city'], 'Kecamatan' => $document['district'], 'Desa/Kelurahan' => $document['village'],
            'Jenis hak' => $document['category_name'], 'Nomor hak' => $document['right_number'], 'Tanggal dokumen' => date_id($document['document_date']),
            'Lokasi arsip' => "Lantai {$document['floor']} · Lemari {$document['cabinet']} · Rak {$document['rack']} · Album " . ($document['album'] ?: '—'),
            'Kondisi' => $document['condition_state'], 'Catatan' => $document['notes'] ?: '—',
        ];
        foreach ($details as $label => $value) {
            $content .= '<div><dt>' . h($label) . '</dt><dd>' . h($value) . '</dd></div>';
        }
        $content .= '</dl><div class="form-actions">';
        if (in_array($document['status'], ['Hilang','Nonaktif'], true)) {
            $content .= form_button('Tandai tersedia', 'set_document_status', ['id' => $document['id'], 'status' => 'Tersedia'], 'button');
        } else {
            if ($document['status'] !== 'Dipinjam') {
                $content .= form_button('Tandai hilang', 'set_document_status', ['id' => $document['id'], 'status' => 'Hilang'], 'button danger');
                $content .= form_button('Nonaktifkan', 'set_document_status', ['id' => $document['id'], 'status' => 'Nonaktif'], 'button danger');
            }
        }
        $content .= '</div></section><section class="panel"><h2>Riwayat peminjaman</h2>';
        $rows = [];
        foreach ($history as $item) {
            $rows[] = [
                '<a href="' . h(page_url('loan-detail', ['id' => $item['id']])) . '">' . h($item['borrower_name']) . '</a>',
                h(date_id($item['loan_date'])),
                h(date_id($item['due_date'])),
                h(date_id($item['returned_at'])),
                status_badge($item['returned_at'] ? 'Selesai' : ($item['due_date'] < date('Y-m-d') ? 'Terlambat' : 'Dipinjam')),
            ];
        }
        $content .= table_html(['Peminjam','Tanggal pinjam','Tenggat','Tanggal kembali','Status'], $rows, 'Belum ada riwayat peminjaman.') . '</section>';
        break;

    case 'borrowers':
        $pageTitle = 'Peminjam';
        $query = trim((string) ($_GET['q'] ?? ''));
        $statusFilter = (string) ($_GET['status'] ?? 'aktif');
        $sql = 'SELECT b.*,u.name AS unit_name FROM borrowers b LEFT JOIN units u ON u.id=b.unit_id WHERE EXISTS (SELECT 1 FROM loans h WHERE h.borrower_id=b.id)';
        $params = [];
        if ($statusFilter !== 'semua') {
            $sql .= ' AND b.active=?';
            $params[] = $statusFilter === 'nonaktif' ? 0 : 1;
        }
        if ($query !== '') {
            $sql .= ' AND (b.name ILIKE ? OR b.employee_id ILIKE ? OR u.name ILIKE ?)';
            array_push($params, "%{$query}%", "%{$query}%", "%{$query}%");
        }
        $sql .= ' ORDER BY b.name LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $borrowers = $stmt->fetchAll();
        $periodFrom = (string) ($_GET['from'] ?? date('Y-m-01'));
        $periodTo = (string) ($_GET['to'] ?? date('Y-m-d'));
        $periodError = null;
        $borrowerReportHeaders = ['No. transaksi','Tanggal pinjam','Nama peminjam','NIP/ID','Unit kerja / seksi','Nomor hak','Jenis hak','Kecamatan','Desa/Kelurahan','Tenggat kembali','Tanggal kembali','Status','Keperluan'];
        $borrowerReportRows = [];
        if (!valid_date($periodFrom) || !valid_date($periodTo) || $periodFrom > $periodTo) {
            $periodError = 'Periode tanggal tidak valid. Pastikan tanggal awal tidak melewati tanggal akhir.';
        } else {
            $reportStmt = $pdo->prepare(
                "SELECT l.id AS loan_id,l.loan_date,l.due_date,l.purpose,b.name AS borrower_name,b.employee_id,u.name AS unit_name,
                        d.right_number,c.name AS category,d.district,d.village,li.returned_at,
                        CASE WHEN li.returned_at IS NOT NULL THEN 'Selesai' WHEN l.due_date<CURRENT_DATE THEN 'Terlambat' ELSE 'Dipinjam' END AS item_status
                 FROM loans l
                 JOIN borrowers b ON b.id=l.borrower_id
                 LEFT JOIN units u ON u.id=b.unit_id
                 JOIN loan_items li ON li.loan_id=l.id
                 JOIN documents d ON d.id=li.document_id
                 JOIN right_categories c ON c.id=d.right_category_id
                 WHERE l.loan_date BETWEEN ? AND ?
                 ORDER BY l.loan_date DESC,l.id DESC,b.name,d.right_number"
            );
            $reportStmt->execute([$periodFrom, $periodTo]);
            foreach ($reportStmt->fetchAll() as $row) {
                $borrowerReportRows[] = [
                    (string) $row['loan_id'],
                    $row['loan_date'],
                    $row['borrower_name'],
                    $row['employee_id'] ?: '—',
                    $row['unit_name'] ?: '—',
                    $row['right_number'],
                    $row['category'],
                    $row['district'],
                    $row['village'],
                    $row['due_date'],
                    $row['returned_at'] ?: '—',
                    $row['item_status'],
                    $row['purpose'],
                ];
            }
        }
        $content = page_head('Peminjam', 'Daftar peminjam yang memiliki riwayat transaksi.');
        $content .= '<section class="panel borrower-period-controls"><h2>Ekspor transaksi peminjam</h2><form method="get" class="filter-bar report-filter"><input type="hidden" name="page" value="borrowers"><label>Dari tanggal<input type="date" name="from" required value="' . h($periodFrom) . '"></label><label>Sampai tanggal<input type="date" name="to" required value="' . h($periodTo) . '"></label><button class="button primary">Tampilkan periode</button>';
        if (!$periodError) {
            $content .= '<a class="button" href="' . h(page_url('borrowers', ['from' => $periodFrom, 'to' => $periodTo, 'export' => 'excel'])) . '">Ekspor Excel</a><button type="button" class="button" onclick="window.print()">Cetak / Simpan PDF</button>';
        }
        $content .= '</form>';
        if ($periodError) {
            $content .= '<div class="notice error">' . h($periodError) . '</div>';
        }
        $content .= '</section><section class="panel borrower-period-report"><h2>Laporan transaksi peminjam</h2><p class="muted">Periode ' . h(date_id($periodFrom)) . ' sampai ' . h(date_id($periodTo)) . ' · ' . count($borrowerReportRows) . ' baris transaksi dokumen.</p>';
        $escapedBorrowerRows = [];
        foreach ($borrowerReportRows as $row) {
            $escapedBorrowerRows[] = array_map('h', $row);
        }
        $content .= table_html($borrowerReportHeaders, $escapedBorrowerRows, 'Tidak ada transaksi peminjam pada periode ini.') . '</section>';
        $content .= '<form method="get" class="filter-bar"><input type="hidden" name="page" value="borrowers"><input type="hidden" name="from" value="' . h($periodFrom) . '"><input type="hidden" name="to" value="' . h($periodTo) . '"><label class="search-field">Cari nama, NIP/ID, atau unit<input name="q" value="' . h($query) . '" placeholder="Ketik kata kunci…"></label><label>Status<select name="status"><option value="aktif"' . ($statusFilter === 'aktif' ? ' selected' : '') . '>Aktif</option><option value="nonaktif"' . ($statusFilter === 'nonaktif' ? ' selected' : '') . '>Nonaktif</option><option value="semua"' . ($statusFilter === 'semua' ? ' selected' : '') . '>Semua</option></select></label><button class="button">Terapkan</button></form><section class="panel borrowers-list-panel">';
        $rows = [];
        foreach ($borrowers as $borrower) {
            $rows[] = [
                '<a href="' . h(page_url('borrower-detail', ['id' => $borrower['id']])) . '"><strong>' . h($borrower['name']) . '</strong></a>',
                h($borrower['employee_id'] ?: '—'),
                h($borrower['unit_name'] ?: '—'),
                h($borrower['phone'] ?: '—'),
                status_badge($borrower['active'] ? 'Tersedia' : 'Nonaktif'),
                '<a class="button small" href="' . h(page_url('borrower-detail', ['id' => $borrower['id']])) . '">Detail</a>',
            ];
        }
        $content .= '<div class="section-title"><h2>Daftar peminjam</h2><span class="muted">' . count($borrowers) . ' hasil</span></div>' . table_html(['Nama','NIP/ID','Unit kerja / seksi','Kontak','Status',''], $rows, 'Belum ada peminjam.') . '</section>';
        break;

    case 'borrower-form':
        $pageTitle = 'Form peminjam';
        $borrowerId = (int) ($_GET['id'] ?? 0);
        if (!$borrowerId) {
            redirect(page_url('borrowers'));
        }
        $stmt = $pdo->prepare('SELECT * FROM borrowers WHERE id=?');
        $stmt->execute([$borrowerId]);
        $borrower = $stmt->fetch() ?: null;
        if (!$borrower) {
            http_response_code(404);
            $content = page_head('Peminjam tidak ditemukan');
            break;
        }
        $units = $pdo->query('SELECT id,name FROM units WHERE active=1 ORDER BY name')->fetchAll();
        $content = page_head('Ubah peminjam', 'Simpan hanya informasi kontak yang diperlukan.');
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<form method="post" class="panel form-panel narrow">' . csrf_field() . '<input type="hidden" name="action" value="save_borrower"><input type="hidden" name="id" value="' . h($borrowerId) . '"><div class="form-grid"><label class="full">Nama lengkap<input name="name" maxlength="160" required value="' . h($borrower['name']) . '"></label><label>NIP/ID (opsional)<input name="employee_id" maxlength="60" value="' . h($borrower['employee_id']) . '"></label><label>Unit kerja / seksi<select name="unit_id"><option value="">Pilih unit</option>';
        foreach ($units as $unit) {
            $content .= '<option value="' . h($unit['id']) . '"' . ((int) $borrower['unit_id'] === (int) $unit['id'] ? ' selected' : '') . '>' . h($unit['name']) . '</option>';
        }
        $content .= '</select></label><label>Nomor kontak<input name="phone" maxlength="40" value="' . h($borrower['phone']) . '"></label><label>Email (opsional)<input type="email" name="email" maxlength="190" value="' . h($borrower['email']) . '"></label></div><div class="form-actions"><button class="button primary">Simpan peminjam</button><a class="button" href="' . h(page_url('borrowers')) . '">Batal</a></div></form>';
        break;

    case 'borrower-detail':
        $pageTitle = 'Detail peminjam';
        $borrowerId = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT b.*,u.name AS unit_name FROM borrowers b LEFT JOIN units u ON u.id=b.unit_id WHERE b.id=?');
        $stmt->execute([$borrowerId]);
        $borrower = $stmt->fetch();
        if (!$borrower) {
            http_response_code(404);
            $content = page_head('Peminjam tidak ditemukan');
            break;
        }
        $pageTitle = $borrower['name'];
        $content = page_head($borrower['name'], 'Riwayat peminjaman dan informasi kontak.', button_link('Ubah', page_url('borrower-form', ['id' => $borrowerId])));
        $historyCount = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE borrower_id=?');
        $historyCount->execute([$borrowerId]);
        $hasHistory = (int) $historyCount->fetchColumn() > 0;
        $content .= '<section class="panel"><dl class="detail-grid"><div><dt>Nama</dt><dd>' . h($borrower['name']) . '</dd></div><div><dt>NIP/ID</dt><dd>' . h($borrower['employee_id'] ?: '—') . '</dd></div><div><dt>Unit kerja</dt><dd>' . h($borrower['unit_name'] ?: '—') . '</dd></div><div><dt>Nomor kontak</dt><dd>' . h($borrower['phone'] ?: '—') . '</dd></div><div><dt>Email</dt><dd>' . h($borrower['email'] ?: '—') . '</dd></div><div><dt>Status</dt><dd>' . status_badge($borrower['active'] ? 'Tersedia' : 'Nonaktif') . '</dd></div></dl><div class="form-actions">' . form_button($borrower['active'] ? 'Nonaktifkan peminjam' : 'Aktifkan peminjam', 'toggle_borrower', ['id' => $borrowerId], $borrower['active'] ? 'button danger' : 'button');
        if (!$hasHistory) {
            $content .= form_button('Hapus peminjam', 'delete_borrower', ['id' => $borrowerId], 'button danger');
        } else {
            $content .= '<span class="muted">Peminjam memiliki riwayat; data hanya dapat dinonaktifkan.</span>';
        }
        $content .= '</div></section><section class="panel"><h2>Riwayat peminjaman</h2>';
        $stmt = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL) AS active_items, STRING_AGG(d.right_number, ', ' ORDER BY d.right_number) AS documents FROM loans l JOIN loan_items x ON x.loan_id=l.id JOIN documents d ON d.id=x.document_id WHERE l.borrower_id=? GROUP BY l.id ORDER BY l.loan_date DESC");
        $stmt->execute([$borrowerId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $loan) {
            $rows[] = [
                h(date_id($loan['loan_date'])),
                '<a href="' . h(page_url('loan-detail', ['id' => $loan['id']])) . '">' . h($loan['documents']) . '</a>',
                h(date_id($loan['due_date'])),
                status_badge(loan_status($loan)),
            ];
        }
        $content .= table_html(['Tanggal pinjam','Dokumen','Tenggat','Status'], $rows, 'Peminjam ini belum memiliki riwayat peminjaman.') . '</section>';
        break;

    case 'loans':
        $pageTitle = 'Peminjaman';
        $filter = (string) ($_GET['filter'] ?? 'Aktif');
        $query = trim((string) ($_GET['q'] ?? ''));
        $where = [];
        $params = [];
        if ($filter === 'Aktif') {
            $where[] = 'EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)';
        } elseif ($filter === 'Selesai') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)';
        } elseif ($filter === 'Terlambat') {
            $where[] = 'l.due_date<CURRENT_DATE AND EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)';
        }
        if ($query !== '') {
            $where[] = '(b.name ILIKE ? OR d.right_number ILIKE ?)';
            array_push($params, "%{$query}%", "%{$query}%");
        }
        $sql = "SELECT l.*, b.name AS borrower_name, (SELECT COUNT(*) FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL) AS active_items, STRING_AGG(d.right_number, ', ' ORDER BY d.right_number) AS documents FROM loans l JOIN borrowers b ON b.id=l.borrower_id JOIN loan_items x ON x.loan_id=l.id JOIN documents d ON d.id=x.document_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY l.id, b.name ORDER BY l.loan_date DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $loans = $stmt->fetchAll();
        $content = page_head('Peminjaman', 'Transaksi aktif, keterlambatan, dan riwayat.', button_link('Peminjaman baru', page_url('loan-form'), 'button primary'));
        $content .= '<form method="get" class="filter-bar"><input type="hidden" name="page" value="loans"><label class="search-field">Cari peminjam atau nomor hak<input name="q" value="' . h($query) . '" placeholder="Ketik kata kunci…"></label><label>Status<select name="filter">';
        foreach (['Aktif','Terlambat','Selesai','Semua'] as $option) {
            $content .= '<option' . ($filter === $option ? ' selected' : '') . '>' . h($option) . '</option>';
        }
        $content .= '</select></label><button class="button">Terapkan</button></form><section class="panel"><div class="section-title"><h2>Daftar transaksi</h2><span class="muted">' . count($loans) . ' hasil</span></div>';
        $rows = [];
        foreach ($loans as $loan) {
            $status = loan_status($loan);
            $rows[] = [
                '<a href="' . h(page_url('loan-detail', ['id' => $loan['id']])) . '"><strong>' . h($loan['borrower_name']) . '</strong></a>',
                h($loan['documents']),
                h(date_id($loan['loan_date'])),
                h(date_id($loan['due_date'])),
                status_badge($status),
                '<a class="button small" href="' . h(page_url((int) $loan['active_items'] > 0 ? 'loan-manage' : 'loan-detail', ['id' => $loan['id']])) . '">' . ((int) $loan['active_items'] > 0 ? 'Kelola' : 'Detail') . '</a>',
            ];
        }
        $content .= table_html(['Peminjam','Dokumen','Tanggal pinjam','Tenggat','Status',''], $rows, 'Belum ada transaksi.') . '</section>';
        break;

    case 'loan-form':
        $pageTitle = 'Peminjaman baru';
        $units = $pdo->query('SELECT id,name FROM units WHERE active=1 ORDER BY name')->fetchAll();
        $documents = $pdo->query("SELECT d.id,d.right_number,d.district,d.village,c.name AS category FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.status='Tersedia' ORDER BY d.district,d.village,d.right_number LIMIT 1000")->fetchAll();
        $duration = (int) setting('default_loan_days', '14');
        $content = page_head('Peminjaman baru', 'Daftarkan peminjam dan pilih satu atau lebih dokumen untuk transaksi ini.');
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<form method="post" class="panel form-panel">' . csrf_field() . '<input type="hidden" name="action" value="create_loan"><div class="form-section"><h2>Data peminjam</h2><p class="muted">Data akan langsung dicatat pada daftar peminjam setelah transaksi berhasil. Jika NIP/ID sudah terdaftar, riwayat transaksi akan digabung ke peminjam tersebut.</p><div class="form-grid"><label class="full">Nama lengkap<input name="name" maxlength="160" required></label><label>NIP/ID (opsional)<input name="employee_id" maxlength="60"></label><label>Unit kerja / seksi<select name="unit_id"><option value="">Pilih unit</option>';
        foreach ($units as $unit) {
            $content .= '<option value="' . h($unit['id']) . '">' . h($unit['name']) . '</option>';
        }
        $content .= '</select></label><label>Nomor kontak<input name="phone" maxlength="40"></label><label>Email (opsional)<input type="email" name="email" maxlength="190"></label><label>Tanggal pinjam<input type="date" name="loan_date" value="' . h(date('Y-m-d')) . '" required></label><label>Tenggat kembali<input type="date" name="due_date" value="' . h(date('Y-m-d', strtotime("+{$duration} days"))) . '" required></label><label class="full">Keperluan<input name="purpose" maxlength="255" required></label><label class="full">Catatan<textarea name="notes" rows="2" maxlength="5000"></textarea></label></div></div><div class="form-section"><h2>Pilih dokumen <small>(' . count($documents) . ' tersedia)</small></h2><label class="search-field">Cari dokumen<input id="document-search" type="search" placeholder="Nomor hak, jenis hak, desa…"></label><div class="document-picker" id="document-picker">';
        foreach ($documents as $document) {
            $searchText = mb_strtolower($document['right_number'] . ' ' . $document['district'] . ' ' . $document['village'] . ' ' . $document['category']);
            $content .= '<label class="document-option" data-search="' . h($searchText) . '"><input type="checkbox" name="document_ids[]" value="' . h($document['id']) . '"><span><strong>' . h($document['right_number']) . ' · ' . h($document['category']) . '</strong><small>' . h($document['district'] . ', ' . $document['village']) . '</small></span><span class="checkmark">Pilih</span></label>';
        }
        if (!$documents) {
            $content .= '<p class="muted">Tidak ada dokumen tersedia. Tambahkan atau aktifkan dokumen terlebih dahulu.</p>';
        }
        $content .= '</div></div><div class="form-actions"><button class="button primary">Simpan peminjaman</button><a class="button" href="' . h(page_url('loans')) . '">Batal</a></div></form>';
        break;

    case 'loan-detail':
    case 'loan-manage':
        $loanId = (int) ($_GET['id'] ?? 0);
        $loan = get_loan($loanId);
        if (!$loan) {
            http_response_code(404);
            $pageTitle = 'Transaksi tidak ditemukan';
            $content = page_head($pageTitle);
            break;
        }
        $pageTitle = 'Transaksi #' . $loanId;
        $status = loan_status($loan);
        $items = active_loan_items($loanId);
        $extensions = $pdo->prepare('SELECT le.*,a.name AS admin_name FROM loan_extensions le JOIN admins a ON a.id=le.admin_id WHERE le.loan_id=? ORDER BY le.created_at DESC');
        $extensions->execute([$loanId]);
        $extensionRows = $extensions->fetchAll();
        $content = page_head('Detail peminjaman', 'Transaksi #' . $loanId . ' · ' . $loan['borrower_name'], button_link('Kembali ke daftar', page_url('loans')));
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<section class="panel"><div class="section-title"><h2>Informasi transaksi</h2>' . status_badge($status) . '</div><dl class="detail-grid"><div><dt>Peminjam</dt><dd><a href="' . h(page_url('borrower-detail', ['id' => $loan['borrower_id']])) . '">' . h($loan['borrower_name']) . '</a></dd></div><div><dt>Unit kerja</dt><dd>' . h($loan['unit_name'] ?: '—') . '</dd></div><div><dt>Admin pencatat</dt><dd>' . h($loan['admin_name']) . '</dd></div><div><dt>Tanggal pinjam</dt><dd>' . h(date_id($loan['loan_date'])) . '</dd></div><div><dt>Tenggat kembali</dt><dd>' . h(date_id($loan['due_date'])) . '</dd></div><div><dt>Keperluan</dt><dd>' . h($loan['purpose']) . '</dd></div><div class="full"><dt>Catatan</dt><dd>' . h($loan['notes'] ?: '—') . '</dd></div></dl></section><section class="panel"><h2>Dokumen dalam transaksi</h2>';
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                '<a href="' . h(page_url('document-detail', ['id' => $item['document_id']])) . '">' . h($item['right_number']) . '</a>',
                h($item['category']),
                h($item['district'] . ', ' . $item['village']),
                h(date_id($item['returned_at'])),
                h($item['return_condition'] ?? '—'),
                $item['returned_at'] ? status_badge($item['return_condition'] === 'Hilang' ? 'Hilang' : 'Selesai') : status_badge($status),
            ];
        }
        $content .= table_html(['Nomor hak','Jenis hak','Lokasi','Tanggal kembali','Kondisi kembali','Status'], $rows, 'Tidak ada item dokumen.') . '</section>';
        if ($page === 'loan-manage' && (int) $loan['active_items'] > 0) {
            $maximum = (int) setting('max_extensions', '2');
            $content .= '<section class="panel"><h2>Catat pengembalian</h2><p class="muted">Pilih satu atau beberapa dokumen yang dikembalikan. Dokumen lain tetap berstatus dipinjam.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="return_items"><input type="hidden" name="loan_id" value="' . h($loanId) . '"><div class="return-list">';
            foreach ($items as $item) {
                if ($item['returned_at']) {
                    continue;
                }
                $itemId = (int) $item['item_id'];
                $content .= '<article class="return-item"><label class="return-check"><input type="checkbox" name="item_ids[]" value="' . h($itemId) . '"><strong>' . h($item['category'] . ' · ' . $item['right_number']) . '</strong></label><div class="form-grid"><label>Tanggal kembali<input type="date" name="return_date[' . h($itemId) . ']" value="' . h(date('Y-m-d')) . '"></label><label>Kondisi saat kembali<select name="return_condition[' . h($itemId) . ']"><option>Baik</option><option>Perlu perbaikan</option><option>Rusak</option><option>Hilang</option></select></label><label class="full">Catatan<input name="return_note[' . h($itemId) . ']" maxlength="1000"></label></div></article>';
            }
            $content .= '</div><button class="button primary">Simpan pengembalian</button></form></section>';
            if ((int) $loan['extension_count'] < $maximum) {
                $minimumDue = date('Y-m-d', strtotime($loan['due_date'] . ' +1 day'));
                $content .= '<section class="panel"><h2>Perpanjang tenggat</h2><p class="muted">Perpanjangan ' . h((int) $loan['extension_count'] + 1) . ' dari ' . h($maximum) . '.</p><form method="post" class="form-grid">' . csrf_field() . '<input type="hidden" name="action" value="extend_loan"><input type="hidden" name="loan_id" value="' . h($loanId) . '"><label>Tenggat baru<input type="date" name="due_date" min="' . h($minimumDue) . '" required></label><label>Alasan perpanjangan<input name="reason" maxlength="255" required></label><div class="full"><button class="button primary">Simpan perpanjangan</button></div></form></section>';
            } else {
                $content .= '<div class="notice warning">Batas perpanjangan ' . h($maximum) . ' kali telah tercapai.</div>';
            }
        }
        if ($extensionRows) {
            $rows = [];
            foreach ($extensionRows as $extension) {
                $rows[] = [h(date_id($extension['old_due_date'])), h(date_id($extension['new_due_date'])), h($extension['reason']), h($extension['admin_name'])];
            }
            $content .= '<section class="panel"><h2>Riwayat perpanjangan</h2>' . table_html(['Tenggat sebelumnya','Tenggat baru','Alasan','Admin'], $rows) . '</section>';
        }
        break;

    case 'reports':
        $pageTitle = 'Laporan';
        $type = (string) ($_GET['type'] ?? 'Ketersediaan BTSU');
        $allowedReports = ['Ketersediaan BTSU','BTSU masih dipinjam','Peminjaman per periode','Peminjaman per dokumen','Peminjaman per peminjam','Peminjaman per unit','Keterlambatan','Dokumen hilang','Aktivitas perubahan'];
        if (!in_array($type, $allowedReports, true)) {
            $type = 'Ketersediaan BTSU';
        }
        $from = trim((string) ($_GET['from'] ?? ''));
        $to = trim((string) ($_GET['to'] ?? ''));
        if ($from !== '' && !valid_date($from)) {
            $from = '';
        }
        if ($to !== '' && !valid_date($to)) {
            $to = '';
        }
        $headers = [];
        $reportRows = [];
        if ($type === 'Aktivitas perubahan') {
            $headers = ['Waktu','Admin','Aktivitas','Detail','Alamat IP'];
            $where = [];
            $params = [];
            if ($from !== '') {
                $where[] = 'DATE(a.created_at)>=?';
                $params[] = $from;
            }
            if ($to !== '') {
                $where[] = 'DATE(a.created_at)<=?';
                $params[] = $to;
            }
            $sql = 'SELECT a.created_at,ad.name,a.action,a.detail,a.ip_address FROM audit_logs a LEFT JOIN admins ad ON ad.id=a.admin_id';
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY a.created_at DESC LIMIT 5000';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $reportRows[] = [$row['created_at'],$row['name'] ?: 'Akun dihapus',$row['action'],$row['detail'],$row['ip_address']];
            }
        } elseif ($type === 'Ketersediaan BTSU') {
            $headers = ['Kecamatan','Desa/Kelurahan','Jenis hak','Nomor hak','Lokasi arsip','Status'];
            $stmt = $pdo->query('SELECT d.*,c.name AS category_name,' . doc_status_sql() . ' AS effective_status FROM documents d JOIN right_categories c ON c.id=d.right_category_id ORDER BY d.district,d.village,d.right_number');
            foreach ($stmt->fetchAll() as $d) {
                $reportRows[] = [$d['district'],$d['village'],$d['category_name'],$d['right_number'],"Lantai {$d['floor']}, Lemari {$d['cabinet']}, Rak {$d['rack']}, Album " . ($d['album'] ?: '—'),$d['effective_status']];
            }
        } elseif ($type === 'Dokumen hilang') {
            $headers = ['Kecamatan','Desa/Kelurahan','Jenis hak','Nomor hak','Lokasi arsip'];
            $stmt = $pdo->query("SELECT d.*,c.name AS category_name FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.status='Hilang' ORDER BY d.district,d.village,d.right_number");
            foreach ($stmt->fetchAll() as $d) {
                $reportRows[] = [$d['district'],$d['village'],$d['category_name'],$d['right_number'],"Lantai {$d['floor']}, Lemari {$d['cabinet']}, Rak {$d['rack']}"];
            }
        } else {
            $where = [];
            $params = [];
            if (in_array($type, ['BTSU masih dipinjam','Keterlambatan'], true)) {
                $where[] = 'li.returned_at IS NULL';
            }
            if ($type === 'Keterlambatan') {
                $where[] = 'l.due_date<CURRENT_DATE';
            }
            if ($from !== '') {
                $where[] = 'l.loan_date>=?';
                $params[] = $from;
            }
            if ($to !== '') {
                $where[] = 'l.loan_date<=?';
                $params[] = $to;
            }
            $sql = 'SELECT l.id,l.loan_date,l.due_date,b.name AS borrower_name,u.name AS unit_name,d.district,d.village,d.right_number,c.name AS category,li.returned_at FROM loans l JOIN borrowers b ON b.id=l.borrower_id LEFT JOIN units u ON u.id=b.unit_id JOIN loan_items li ON li.loan_id=l.id JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id';
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY l.loan_date DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($type === 'BTSU masih dipinjam') {
                $headers = ['Kecamatan','Desa/Kelurahan','Jenis hak','Nomor hak','Nama peminjam','Unit kerja / seksi','Tanggal pinjam'];
                foreach ($stmt->fetchAll() as $r) {
                    $reportRows[] = [$r['district'],$r['village'],$r['category'],$r['right_number'],$r['borrower_name'],$r['unit_name'] ?: '—',$r['loan_date']];
                }
            } elseif ($type === 'Keterlambatan') {
                $headers = ['Nama peminjam','Nomor hak','Tanggal pinjam','Tenggat','Hari terlambat','Unit kerja'];
                foreach ($stmt->fetchAll() as $r) {
                    $reportRows[] = [$r['borrower_name'],$r['right_number'],$r['loan_date'],$r['due_date'],(string) max(0, (int) ((strtotime(date('Y-m-d')) - strtotime($r['due_date'])) / 86400)),$r['unit_name'] ?: '—'];
                }
            } else {
                $headers = ['Tanggal pinjam','Nama peminjam','Nomor hak','Jenis hak','Unit kerja','Tanggal kembali','Status'];
                foreach ($stmt->fetchAll() as $r) {
                    $status = $r['returned_at'] ? 'Selesai' : ($r['due_date'] < date('Y-m-d') ? 'Terlambat' : 'Dipinjam');
                    $reportRows[] = [$r['loan_date'],$r['borrower_name'],$r['right_number'],$r['category'],$r['unit_name'] ?: '—',$r['returned_at'] ?: '—',$status];
                }
            }
        }
        $content = page_head('Laporan', 'Tinjau dan unduh laporan inventaris serta peminjaman.');
        $content .= '<section class="panel"><form method="get" class="filter-bar report-filter"><input type="hidden" name="page" value="reports"><label>Jenis laporan<select name="type">';
        foreach ($allowedReports as $reportType) {
            $content .= '<option' . ($type === $reportType ? ' selected' : '') . '>' . h($reportType) . '</option>';
        }
        $content .= '</select></label><label>Dari tanggal<input type="date" name="from" value="' . h($from) . '"></label><label>Sampai tanggal<input type="date" name="to" value="' . h($to) . '"></label><button class="button primary">Tampilkan</button><a class="button" href="' . h(page_url('reports', ['type' => $type, 'from' => $from, 'to' => $to, 'export' => 'csv'])) . '">Unduh CSV</a><button type="button" class="button" onclick="window.print()">Cetak / PDF</button></form></section><section class="panel"><div class="section-title"><h2>' . h($type) . '</h2><span class="muted">' . count($reportRows) . ' baris</span></div>';
        $escapedRows = [];
        foreach ($reportRows as $row) {
            $escapedRows[] = array_map('h', $row);
        }
        $content .= table_html($headers, $escapedRows, 'Tidak ada data pada laporan ini.') . '</section>';
        break;

    case 'settings':
        $pageTitle = 'Pengaturan';
        $admins = $pdo->query('SELECT id,name,username,role,active,last_login_at FROM admins ORDER BY name')->fetchAll();
        $content = page_head('Pengaturan', 'Kelola akun admin, master data, dan aturan peminjaman.');
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $rows = [];
        foreach ($admins as $admin) {
            $rows[] = [
                h($admin['name']),
                h($admin['username']),
                h($admin['role']),
                status_badge($admin['active'] ? 'Tersedia' : 'Nonaktif'),
                h($admin['last_login_at'] ? date('d M Y, H:i', strtotime($admin['last_login_at'])) : 'Belum pernah'),
                (int) $admin['id'] === id() ? '<span class="muted">Akun Anda</span>' : '<div class="admin-actions">' . form_button($admin['active'] ? 'Nonaktifkan' : 'Aktifkan', 'toggle_admin', ['id' => $admin['id']], 'button small' . ($admin['active'] ? ' danger' : '')) . '<details><summary class="button small">Reset sandi</summary><form method="post" class="reset-form">' . csrf_field() . '<input type="hidden" name="action" value="reset_admin_password"><input type="hidden" name="id" value="' . h($admin['id']) . '"><label>Kata sandi baru<input type="password" name="new_password" minlength="12" required autocomplete="new-password"></label><button class="button primary small">Simpan sandi</button></form></details></div>',
            ];
        }
        $content .= '<section class="panel"><h2>Akun admin</h2><form method="post" class="form-grid admin-form">' . csrf_field() . '<input type="hidden" name="action" value="save_admin"><label>Nama<input name="name" maxlength="120" required></label><label>Username<input name="username" minlength="3" maxlength="60" pattern="[a-zA-Z0-9._-]+" required></label><label>Kata sandi awal<input type="password" name="password" minlength="12" required></label><label>Peran<select name="role"><option>Admin</option><option>Super Admin</option></select></label><div class="full"><button class="button primary">Tambah admin</button></div></form><div class="table-gap">' . table_html(['Nama','Username','Peran','Status','Login terakhir','Aksi'], $rows) . '</div></section>';
        $masterLists = [
            ['Unit kerja / seksi','unit','units', 'unit'],
            ['Kategori jenis hak','category','right_categories', 'category'],
            ['Kecamatan Kota Semarang','district','districts', 'district'],
        ];
        foreach ($masterLists as [$title, $type, $tableName, $typeValue]) {
            $list = $pdo->query("SELECT id,name,active FROM {$tableName} ORDER BY name")->fetchAll();
            $rows = [];
            foreach ($list as $entry) {
                $rows[] = [
                    h($entry['name']),
                    status_badge($entry['active'] ? 'Tersedia' : 'Nonaktif'),
                    $entry['active'] ? form_button('Nonaktifkan', 'deactivate_master', ['master_type' => $typeValue, 'id' => $entry['id']], 'button small danger') : '<span class="muted">—</span>',
                ];
            }
            $content .= '<section class="panel"><div class="section-title"><h2>' . h($title) . '</h2></div><form method="post" class="master-form">' . csrf_field() . '<input type="hidden" name="action" value="add_master"><input type="hidden" name="master_type" value="' . h($type) . '"><label>Tambah ' . h(mb_strtolower($title)) . '<input name="name" maxlength="120" required></label><button class="button">Tambah</button></form>' . table_html(['Nama','Status','Aksi'], $rows) . '</section>';
        }
        $content .= '<section class="panel"><h2>Aturan peminjaman</h2><form method="post" class="form-grid">' . csrf_field() . '<input type="hidden" name="action" value="save_setting"><label>Durasi standar (hari)<input type="number" min="1" max="365" name="default_loan_days" value="' . h(setting('default_loan_days', '14')) . '" required></label><label>Maksimum perpanjangan<input type="number" min="0" max="10" name="max_extensions" value="' . h(setting('max_extensions', '2')) . '" required></label><div class="full"><button class="button primary">Simpan aturan</button></div></form></section>';
        break;

    case 'password':
        $pageTitle = 'Ubah kata sandi';
        $content = page_head('Ubah kata sandi', 'Gunakan minimal 12 karakter untuk kata sandi baru.');
        if ($actionError) {
            $content .= '<div class="notice error">' . h($actionError) . '</div>';
        }
        $content .= '<form method="post" class="panel form-panel narrow">' . csrf_field() . '<input type="hidden" name="action" value="change_password"><div class="stack"><label>Kata sandi saat ini<input type="password" name="current_password" required autocomplete="current-password"></label><label>Kata sandi baru<input type="password" name="new_password" minlength="12" required autocomplete="new-password"></label><label>Ulangi kata sandi baru<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label><button class="button primary">Simpan kata sandi</button></div></form>';
        break;
}

if ($page === 'borrowers' && ($_GET['export'] ?? '') === 'excel') {
    if ($periodError) {
        http_response_code(400);
        exit(h($periodError));
    }
    $filename = 'laporan-peminjam-' . $periodFrom . '-sampai-' . $periodTo . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $appendSpreadsheetRow = static function (array $cells, bool $isHeader = false): string {
        $xml = '<Row>';
        foreach ($cells as $cell) {
            $style = $isHeader ? ' ss:StyleID="Header"' : '';
            $value = htmlspecialchars((string) $cell, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
            $xml .= '<Cell' . $style . '><Data ss:Type="String">' . $value . '</Data></Cell>';
        }
        return $xml . '</Row>';
    };

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<?mso-application progid="Excel.Sheet"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Styles><Style ss:ID="Header"><Font ss:Bold="1"/>'
        . '<Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/></Style></Styles>'
        . '<Worksheet ss:Name="Laporan Peminjam"><Table>'
        . $appendSpreadsheetRow(['Laporan transaksi peminjam'], true)
        . $appendSpreadsheetRow(['Periode', $periodFrom . ' sampai ' . $periodTo])
        . $appendSpreadsheetRow($borrowerReportHeaders, true);
    foreach ($borrowerReportRows as $row) {
        $xml .= $appendSpreadsheetRow($row);
    }
    $xml .= '</Table></Worksheet></Workbook>';
    echo $xml;
    exit;
}

if ($page === 'reports' && ($_GET['export'] ?? '') === 'csv') {
    $filename = 'laporan-btsu-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers, ';');
    foreach ($reportRows as $row) {
        $safeRow = array_map(static function ($cell): string {
            $value = (string) $cell;
            return preg_match('/^[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
        }, $row);
        fputcsv($output, $safeRow, ';');
    }
    fclose($output);
    exit;
}

$navigation = [
    'dashboard' => ['Dashboard', '◫'],
    'documents' => ['Dokumen', '▤'],
    'borrowers' => ['Peminjam', '♙'],
    'loans' => ['Peminjaman', '⇄'],
    'reports' => ['Laporan', '▥'],
];
$inlineErrorPages = ['document-form','document-detail','document-import','borrower-form','loan-form','loan-detail','loan-manage','settings','password'];
$flashMessage = $notice ?: ($actionError && !in_array($page, $inlineErrorPages, true) ? ['message' => $actionError, 'type' => 'error'] : null);
?><!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#1e3a5f"><title><?= h($pageTitle) ?> · BTSU</title><link rel="stylesheet" href="assets/styles.css"></head>
<body class="<?= $page === 'borrowers' ? 'borrowers-page' : '' ?>">
<a class="skip-link" href="#main">Lewati ke konten utama</a>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="<?= h(page_url('dashboard')) ?>"><img class="brand-mark" src="https://www.atrbpn.go.id/favicon.ico" alt="Logo ATR/BPN"><span><strong>BTSU</strong><small>Arsip Pertanahan</small></span></a>
    <p class="nav-caption">MENU UTAMA</p>
    <nav aria-label="Navigasi utama">
      <?php foreach ($navigation as $key => [$label, $icon]): ?>
        <a class="nav-link<?= $page === $key || ($key === 'documents' && str_starts_with($page, 'document-')) || ($key === 'borrowers' && str_starts_with($page, 'borrower-')) || ($key === 'loans' && str_starts_with($page, 'loan-')) ? ' active' : '' ?>" href="<?= h(page_url($key)) ?>"><span class="nav-icon" aria-hidden="true"><?= h($icon) ?></span><?= h($label) ?></a>
      <?php endforeach; ?>
      <?php if (is_super_admin()): ?><a class="nav-link<?= $page === 'settings' ? ' active' : '' ?>" href="<?= h(page_url('settings')) ?>"><span class="nav-icon" aria-hidden="true">⚙</span>Pengaturan</a><?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><span class="avatar"><?= h(mb_strtoupper(mb_substr($currentAdmin['name'], 0, 1))) ?></span><div><strong><?= h($currentAdmin['name']) ?></strong><small><?= h($currentAdmin['role']) ?></small></div></div>
  </aside>
  <div class="app-main">
    <header class="topbar"><div><span class="topbar-label">Tata Kelola Buku Tanah dan Surat Ukur</span><span class="topbar-location">Kota Semarang</span></div><div class="topbar-actions"><a href="<?= h(page_url('password')) ?>">Ubah kata sandi</a><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="button">Keluar</button></form></div></header>
    <main id="main" class="main-content">
      <?php if ($flashMessage): ?><div class="notice <?= h($flashMessage['type']) ?>" role="status"><?= h($flashMessage['message']) ?></div><?php endif; ?>
      <?= $content ?>
      <footer class="footer">BTSU · Sistem Administrasi Dokumen · <?= date('Y') ?></footer>
    </main>
  </div>
</div>
<script>
const villageOptionsByDistrict = <?= json_encode($clientData['villages'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const search = document.querySelector('#document-search');
if (search) search.addEventListener('input', () => {
  const query = search.value.trim().toLocaleLowerCase('id');
  document.querySelectorAll('.document-option').forEach((item) => {
    item.hidden = !item.dataset.search.includes(query);
  });
});
const districtSelect = document.querySelector('#district-select');
const villageSelect = document.querySelector('#village-select');
if (districtSelect && villageSelect) {
  districtSelect.addEventListener('change', () => {
    villageSelect.replaceChildren(new Option('Pilih kelurahan', ''));
    (villageOptionsByDistrict[districtSelect.value] || []).forEach((village) => {
      villageSelect.add(new Option(village, village));
    });
  });
}
</script>
</body></html>
