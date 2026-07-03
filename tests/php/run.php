<?php

ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../ml/ml.php';

$failures = [];
$checks = 0;

function check(bool $condition, string $message): void {
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $message;
}

check(validateEmail('student@example.com'), 'A valid email should be accepted.');
check(!validateEmail('not-an-email'), 'An invalid email should be rejected.');
check(validatePassword('Strong123') === [], 'A compliant password should be accepted.');
check(count(validatePassword('short')) === 3, 'A short lowercase password should fail all rules.');
check(in_array('Password must contain at least one number.', validatePassword('NoNumbers'), true), 'A number is required.');
check(validatePhoneNumber('0241234567'), 'A ten-digit phone number should be accepted.');
check(!validatePhoneNumber('024123456'), 'A nine-digit phone number should be rejected.');
check(!validatePhoneNumber('02412345A7'), 'A phone number containing letters should be rejected.');
check(teacherDisplayName('Caleb Ato') === 'Mr. Caleb Ato', 'Untitled teacher names should receive the default honorific.');
check(teacherDisplayName('Mrs. Abena Mensah') === 'Mrs. Abena Mensah', 'Existing teacher honorifics should be preserved.');

$_SESSION = [];
$token = generateCSRF();
check(strlen($token) === 64, 'The CSRF token should contain 32 random bytes.');
check(validateCSRF($token), 'The generated CSRF token should validate.');
check(!validateCSRF($token . 'x'), 'A modified CSRF token should fail validation.');
check(generateCSRF() === $token, 'The CSRF token should remain stable within a session.');

$mlModel = loadExamPredictionModel();
check($mlModel === null || !empty($mlModel['weights']), 'The exam model should be either genuinely trained or explicitly unavailable.');
check(scoreToBeceGrade(91) === '1' && scoreToBeceGrade(34) === '9', 'Predicted scores should map to BECE grades correctly.');
$newLearnerPrediction = predictStudentExamPerformance(3, false);
check(!$newLearnerPrediction['available'] && $newLearnerPrediction['risk_level'] === 'insufficient_data', 'Learners without enough quizzes must not receive a failing prediction.');
$experiencedLearnerPrediction = predictStudentExamPerformance(8, false);
check(
    !$experiencedLearnerPrediction['available'] || ($experiencedLearnerPrediction['score'] >= 0 && $experiencedLearnerPrediction['score'] <= 100),
    'Any available ML prediction should remain bounded.'
);
$mlRecommendations = generateMLRecommendations(8, 3);
check(count($mlRecommendations) <= 3 && (!$mlRecommendations || isset($mlRecommendations[0]['model_version'])), 'The ML recommender should return a bounded, versioned sequence.');
$defaultGoal = getStudentLearningGoal(999999);
check($defaultGoal['target_mastery'] === 70, 'Students without a saved learning goal should receive the 70 percent default.');
check((int)dbValue('SELECT COUNT(*) FROM ml_model_metadata WHERE is_active=1') >= 1, 'An active ML model should be registered in metadata.');

$_SESSION = ['user_id' => 7, 'user_type' => 'teacher'];
check(isTeacher() && !isStudent() && !isAdmin(), 'Teacher sessions should have only the teacher role.');
$_SESSION['user_type'] = 'admin';
check(isAdmin() && !isTeacher() && !isStudent(), 'Admin sessions should have only the admin role.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    fwrite(STDERR, count($failures) . " of {$checks} checks failed.\n");
    exit(1);
}

echo "PHP checks passed: {$checks}\n";
