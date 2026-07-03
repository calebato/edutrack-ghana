<?php
require_once __DIR__ . '/../auth/auth.php';
logoutUser();
header('Location: ' . BASE_URL . '/index.php');
exit;
