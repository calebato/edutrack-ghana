# EduTrack Ghana

EduTrack is a PHP and MySQL learning platform for Ghanaian junior high schools. It provides separate student, teacher, and administrator workflows for curriculum delivery, quizzes, progress tracking, communication, and reporting.

## Requirements

- PHP 8.1 or later with PDO MySQL
- MySQL or MariaDB
- Apache (XAMPP is used for local development)
- Python 3.10 or later for offline model retraining; no third-party packages are required

## Local setup

1. Place the project at `C:\xampp\htdocs\edutrack`.
2. Create a database named `edutrack_ghana`.
3. Import `edutrack_ghana.sql`.
4. Apply `database/migrations/20260620_restore_ml.sql` to an existing database.
5. Review database settings in `config/db.php`.
6. Start Apache and MySQL, then open `http://localhost/edutrack`.

Production deployments should use environment-specific credentials, HTTPS, restricted error output, and a supported web-server configuration.

## Application areas

- `student/`: subjects, topics, quizzes, recorded progress, badges, leaderboards, and profiles
- `teacher/`: curriculum management, quiz creation, student performance, announcements, and reports
- `admin/`: account moderation, curriculum approval, communication, violations, and system logs
- `auth/`: registration, login, logout, sessions, password policy, and CSRF protection
- `api/`: authenticated PHP endpoints used by the application interface
- `ml/`: model training, versioned artifacts, real-time inference, and personalized ranking

Runtime code uses PDO through `config/db.php`. `config.php` retains a centralized MySQLi compatibility connection for standalone curriculum seed and localization utilities.

## Developer documentation

Start with `docs/CODEBASE_DATABASE_SECURITY_ML_GUIDE.md` for the full handoff map of code clusters, functions, database tables, security controls, ML components, diagrams, and maintenance rules.

## Learning behavior

Quiz attempts are tied to the authenticated student and store the exact selected question IDs. Adaptive question selection uses recorded topic mastery, earlier mistakes, difficulty, and Bloom level.

After submission, EduTrack records the score, updates topic mastery, awards session points, checks badges, prevents duplicate rewards, and refreshes the learner's ML outputs. Streaks, rewards, and confetti provide the engagement layer.

The exam-performance model is trained offline from chronological learning records and exported as a versioned JSON artifact. A localhost Flask service provides real-time learner profiling, prediction, and recommendation ranking through the authenticated PHP bridge. PHP retains direct artifact inference as an availability fallback. Forecasts require at least three completed quizzes and include confidence, risk, model version, explainable factors, and inference source.

Teacher analytics, printable reports, and parent emails include forecasts only when enough evidence exists. See `ml/README.md` for retraining commands, limitations, and safeguards.

## Validation

Run PHP syntax checks:

```powershell
$files = rg --files -g '*.php' -g '!src/**'
foreach ($file in $files) { C:\xampp\php\php.exe -l $file }
```

Run focused regression checks:

```powershell
C:\xampp\php\php.exe tests\php\run.php
```

The checks cover email, password, phone-number, CSRF, roles, model loading, BECE grade mapping, cold-start prediction safety, bounded inference, recommendations, and active model metadata.

## Repository hygiene

Local environments, caches, and service logs are excluded through `.gitignore`. Database exports must not contain real student, parent, or staff information before they are shared.
