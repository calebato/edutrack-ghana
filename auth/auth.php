<?php
/**
 * EduTrack Ghana - Authentication Module
 * auth/auth.php
 */

require_once __DIR__ . '/../config/db.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------
// Session Helpers
// -----------------------------------------------

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function isStudent(): bool {
    return isLoggedIn() && $_SESSION['user_type'] === 'student';
}

function isTeacher(): bool {
    return isLoggedIn() && $_SESSION['user_type'] === 'teacher';
}

function isAdmin(): bool {
    return isLoggedIn() && $_SESSION['user_type'] === 'admin';
}

function requireStudent(): void {
    if (!isStudent()) {
        header('Location: ' . BASE_URL . '/auth/login.php?msg=Please+login+as+a+student');
        exit;
    }
}

function requireTeacher(): void {
    if (!isTeacher()) {
        header('Location: ' . BASE_URL . '/auth/login.php?msg=Please+login+as+a+teacher');
        exit;
    }
}

function requireAdmin(): void {
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . '/auth/login.php?user_type=admin');
        exit;
    }
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php?msg=Please+login+to+continue');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $table = match ($_SESSION['user_type']) {
        'admin' => 'admins',
        'teacher' => 'teachers',
        default => 'students',
    };
    return dbRow("SELECT * FROM $table WHERE id = ?", [$_SESSION['user_id']]);
}

// -----------------------------------------------
// Validation Helpers
// -----------------------------------------------

