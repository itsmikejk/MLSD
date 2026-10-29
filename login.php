
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_start();
}

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$error = '';

if (isset($_GET['error']) && $_GET['error'] === 'account_inactive') {
    $error = 'Your account is inactive. Please contact an administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, username, password_hash, full_name, role, is_active
             FROM admin_users
             WHERE username = ?
             LIMIT 1"
        );

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        $stmt->close();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            if ((int)$admin['is_active'] !== 1) {
                $error = 'Your account is inactive. Please contact an administrator.';
            } else {
                session_regenerate_id(true);

                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_role'] = $admin['role'];

                header("Location: dashboard.php");
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Login | MLSD Sweets and Delights</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #fff1f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            max-width: 420px;
            width: 100%;
            background: white;
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 8px 30px #00000012;
        }

        .brand {
            color: #b83267;
        }
    </style>
</head>

<body>
<div class="login-card">
    <h2 class="text-center brand">MLSD</h2>
    <p class="text-center text-muted">Sweets and Delights</p>

    <h4 class="mb-4 text-center">Administrator Login</h4>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input
                type="text"
                class="form-control"
                id="username"
                name="username"
                autocomplete="username"
                required
            >
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input
                type="password"
                class="form-control"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit" class="btn btn-danger w-100">
            Log In
        </button>
    </form>

    <p class="text-center text-muted mt-4 mb-0">
        Authorized staff only
    </p>
</div>
</body>
</html>
