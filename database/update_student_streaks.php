<?php
require_once __DIR__ . '/../config/db.php';

$streaks = [
    'ama@gmail.com' => [12, 14, 0],
    'kwame@gmail.com' => [6, 8, 1],
    'efua@gmail.com' => [1, 2, 0],
    'yaw@gmail.com' => [3, 5, 2],
    'akosua@gmail.com' => [7, 9, 1],
    'kofi@gmail.com' => [5, 7, 3],
    'abena@gmail.com' => [10, 12, 0],
    'kojo@gmail.com' => [2, 3, 1],
    'adwoa@gmail.com' => [4, 5, 0],
    'nana@gmail.com' => [6, 6, 2],
    'esi@gmail.com' => [11, 13, 1],
    'kwesi@gmail.com' => [1, 1, 4],
    'afia@gmail.com' => [4, 6, 0],
    'kwaku@gmail.com' => [8, 10, 1],
    'akua@gmail.com' => [5, 8, 2],
];

function atDay(int $daysAgo, int $hour, int $minute): string {
    return (new DateTimeImmutable('today'))->modify("-{$daysAgo} days")->setTime($hour, $minute)->format('Y-m-d H:i:s');
}

foreach ($streaks as $email => [$current, $longest, $lastDaysAgo]) {
    $student = dbRow('SELECT id FROM students WHERE email=?', [$email]);
    if (!$student) continue;
    $id = (int)$student['id'];
    $lastLogin = atDay($lastDaysAgo, 7 + ($id % 8), ($id * 7) % 55);
    dbQuery(
        'UPDATE students SET current_streak=?, longest_streak=?, last_activity_date=DATE(?), last_login=? WHERE id=?',
        [$current, max($current, $longest), $lastLogin, $lastLogin, $id]
    );

    dbQuery("DELETE FROM login_logs WHERE user_type='student' AND user_id=? AND login_time >= DATE_SUB(NOW(), INTERVAL 15 DAY)", [$id]);
    for ($d = $lastDaysAgo + $current - 1; $d >= $lastDaysAgo; $d--) {
        $login = atDay($d, 7 + (($id + $d) % 9), (($id * 5) + $d * 3) % 55);
        dbInsert(
            'INSERT INTO login_logs (user_id,user_type,login_time,logout_time,ip_address,session_duration_minutes,created_at) VALUES (?,?,?,?,?,?,?)',
            [$id, 'student', $login, (new DateTimeImmutable($login))->modify('+' . (18 + ($id + $d) % 24) . ' minutes')->format('Y-m-d H:i:s'), '127.0.0.1', 18 + ($id + $d) % 24, $login]
        );
    }

    dbInsert(
        'INSERT INTO activity_logs (user_id,user_type,action,details,ip_address,created_at) VALUES (?,?,?,?,?,?)',
        [$id, 'student', 'login', 'User logged in', '127.0.0.1', $lastLogin]
    );
}

echo "Student streaks and recent login patterns updated.\n";
