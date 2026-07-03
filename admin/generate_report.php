<?php
require_once __DIR__ . '/../auth/auth.php';
requireAdmin();

// GET STUDENTS

$students = dbRows('SELECT * FROM students ORDER BY full_name ASC');

$selectedStudent = null;

if(isset($_GET['student_id'])) {

    $studentId = (int)$_GET['student_id'];

    $selectedStudent = dbRow('SELECT * FROM students WHERE id = ?', [$studentId]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Generate Student Report
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body{
            background:#f4f7fb;
        }

        .main{
            padding:30px;
        }

        .report-card{
            background:white;
            padding:30px;
            border-radius:15px;
            box-shadow:0 2px 10px rgba(0,0,0,0.05);
        }

        .report-header{
            border-bottom:2px solid #eee;
            margin-bottom:25px;
            padding-bottom:15px;
        }

        @media print {

            .no-print{
                display:none;
            }

            body{
                background:white;
            }

            .report-card{
                box-shadow:none;
            }
        }

    </style>

</head>

<body>

<div class="main">

    <div class="report-card">

        <!-- SELECT STUDENT -->

        <div class="no-print mb-4">

            <form method="GET">

                <div class="row">

                    <div class="col-md-8">

                        <select
                            name="student_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Student
                            </option>

                            <?php foreach($students as $student): ?>

                                <option
                                    value="<?= $student['id'] ?>"
                                >
                                    <?= htmlspecialchars($student['full_name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-4">

                        <button
                            class="btn btn-primary w-100"
                            type="submit"
                        >
                            Generate Report
                        </button>

                    </div>

                </div>

            </form>

        </div>

        <?php if($selectedStudent): ?>

        <!-- REPORT -->

        <div class="report-header">

            <h2>
                📘 Student Progress Report
            </h2>

            <p class="text-muted">
                EduTrack Ghana
            </p>

        </div>

        <!-- STUDENT INFO -->

        <div class="row mb-4">

            <div class="col-md-6">

                <h5>
                    Student Information
                </h5>

                <p>
                    <strong>Name:</strong>
                    <?= htmlspecialchars($selectedStudent['full_name']) ?>
                </p>

                <p>
                    <strong>Email:</strong>
                    <?= htmlspecialchars($selectedStudent['email']) ?>
                </p>

                <p>
                    <strong>Class:</strong>
                    <?= htmlspecialchars($selectedStudent['class_level']) ?>
                </p>

            </div>

            <div class="col-md-6">

                <h5>
                    Parent Information
                </h5>

                <p>
                    <strong>Parent Name:</strong>
                    <?= htmlspecialchars($selectedStudent['parent_name']) ?>
                </p>

                <p>
                    <strong>Parent Email:</strong>
                    <?= htmlspecialchars($selectedStudent['parent_email']) ?>
                </p>

                <p>
                    <strong>Parent Phone:</strong>
                    <?= htmlspecialchars($selectedStudent['parent_phone']) ?>
                </p>

            </div>

        </div>

        <!-- PERFORMANCE -->

        <div class="mb-4">

            <h5>
                Academic Performance
            </h5>

            <table class="table table-bordered">

                <tr>

                    <th>Total Points</th>

                    <td>
                        ⭐ <?= $selectedStudent['total_points'] ?>
                    </td>

                </tr>

                <tr>

                    <th>Current Streak</th>

                    <td>
                        🔥 <?= $selectedStudent['current_streak'] ?> Days
                    </td>

                </tr>

                <tr>

                    <th>Longest Streak</th>

                    <td>
                        🏆 <?= $selectedStudent['longest_streak'] ?> Days
                    </td>

                </tr>

            </table>

        </div>

        <!-- REMARK -->

        <div class="mb-4">

            <h5>
                Teacher Remark
            </h5>

            <p>

                <?php if($selectedStudent['total_points'] >= 500): ?>

                    Excellent performance. Keep up the hard work!

                <?php elseif($selectedStudent['total_points'] >= 200): ?>

                    Good progress. Student should stay consistent.

                <?php else: ?>

                    Student needs more focus and participation.

                <?php endif; ?>

            </p>

        </div>

        <!-- PRINT -->

        <div class="no-print">

            <button
                onclick="window.print()"
                class="btn btn-success"
            >
                🖨 Print Report
            </button>

        </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