function validateEmail(string $email): bool {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function validatePassword(string $password): array {
    $errors = [];
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number.';
    return $errors;
}

function validatePhoneNumber(string $phone): bool {
    return preg_match('/^[0-9]{10}$/', $phone) === 1;
}

function teacherDisplayName(string $name): string {
    $name = trim($name);
    if ($name === '') return 'Teacher';
    if (preg_match('/^(Mr\.?|Mrs\.?|Ms\.?|Dr\.?|Madam|Prof\.?)\s+/i', $name)) return $name;
    return 'Mr. ' . $name;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// -----------------------------------------------
// CSRF Protection
// -----------------------------------------------

function generateCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRF(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// -----------------------------------------------
// Student Registration
// -----------------------------------------------

function registerStudent(array $data): array {

    $errors = [];

    $name  = trim($data['full_name'] ?? '');

    $email = strtolower(trim($data['email'] ?? ''));

    $pass  = $data['password'] ?? '';

    $pass2 = $data['password2'] ?? '';

    $level = $data['class_level'] ?? 'JHS1';

    $gender = $data['gender'] ?? 'Male';

    $school = (int)($data['school_id'] ?? 1);
    $schoolConfirmed = !empty($data['school_confirmed']);

    // PARENT INFO

    $parentName  = trim($data['parent_name'] ?? '');

    $parentEmail = trim($data['parent_email'] ?? '');

    $parentPhone = trim($data['parent_phone'] ?? '');
    $parentPhone = $parentPhone === '' ? null : $parentPhone;

    // VALIDATION

    if (empty($name))
        $errors[] = 'Full name is required.';

    if (!validateEmail($email))
        $errors[] = 'Invalid email address.';

    if ($pass !== $pass2)
        $errors[] = 'Passwords do not match.';

    $passErrors = validatePassword($pass);

    $errors = array_merge($errors, $passErrors);

    if (!in_array($level, ['JHS1','JHS2','JHS3']))
        $errors[] = 'Invalid class level.';

    if (!$schoolConfirmed)
        $errors[] = 'Please confirm that you selected the correct school.';

    if (!dbRow('SELECT id FROM schools WHERE id = ?', [$school]))
        $errors[] = 'Please choose a valid school.';

    // OPTIONAL PARENT EMAIL VALIDATION

    if (
        !empty($parentEmail) &&
        !validateEmail($parentEmail)
    ) {

        $errors[] = 'Invalid parent email address.';
    }

    if ($parentPhone !== null && !validatePhoneNumber($parentPhone)) {
        $errors[] = 'Parent phone number must contain exactly 10 digits.';
    }

    // DUPLICATE EMAIL CHECK

    if (empty($errors)) {

        $existing = dbRow(
            "SELECT id FROM students WHERE email = ?",
            [$email]
        );

        if ($existing)
            $errors[] = 'An account with this email already exists.';
    }

    if (!empty($errors)) {

        return [
            'success' => false,
            'errors' => $errors
        ];
    }

    // PASSWORD HASH

    $hash = password_hash(
        $pass,
        PASSWORD_BCRYPT
    );

    // STUDENT ID

    // Generate a collision-resistant public student identifier.
    do {
        $studentId = 'JHS-' . date('Y') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    } while (dbRow('SELECT id FROM students WHERE student_id = ?', [$studentId]));

    // INSERT STUDENT

    $id = dbInsert(
        "INSERT INTO students (
            school_id,
            full_name,
            email,
            password_hash,
            student_id,
            class_level,
            gender,
            parent_name,
            parent_email,
            parent_phone
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $school,
            $name,
            $email,
            $hash,
            $studentId,
            $level,
            $gender,
            $parentName,
            $parentEmail,
            $parentPhone
        ]
    );

    // ACTIVITY LOG

    logActivity(
        $id,
        'student',
        'registered',
        'New student account created'
    );

    return [
        'success' => true,
        'id' => $id
    ];
}
// -----------------------------------------------
// Teacher Registration
// -----------------------------------------------

function registerTeacher(array $data): array {
    $errors = [];

    $name    = trim($data['full_name'] ?? '');
    $email   = strtolower(trim($data['email'] ?? ''));
    $pass    = $data['password'] ?? '';
    $pass2   = $data['password2'] ?? '';
    $subject = trim($data['subject'] ?? 'General');
    $title   = trim($data['teacher_title'] ?? 'Mr.');
    $school  = (int)($data['school_id'] ?? 1);
    $schoolConfirmed = !empty($data['school_confirmed']);

    if (empty($name)) $errors[] = 'Full name is required.';
    if (!in_array($title, ['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Madam'], true)) $errors[] = 'Please choose a valid title.';
    if (!validateEmail($email)) $errors[] = 'Invalid email address.';
    if ($pass !== $pass2) $errors[] = 'Passwords do not match.';
    $passErrors = validatePassword($pass);
    $errors = array_merge($errors, $passErrors);
    if (!$schoolConfirmed) $errors[] = 'Please confirm that you selected the correct school.';
    if (!dbRow('SELECT id FROM schools WHERE id = ?', [$school])) $errors[] = 'Please choose a valid school.';
    if ($subject !== 'General' && !dbRow('SELECT id FROM subjects WHERE name = ?', [$subject])) {
        $errors[] = 'Please choose a valid subject specialization.';
    }

    if (empty($errors)) {
        $existing = dbRow("SELECT id FROM teachers WHERE email = ?", [$email]);
        if ($existing) $errors[] = 'An account with this email already exists.';
    }

    if (!empty($errors)) return ['success' => false, 'errors' => $errors];

    $name = preg_replace('/^(Mr\.?|Mrs\.?|Ms\.?|Dr\.?|Madam|Prof\.?)\s+/i', '', $name);
    $name = $title . ' ' . trim($name);

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    do {
        $staffId = 'TCH-' . date('Y') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    } while (dbRow('SELECT id FROM teachers WHERE staff_id = ?', [$staffId]));

    $id = dbInsert(
        "INSERT INTO teachers (school_id, full_name, email, password_hash, subject, staff_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 0)",
        [$school, $name, $email, $hash, $subject, $staffId]
    );

    logActivity($id, 'teacher', 'registered', 'New teacher account awaiting admin approval');
    return ['success' => true, 'id' => $id];
}

// -----------------------------------------------
// Login
// -----------------------------------------------

function loginUser(string $email, string $password, string $userType): array {

    $email = strtolower(trim($email));

    if ($userType === 'teacher') {

        $table = 'teachers';

    } elseif ($userType === 'admin') {

        $table = 'admins';

    } else {

        $table = 'students';
    }

    // GET USER

    if ($userType === 'admin') {

        $user = dbRow(
            "SELECT * FROM admins WHERE email = ?",
            [$email]
        );

    } else {

        $user = dbRow("SELECT * FROM $table WHERE email = ?", [$email]);
    }

    // PASSWORD CHECK

    if (
        !$user ||
        !password_verify(
            $password,
            $userType === 'admin'
                ? $user['password']
                : $user['password_hash']
        )
    ) {

        return [
            'success' => false,
            'error' => 'Invalid email or password.'
        ];
    }

    if ($userType !== 'admin' && !(int)$user['is_active']) {
        return [
            'success' => false,
            'error' => $userType === 'teacher'
                ? 'Your teacher account is awaiting administrator approval.'
                : 'Your account is currently inactive.'
        ];
    }

    // SESSION

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];

    $_SESSION['user_type'] = $userType;

    $_SESSION['user_name'] = $user['full_name'];

    $_SESSION['user_email'] = $user['email'];

    $_SESSION['must_change_password'] =
        $user['must_change_password'] ?? 0;

    $_SESSION['login_time'] = time();

    // UPDATE LOGIN DETAILS

    if ($userType !== 'admin') {

        dbQuery(
            "UPDATE $table
             SET
                last_login = NOW(),
                login_count = login_count + 1
             WHERE id = ?",
            [$user['id']]
        );
    }

    // LOGIN LOG

    dbInsert(
        "INSERT INTO login_logs (
            user_id,
            user_type,
            login_time,
            ip_address
        )
        VALUES (?, ?, NOW(), ?)",
        [
            $user['id'],
            $userType,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]
    );

    // ACTIVITY LOG

    logActivity(
        $user['id'],
        $userType,
        'login',
        'User logged in'
    );

    // STUDENT STREAK

    if ($userType === 'student') {

        updateStreak($user['id']);
    }

    return [
        'success' => true,
        'user' => $user
    ];
}
// -----------------------------------------------
// Logout
// -----------------------------------------------

function logoutUser(): void {
    if (isLoggedIn()) {
        logActivity($_SESSION['user_id'], $_SESSION['user_type'], 'logout', 'User logged out');
    }
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
}

// -----------------------------------------------
// Activity Log
// -----------------------------------------------

function logActivity(int $userId, string $userType, string $action, string $details = ''): void {
    try {
        dbInsert(
            "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address) VALUES (?, ?, ?, ?, ?)",
            [$userId, $userType, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']
        );
    } catch (Exception $e) {
        // Silent fail - logging shouldn't break the app
    }
}

// -----------------------------------------------
// Streak Management
// -----------------------------------------------

function updateStreak(int $studentId): void {
    $student = dbRow("SELECT last_activity_date, current_streak, longest_streak FROM students WHERE id = ?", [$studentId]);
    if (!$student) return;

    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $lastDate  = $student['last_activity_date'];

    $newStreak = $student['current_streak'];
    if ($lastDate === $yesterday) {
        $newStreak++; // Continue streak
    } elseif ($lastDate !== $today) {
        $newStreak = 1; // Reset streak
    }

    $longest = max($newStreak, $student['longest_streak']);
    dbQuery(
        "UPDATE students SET current_streak = ?, longest_streak = ?, last_activity_date = ? WHERE id = ?",
        [$newStreak, $longest, $today, $studentId]
    );
}
