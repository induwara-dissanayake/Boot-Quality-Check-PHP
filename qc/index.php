<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

if (isset($_SESSION['qcc_id'])) {
    header('Location: view_data.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $efc_no = $_POST['efc_no'] ?? '';

    // Verify QCC credentials
    $stmt = $pdo->prepare("SELECT id FROM qcc WHERE username = ? AND password = ?");
    $stmt->execute([$username, $password]);
    $qcc = $stmt->fetch();

    if ($qcc) {
        // Then verify EFC number
        $stmt = $pdo->prepare("SELECT id FROM users WHERE EFC_no = ?");
        $stmt->execute([$efc_no]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['qcc_id'] = $qcc['id'];
            header('Location: dashboard.php');
            exit();
        } else {
            $error = 'Invalid EFC number';
        }
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boots QC - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
        }
        .brand-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="login-container">
                    <div class="text-center mb-4">
                        <div class="brand-logo">
                            <i class="fas fa-boot"></i>
                        </div>
                        <h3>Boots QC</h3>
                        <p class="text-muted">Quality Control Management System</p>
                    </div>
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="username" name="username" placeholder="QCC Username" required>
                            <label for="username"><i class="fas fa-user me-2"></i>QCC Username</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="password" name="password" placeholder="QCC Password" required>
                            <label for="password"><i class="fas fa-lock me-2"></i>QCC Password</label>
                        </div>
                        <div class="form-floating mb-4">
                            <input type="text" class="form-control" id="efc_no" name="efc_no" placeholder="EFC Number" required>
                            <label for="efc_no"><i class="fas fa-id-card me-2"></i>EFC Number</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mb-3">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                        <div class="text-center">
                            <a href="admin_login.php" class="text-decoration-none">
                                <i class="fas fa-user-shield me-1"></i>Admin Login
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 