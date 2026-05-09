<?php
/**
 * NoteVault - API Backend
 * Gestión de notas con PHP puro + archivos JSON
 * Compatible con Nginx + PHP-FPM
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ─── Config ───
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('BCRYPT_COST', 12);

/**
 * CONTROL DE REGISTRO
 * ───────────────────
 * 'open'     → Cualquiera puede crear cuenta (por defecto)
 * 'closed'   → Nadie puede registrarse (ciérralo después de crear tu cuenta)
 * 'invite'   → Solo con código de invitación (cambia INVITE_CODE abajo)
 */
define('REGISTRATION_MODE', 'open');
define('INVITE_CODE', 'cambia-este-codigo-secreto');

/**
 * LÍMITE DE USUARIOS
 * Máximo número de cuentas permitidas (0 = sin límite)
 */
define('MAX_USERS', 0);

// Crear directorio de datos si no existe
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0700, true);
}

// ─── Helpers ───
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function loadJson($file, $default = []) {
    if (!file_exists($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    return $data ?? $default;
}

function saveJson($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function requireAuth() {
    if (empty($_SESSION['user'])) {
        jsonResponse(['error' => 'No autorizado'], 401);
    }
    return $_SESSION['user'];
}

function getUserDir($username) {
    // Sanitizar nombre de usuario para uso en sistema de archivos
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    $dir = DATA_DIR . '/users/' . $safe;
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
}

function generateId() {
    return bin2hex(random_bytes(8));
}

// ─── Error handler global ───
set_error_handler(function($errno, $errstr) {
    jsonResponse(['error' => 'Error interno del servidor'], 500);
});

// ─── Router ───
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── Endpoint de estado (para diagnóstico) ───
if ($action === 'status') {
    jsonResponse([
        'ok' => true,
        'php' => PHP_VERSION,
        'data_writable' => is_writable(DATA_DIR),
        'registration' => REGISTRATION_MODE,
    ]);
}

// ─── AUTH ───
if ($action === 'register' && $method === 'POST') {
    // ── Verificar modo de registro ──
    if (REGISTRATION_MODE === 'closed') {
        jsonResponse(['error' => 'El registro de nuevas cuentas está desactivado'], 403);
    }

    $input = getInput();
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    // ── Verificar código de invitación si aplica ──
    if (REGISTRATION_MODE === 'invite') {
        $code = trim($input['invite_code'] ?? '');
        if ($code !== INVITE_CODE) {
            jsonResponse(['error' => 'Código de invitación inválido'], 403);
        }
    }

    if (strlen($username) < 3 || strlen($username) > 30) {
        jsonResponse(['error' => 'El usuario debe tener entre 3 y 30 caracteres'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        jsonResponse(['error' => 'Usuario solo puede contener letras, números, guiones y guion bajo'], 400);
    }

    $users = loadJson(USERS_FILE, []);

    // ── Verificar límite de usuarios ──
    if (MAX_USERS > 0 && count($users) >= MAX_USERS) {
        jsonResponse(['error' => 'Se alcanzó el límite máximo de usuarios'], 403);
    }

    if (isset($users[$username])) {
        jsonResponse(['error' => 'El usuario ya existe'], 409);
    }

    $users[$username] = [
        'hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
        'created' => date('c'),
    ];
    saveJson(USERS_FILE, $users);

    // Crear estructura inicial
    $userDir = getUserDir($username);
    saveJson($userDir . '/notes.json', []);
    saveJson($userDir . '/folders.json', [
        ['id' => 'inbox', 'name' => 'Inbox', 'icon' => '📥'],
        ['id' => 'personal', 'name' => 'Personal', 'icon' => '🏠'],
        ['id' => 'work', 'name' => 'Trabajo', 'icon' => '💼'],
    ]);

    $_SESSION['user'] = $username;
    session_regenerate_id(true);
    jsonResponse(['ok' => true, 'user' => $username]);
}

if ($action === 'login' && $method === 'POST') {
    $input = getInput();
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    $users = loadJson(USERS_FILE, []);
    if (!isset($users[$username]) || !password_verify($password, $users[$username]['hash'])) {
        // Delay para prevenir fuerza bruta
        usleep(random_int(100000, 300000));
        jsonResponse(['error' => 'Credenciales inválidas'], 401);
    }

    $_SESSION['user'] = $username;
    session_regenerate_id(true);
    jsonResponse(['ok' => true, 'user' => $username]);
}

if ($action === 'logout') {
    session_destroy();
    jsonResponse(['ok' => true]);
}

if ($action === 'session') {
    if (!empty($_SESSION['user'])) {
        jsonResponse(['user' => $_SESSION['user']]);
    }
    jsonResponse(['user' => null]);
}

// ─── NOTES CRUD ───
if ($action === 'notes' && $method === 'GET') {
    $user = requireAuth();
    $notes = loadJson(getUserDir($user) . '/notes.json', []);
    // Ordenar: pinned primero, luego por fecha
    usort($notes, function($a, $b) {
        if (($a['pinned'] ?? false) !== ($b['pinned'] ?? false)) {
            return ($b['pinned'] ?? false) ? 1 : -1;
        }
        return ($b['updatedAt'] ?? 0) - ($a['updatedAt'] ?? 0);
    });
    jsonResponse($notes);
}

if ($action === 'notes' && $method === 'POST') {
    $user = requireAuth();
    $input = getInput();
    $userDir = getUserDir($user);
    $notes = loadJson($userDir . '/notes.json', []);

    $note = [
        'id' => generateId(),
        'title' => $input['title'] ?? 'Sin título',
        'content' => $input['content'] ?? '',
        'folder' => $input['folder'] ?? 'inbox',
        'pinned' => false,
        'createdAt' => time(),
        'updatedAt' => time(),
    ];

    array_unshift($notes, $note);
    saveJson($userDir . '/notes.json', $notes);

    // También guardar como texto plano
    $txtDir = $userDir . '/txt';
    if (!is_dir($txtDir)) mkdir($txtDir, 0700, true);
    file_put_contents($txtDir . '/' . $note['id'] . '.txt', $note['content'], LOCK_EX);

    jsonResponse($note, 201);
}

if ($action === 'notes' && $method === 'PUT') {
    $user = requireAuth();
    $input = getInput();
    $noteId = $input['id'] ?? '';
    $userDir = getUserDir($user);
    $notes = loadJson($userDir . '/notes.json', []);

    $found = false;
    foreach ($notes as &$note) {
        if ($note['id'] === $noteId) {
            if (isset($input['title'])) $note['title'] = $input['title'];
            if (isset($input['content'])) $note['content'] = $input['content'];
            if (isset($input['folder'])) $note['folder'] = $input['folder'];
            if (isset($input['pinned'])) $note['pinned'] = (bool)$input['pinned'];
            $note['updatedAt'] = time();
            $found = true;

            // Actualizar texto plano
            $txtDir = $userDir . '/txt';
            if (!is_dir($txtDir)) mkdir($txtDir, 0700, true);
            file_put_contents($txtDir . '/' . $note['id'] . '.txt', $note['content'], LOCK_EX);
            break;
        }
    }
    unset($note);

    if (!$found) jsonResponse(['error' => 'Nota no encontrada'], 404);

    saveJson($userDir . '/notes.json', $notes);
    jsonResponse(['ok' => true]);
}

if ($action === 'notes' && $method === 'DELETE') {
    $user = requireAuth();
    $noteId = $_GET['id'] ?? '';
    $userDir = getUserDir($user);
    $notes = loadJson($userDir . '/notes.json', []);

    $notes = array_values(array_filter($notes, fn($n) => $n['id'] !== $noteId));
    saveJson($userDir . '/notes.json', $notes);

    // Eliminar texto plano
    $txtFile = $userDir . '/txt/' . preg_replace('/[^a-f0-9]/', '', $noteId) . '.txt';
    if (file_exists($txtFile)) unlink($txtFile);

    jsonResponse(['ok' => true]);
}

// ─── FOLDERS ───
if ($action === 'folders' && $method === 'GET') {
    $user = requireAuth();
    $folders = loadJson(getUserDir($user) . '/folders.json', []);
    jsonResponse($folders);
}

if ($action === 'folders' && $method === 'POST') {
    $user = requireAuth();
    $input = getInput();
    $userDir = getUserDir($user);
    $folders = loadJson($userDir . '/folders.json', []);

    $folder = [
        'id' => generateId(),
        'name' => trim($input['name'] ?? 'Nueva carpeta'),
        'icon' => $input['icon'] ?? '📁',
    ];

    $folders[] = $folder;
    saveJson($userDir . '/folders.json', $folders);
    jsonResponse($folder, 201);
}

if ($action === 'folders' && $method === 'DELETE') {
    $user = requireAuth();
    $folderId = $_GET['id'] ?? '';
    $userDir = getUserDir($user);

    // No permitir eliminar carpetas del sistema
    if (in_array($folderId, ['inbox', 'personal', 'work'])) {
        jsonResponse(['error' => 'No se puede eliminar esta carpeta'], 400);
    }

    $folders = loadJson($userDir . '/folders.json', []);
    $folders = array_values(array_filter($folders, fn($f) => $f['id'] !== $folderId));
    saveJson($userDir . '/folders.json', $folders);

    // Mover notas de esa carpeta a inbox
    $notes = loadJson($userDir . '/notes.json', []);
    foreach ($notes as &$note) {
        if ($note['folder'] === $folderId) $note['folder'] = 'inbox';
    }
    unset($note);
    saveJson($userDir . '/notes.json', $notes);

    jsonResponse(['ok' => true]);
}

// ─── Fallback ───
jsonResponse(['error' => 'Acción no válida'], 400);
