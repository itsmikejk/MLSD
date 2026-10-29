
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Confirm the account still exists and is active.
$stmt = $conn->prepare("
    SELECT id, username, full_name, role, is_active
    FROM admin_users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || (int)$user['is_active'] !== 1) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header("Location: login.php?error=account_inactive");
    exit;
}

// Refresh role and profile details from the database.
$_SESSION['admin_username'] = $user['username'];
$_SESSION['admin_name'] = $user['full_name'];
$_SESSION['admin_role'] = $user['role'];

// Helper for pages that only Admins may access.
function isAdmin(): bool
{
    return isset($_SESSION['admin_role'])
        && $_SESSION['admin_role'] === 'admin';
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        http_response_code(403);
        exit('Access denied. Only Admin accounts can access this page.');
    }
}
