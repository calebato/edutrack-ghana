<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';

requireTeacher();
$teacher = getCurrentUser();

$pageTitle = 'Violation Reports';
$activeNav = 'violations';

require_once __DIR__ . '/../includes/header.php';

// UPDATE STATUS
if(isset($_POST['update_status'])) {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Your session expired. Please reload the page and try again.');
    }
    $report_id = (int)($_POST['report_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['Pending', 'Reviewing', 'Resolved'], true)) {
        dbQuery(
            'UPDATE violation_reports vr
             JOIN students s ON s.id = vr.student_id
             SET vr.status = ?
             WHERE vr.id = ? AND s.school_id = ?',
            [$status, $report_id, (int)$teacher['school_id']]
        );
    }

    header("Location: violations.php");
    exit();
}

// GET REPORTS
$reports = dbRows("
    SELECT 
        violation_reports.*,
        students.full_name,
        students.class_level
    FROM violation_reports

    JOIN students ON violation_reports.student_id = students.id

    WHERE students.school_id = ?
    ORDER BY violation_reports.created_at DESC
", [(int)$teacher['school_id']]);
?>

<div class="container-fluid">

    <div class="card edu-card">

        <div class="card-body">

            <h3 class="mb-4">
                🚨 Student Violation Reports
            </h3>

            <div class="table-responsive">

                <table class="table table-bordered">

                    <thead>

                        <tr>

                            <th>Student</th>
                            <th>Class</th>
                            <th>Violation</th>
                            <th>Description</th>
                            <th>Evidence</th>
                            <th>Status</th>
                            <th>Date</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach($reports as $row): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars($row['full_name']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['class_level']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['violation_type']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['description']) ?>
                            </td>

                            <td>

                                <?php if($row['evidence'] != ''): ?>

                                    <a
                                        href="../uploads/violations/<?= $row['evidence'] ?>"
                                        target="_blank"
                                        class="btn btn-sm btn-primary"
                                    >
                                        View File
                                    </a>

                                <?php else: ?>

                                    No File

                                <?php endif; ?>

                            </td>

                            <td>

                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">

                                    <input
                                        type="hidden"
                                        name="report_id"
                                        value="<?= $row['id'] ?>"
                                    >

                                    <select
                                        name="status"
                                        class="form-select form-select-sm mb-2"
                                    >

                                        <option
                                            value="Pending"
                                            <?= $row['status'] == 'Pending' ? 'selected' : '' ?>
                                        >
                                            Pending
                                        </option>

                                        <option
                                            value="Reviewing"
                                            <?= $row['status'] == 'Reviewing' ? 'selected' : '' ?>
                                        >
                                            Reviewing
                                        </option>

                                        <option
                                            value="Resolved"
                                            <?= $row['status'] == 'Resolved' ? 'selected' : '' ?>
                                        >
                                            Resolved
                                        </option>

                                    </select>

                                    <button
                                        type="submit"
                                        name="update_status"
                                        class="btn btn-success btn-sm"
                                    >
                                        Update
                                    </button>

                                </form>

                            </td>

                            <td>
                                <?= date('M d, Y', strtotime($row['created_at'])) ?>
                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
