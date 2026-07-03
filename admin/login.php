<?php
session_start();

// Consolidated auth: redirect to central login and preselect admin
header('Location: ../auth/login.php?user_type=admin');
exit;
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Login - EduTrack</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body{
            background:#f4f7fb;
            display:flex;
            justify-content:center;
            align-items:center;
            height:100vh;
        }

        .login-box{
            width:400px;
            background:white;
            padding:40px;
            border-radius:15px;
            box-shadow:0 2px 10px rgba(0,0,0,0.1);
        }

        .login-title{
            text-align:center;
            margin-bottom:30px;
            font-weight:bold;
        }

    </style>

</head>

<body>

<div class="login-box">

    <h2 class="login-title">
        🔐 Admin Login
    </h2>

    <?php if($error != ""): ?>

        <div class="alert alert-danger">
            <?= $error ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <div class="mb-3">

            <label>Email</label>

            <input
                type="email"
                name="email"
                class="form-control"
                required
            >

        </div>

        <div class="mb-3">

            <label>Password</label>

            <input
                type="password"
                name="password"
                class="form-control"
                required
            >

        </div>

        <button
            type="submit"
            name="login"
            class="btn btn-primary w-100"
        >
            Login
        </button>

    </form>

</div>

</body>
</html>