<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/../includes/log_helper.php';

requireStudent();

$pageTitle = 'Report Violation';
$activeNav = 'report_violation';

require_once __DIR__ . '/../includes/header.php';

$message = '';
$error = '';
$studentId = $_SESSION['user_id'];

// SUBMIT REPORT
if(isset($_POST['submit_report'])) {

    $violation_type = trim($_POST['violation_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

    $allowedViolationTypes = ['Bullying', 'Cheating', 'Harassment', 'Cyberbullying', 'Violence', 'Abusive Language', 'Discrimination', 'Other'];
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (!in_array($violation_type, $allowedViolationTypes, true) || !$description) {
        $error = 'Violation type and description are required.';
    } else {
        $evidence = '';

        // FILE UPLOAD VALIDATION
        if(isset($_FILES['evidence']) && $_FILES['evidence']['name'] != '') {

            $file = $_FILES['evidence'];
            $fileName = $file['name'];
            $fileSize = $file['size'];
            $fileTmpName = $file['tmp_name'];
            $fileError = $file['error'];
            $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
            $mimeType = $fileError === UPLOAD_ERR_OK
                ? (new finfo(FILEINFO_MIME_TYPE))->file($fileTmpName)
                : '';

            // Allowed image types only
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // Validate file type
            if (!in_array($fileExt, $allowed, true) || !isset($allowedMimeTypes[$mimeType])) {
                $error = '❌ Only JPG, PNG, GIF images are allowed!';
            }
            // Validate file size (5MB max)
            elseif ($fileSize > 5 * 1024 * 1024) {
                $error = '❌ File size must be less than 5MB!';
            }
            // Validate no upload errors
            elseif ($fileError !== 0) {
                $error = '❌ Error uploading file. Please try again.';
            }
            else {
                // Upload is valid - save file
                $uploadDir = __DIR__ . '/../uploads/violations/';

                if(!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0750, true);
                }

                $newFileName = bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
                $targetFile = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpName, $targetFile)) {
                    $evidence = $newFileName;
                } else {
                    $error = '❌ Failed to save file. Please try again.';
                }
            }
        }

        // If no error, insert report
        if ($error === '') {
            dbInsert(
                'INSERT INTO violation_reports (student_id, violation_type, description, evidence, is_anonymous)
                 VALUES (?, ?, ?, ?, ?)',
                [$studentId, $violation_type, $description, $evidence, $is_anonymous]
            );

            addLog(null, 'Student ID: ' . $studentId, 'Submitted violation report: ' . $violation_type . ' (' . ($is_anonymous ? 'Anonymous' : 'Named') . ')');

            $message = '✅ Violation report submitted successfully.';
        }
    }
}
?>

<div class="container-fluid">

    <div class="card edu-card">

        <div class="card-body">

            <h3 class="mb-4">🚨 Report a Violation</h3>

            <?php if($message != ''): ?>

                <div class="alert alert-success">
                    <?= $message ?>
                </div>

            <?php endif; ?>

            <?php if($error != ''): ?>

                <div class="alert alert-danger">
                    <?= $error ?>
                </div>

            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">

                <div class="mb-3">

                    <label class="form-label fw-600">
                        Violation Type
                    </label>

                    <select
                        name="violation_type"
                        class="form-control"
                        required
                    >

                        <option value="">
                            Select Violation
                        </option>

                        <option>Bullying</option>
                        <option>Cheating</option>
                        <option>Harassment</option>
                        <option>Cyberbullying</option>
                        <option>Violence</option>
                        <option>Abusive Language</option>
                        <option>Discrimination</option>
                        <option>Other</option>

                    </select>

                </div>

                <div class="mb-3">

                    <label class="form-label fw-600">
                        Description
                    </label>

                    <textarea
                        name="description"
                        class="form-control"
                        rows="6"
                        placeholder="Explain what happened..."
                        required
                    ></textarea>

                </div>

                <div class="mb-4">

                    <label class="form-label fw-600">
                        Upload Evidence (Optional)
                    </label>

                    <input
                        type="file"
                        name="evidence"
                        class="form-control"
                        accept=".jpg,.jpeg,.png,.gif"
                    >

                    <small class="text-muted">📷 Allowed: JPG, PNG, GIF images only (Max 5MB)</small>

                </div>

                <div class="mb-4">

                    <div class="form-check">

                        <input
                            type="checkbox"
                            name="is_anonymous"
                            class="form-check-input"
                            id="isAnonymous"
                        >

                        <label class="form-check-label" for="isAnonymous">
                            🔒 Report Anonymously (Hide my name from admin)
                        </label>

                    </div>

                    <small class="text-muted d-block mt-2">
                        If checked, your name will be hidden and the report will show as anonymous.
                    </small>

                </div>

                <button
                    type="submit"
                    name="submit_report"
                    class="btn btn-danger btn-edu"
                >
                    🚨 Submit Report
                </button>

            </form>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
