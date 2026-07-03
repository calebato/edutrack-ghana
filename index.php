<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'], $_SESSION['user_type'])) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php');
    exit;
}

try {
    $studentCount = dbRow("SELECT COUNT(*) AS cnt FROM students WHERE is_active = 1");
    $quizCount = dbRow("SELECT COUNT(*) AS cnt FROM quiz_attempts");
    $subjectCount = dbRow("SELECT COUNT(*) AS cnt FROM subjects");
} catch (Exception $e) {
    $studentCount = ['cnt' => 0];
    $quizCount = ['cnt' => 0];
    $subjectCount = ['cnt' => 8];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduTrack Ghana - Learning for JHS Students</title>
    <meta name="description" content="Practise Ghanaian JHS subjects, complete quizzes, and track real learning progress with EduTrack Ghana.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0d0d1a;
            --green: #00c08b;
            --green-dark: #4f3ff0;
            --green-soft: #f0efff;
            --purple-light: #c7c2ff;
            --blue: #00a779;
            --gold: #f5c842;
            --cream: #f7f6ff;
            --line: #e6e3f4;
            --muted: #6b7280;
            --white: #fff;
            --active-hero-button: #0f8f68;
            --active-hero-button-hover: #0a7554;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font-family: 'DM Sans', sans-serif; color: var(--ink); background: var(--white); overflow-x: hidden; }
        h1, h2, h3, h4 { font-family: 'Syne', sans-serif; letter-spacing: 0; }
        a { color: inherit; }
        .container { max-width: 1180px; }

        .et-nav {
            position: fixed; inset: 0 0 auto; z-index: 999; height: 70px;
            display: flex; align-items: center; justify-content: space-between; gap: 24px;
            padding: 0 max(24px, calc((100vw - 1180px) / 2));
            background: transparent; transition: background .25s, box-shadow .25s;
        }
        .et-nav.scrolled { background: rgba(255,255,255,.97); box-shadow: 0 1px 12px rgba(20,33,54,.1); }
        .nav-brand { display: inline-flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; white-space: nowrap; }
        .et-nav.scrolled .nav-brand { color: var(--green-dark); }
        .brand-mark { width: 35px; height: 35px; display: grid; place-items: center; background: transparent; color: var(--white); font-size: 27px; line-height: 1; }
        .et-nav.scrolled .brand-mark { color: var(--green-dark); }
        .nav-brand-name { font-family: 'Syne', sans-serif; font-size: 19px; font-weight: 800; }
        .nav-links { display: flex; align-items: center; gap: 26px; margin-left: auto; }
        .nav-links a { color: rgba(255,255,255,.9); font-size: 14px; font-weight: 600; text-decoration: none; }
        .et-nav.scrolled .nav-links a { color: var(--ink); }
        .nav-links a:hover { text-decoration: underline; text-underline-offset: 5px; }
        .nav-actions { display: flex; align-items: center; gap: 9px; }
        .btn-nav-login, .btn-nav-signup { padding: 9px 18px; border-radius: 6px; font-size: 14px; font-weight: 700; text-decoration: none; transition: background .25s, border-color .25s, transform .2s; }
        .btn-nav-login { color: #fff; border: 1px solid rgba(255,255,255,.7); }
        .et-nav.scrolled .btn-nav-login { color: var(--green-dark); border-color: var(--green); }
        .btn-nav-signup { background: var(--active-hero-button); color: var(--white); border: 1px solid var(--active-hero-button); }
        .btn-nav-signup:hover { background: var(--active-hero-button-hover); border-color: var(--active-hero-button-hover); color: var(--white); transform: translateY(-1px); }

        #heroCarousel { min-height: 700px; height: 100vh; --hero-active-accent: #8de4c2; --hero-active-stats-bg: rgba(8,39,45,.82); }
        #heroCarousel .carousel-inner, #heroCarousel .carousel-item { height: 100%; }
        .carousel-item {
            position: relative;
            background-size: cover;
            background-position: center;
            --hero-text: #f8fffb;
            --hero-muted: rgba(248,255,251,.86);
            --hero-accent: #8de4c2;
            --hero-button: #087f5b;
            --hero-button-hover: #066b4d;
            --hero-stats-bg: rgba(8,39,45,.82);
            --hero-overlay-start: rgba(9,30,38,.88);
            --hero-overlay-mid: rgba(9,30,38,.52);
            --hero-overlay-end: rgba(9,30,38,.22);
        }
        .slide-1 {
            background-image: url('assets/images/hero-students.jpg');
            --hero-accent: #8de4c2;
            --hero-button: #0f8f68;
            --hero-button-hover: #0a7554;
            --hero-stats-bg: rgba(8,54,48,.82);
            --hero-overlay-start: rgba(8,39,45,.88);
            --hero-overlay-mid: rgba(8,39,45,.5);
            --hero-overlay-end: rgba(8,39,45,.18);
        }
        .slide-2 {
            background-image: url('assets/images/hero-quiz.jpg');
            --hero-text: #fffaf0;
            --hero-muted: rgba(255,250,240,.86);
            --hero-accent: #ffd166;
            --hero-button: #6650d9;
            --hero-button-hover: #5641c6;
            --hero-stats-bg: rgba(35,24,78,.82);
            --hero-overlay-start: rgba(28,21,64,.9);
            --hero-overlay-mid: rgba(28,21,64,.53);
            --hero-overlay-end: rgba(28,21,64,.22);
        }
        .slide-3 {
            background-image: url('assets/images/classroom.jpg');
            --hero-text: #f3fbff;
            --hero-muted: rgba(243,251,255,.86);
            --hero-accent: #9ee7ff;
            --hero-button: #0b7285;
            --hero-button-hover: #075d6c;
            --hero-stats-bg: rgba(7,48,68,.82);
            --hero-overlay-start: rgba(8,38,58,.89);
            --hero-overlay-mid: rgba(8,38,58,.52);
            --hero-overlay-end: rgba(8,38,58,.2);
        }
        .slide-4 {
            background-image: url('assets/images/hero-badges.jpg');
            --hero-text: #fff8ed;
            --hero-muted: rgba(255,248,237,.86);
            --hero-accent: #ffcf5a;
            --hero-button: #9a4d12;
            --hero-button-hover: #803f0e;
            --hero-stats-bg: rgba(66,35,14,.82);
            --hero-overlay-start: rgba(48,26,12,.9);
            --hero-overlay-mid: rgba(48,26,12,.52);
            --hero-overlay-end: rgba(48,26,12,.2);
        }
        .carousel-item::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, var(--hero-overlay-start), var(--hero-overlay-mid) 62%, var(--hero-overlay-end)); }
        .slide-body { position: relative; z-index: 2; height: 100%; max-width: 1180px; margin: auto; padding: 120px 24px 145px; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; text-align: left; }
        .slide-eyebrow { display: inline-flex; align-items: center; gap: 8px; color: var(--hero-accent); font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 14px; }
        .slide-eyebrow::before { content: ''; width: 28px; height: 3px; background: var(--hero-accent); }
        .slide-headline { max-width: 680px; margin: 0 0 16px; color: var(--hero-text); font-size: clamp(38px, 5vw, 64px); font-weight: 800; line-height: 1.06; }
        .slide-headline em { color: var(--hero-accent); font-style: normal; }
        .slide-sub { max-width: 520px; margin: 0 0 26px; color: var(--hero-muted); font-size: clamp(15px, 2vw, 18px); line-height: 1.55; }
        .slide-ctas { display: flex; flex-wrap: wrap; gap: 12px; }
        .btn-slide-primary, .btn-slide-outline { display: inline-flex; align-items: center; gap: 9px; min-height: 49px; padding: 12px 24px; border-radius: 6px; font-weight: 700; text-decoration: none; transition: transform .2s, background .2s; }
        .btn-slide-primary { background: var(--hero-button); color: var(--white); }
        .btn-slide-outline { color: var(--hero-text); border: 1px solid rgba(255,255,255,.75); background: rgba(255,255,255,.08); }
        .btn-slide-primary:hover, .btn-slide-outline:hover { transform: translateY(-2px); }
        .btn-slide-primary:hover { background: var(--hero-button-hover); color: var(--white); }
        .btn-slide-outline:hover { background: rgba(255,255,255,.18); }
        .btn-slide-primary svg, .btn-slide-outline svg { width: 18px; height: 18px; }
        .hero-stats-bar { position: absolute; z-index: 3; inset: auto 0 0; min-height: 92px; display: flex; justify-content: center; background: var(--hero-active-stats-bg); border-top: 1px solid rgba(255,255,255,.15); transition: background .45s ease; }
        .hstat { min-width: 190px; padding: 17px 34px; text-align: center; color: #fff; border-right: 1px solid rgba(255,255,255,.13); }
        .hstat:last-child { border-right: 0; }
        .hstat-num { font-family: 'Syne', sans-serif; color: var(--hero-active-accent); font-size: 25px; font-weight: 800; transition: color .45s ease; }
        .hstat-label { margin-top: 2px; font-size: 12px; color: rgba(255,255,255,.72); }
        #heroCarousel .carousel-control-prev, #heroCarousel .carousel-control-next { width: 44px; height: 44px; top: 50%; margin: 0 16px; border: 1px solid rgba(255,255,255,.45); border-radius: 50%; background: rgba(11,31,42,.35); }
        #heroCarousel .carousel-indicators { bottom: 102px; }
        #heroCarousel .carousel-indicators button { width: 28px; height: 3px; border: 0; }
        #heroCarousel .carousel-indicators .active { background-color: var(--hero-active-accent); }

        .content-section { padding: 68px 24px; }
        .section-kicker { margin-bottom: 10px; color: var(--green-dark); font-size: 13px; font-weight: 800; text-transform: uppercase; }
        .section-title { max-width: 720px; margin: 0 0 12px; font-size: clamp(28px, 3.4vw, 40px); font-weight: 800; line-height: 1.14; }
        .section-copy { max-width: 650px; margin: 0; color: var(--muted); font-size: 16px; line-height: 1.65; }
        .section-heading { margin-bottom: 30px; }
        .section-heading.center { display: flex; flex-direction: column; align-items: center; text-align: center; }

        .subject-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border: 1px solid var(--line); border-right: 0; border-bottom: 0; }
        .subject-link { min-height: 132px; padding: 21px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); color: var(--ink); text-decoration: none; transition: background .2s; }
        .subject-link:hover { background: var(--green-soft); }
        .subject-icon { width: 34px; height: 34px; margin-bottom: 13px; color: var(--green-dark); }
        .subject-link h3 { margin: 0 0 7px; font-size: 17px; font-weight: 700; }
        .subject-link span { display: flex; align-items: center; gap: 5px; color: var(--muted); font-size: 13px; }
        .subject-link span svg { width: 14px; }

        .pathways { background: var(--cream); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .pathway-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 40px; max-width: 900px; }
        .pathway { padding-top: 22px; border-top: 4px solid var(--green); }
        .pathway:nth-child(2) { border-color: var(--blue); }
        .pathway-icon { width: 39px; height: 39px; margin-bottom: 15px; color: var(--ink); }
        .pathway h3 { margin: 0 0 8px; font-size: 21px; }
        .pathway p { min-height: 81px; margin: 0 0 16px; color: var(--muted); line-height: 1.7; }
        .text-link { display: inline-flex; align-items: center; gap: 7px; color: var(--green-dark); font-weight: 800; text-decoration: none; }
        .text-link:hover { text-decoration: underline; text-underline-offset: 4px; }
        .text-link svg { width: 17px; }

        .final-cta { padding: 66px 24px; text-align: center; background: #f2f7fa; }
        .final-cta h2 { max-width: 760px; margin: 0 auto 14px; font-size: clamp(31px, 4vw, 48px); }
        .final-cta p { max-width: 620px; margin: 0 auto 28px; color: var(--muted); font-size: 17px; }
        .cta-actions { display: flex; justify-content: center; flex-wrap: wrap; gap: 12px; }
        .cta-primary, .cta-secondary { display: inline-flex; align-items: center; gap: 8px; min-height: 49px; padding: 12px 24px; border-radius: 6px; font-weight: 800; text-decoration: none; }
        .cta-primary { color: #fff; background: var(--green-dark); }
        .cta-secondary { color: var(--green-dark); background: #fff; border: 1px solid var(--green); }
        .cta-actions svg { width: 18px; }

        .et-footer { padding: 28px 24px; background: #111b2a; color: rgba(255,255,255,.72); }
        .footer-inner { max-width: 1180px; margin: auto; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
        .footer-brand { display: flex; align-items: center; gap: 8px; color: #fff; font-family: 'Syne', sans-serif; font-weight: 800; }
        .footer-brand .brand-mark { color: var(--white); }
        .footer-bottom { font-size: 12px; }

        .reveal { opacity: 0; transform: translateY(20px); transition: opacity .55s ease, transform .55s ease; }
        .reveal.visible { opacity: 1; transform: none; }

        @media (max-width: 991px) {
            .nav-links { display: none; }
            .subject-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 767px) {
            .et-nav { height: 64px; padding: 0 16px; }
            .nav-brand-name { font-size: 16px; }
            .btn-nav-login { display: none; }
            .btn-nav-signup { padding: 8px 12px; }
            #heroCarousel { height: 760px; min-height: 760px; }
            .slide-body { padding: 95px 22px 185px; justify-content: center; }
            .slide-headline { font-size: 40px; }
            .slide-sub { font-size: 16px; }
            .slide-ctas { width: 100%; }
            .btn-slide-primary, .btn-slide-outline { width: 100%; justify-content: center; }
            .hero-stats-bar { display: grid; grid-template-columns: 1fr 1fr; }
            .hstat { min-width: 0; padding: 10px 8px; border-bottom: 1px solid rgba(255,255,255,.13); }
            .hstat:nth-child(2) { border-right: 0; }
            .hstat-num { font-size: 20px; }
            #heroCarousel .carousel-indicators { bottom: 151px; }
            #heroCarousel .carousel-control-prev, #heroCarousel .carousel-control-next { display: none; }
            .content-section { padding: 65px 20px; }
            .subject-grid { grid-template-columns: 1fr; }
            .subject-link { min-height: 125px; }
            .pathway-grid { grid-template-columns: 1fr; gap: 42px; }
            .pathway p { min-height: 0; }
            .footer-inner { flex-direction: column; align-items: flex-start; gap: 8px; }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition: none !important; animation: none !important; }
        }
    </style>
</head>
<body>
<nav class="et-nav" id="mainNav" aria-label="Main navigation">
    <a href="#heroCarousel" class="nav-brand" aria-label="EduTrack Ghana home">
        <span class="brand-mark" aria-hidden="true">🎓</span>
        <span class="nav-brand-name">EduTrack Ghana</span>
    </a>
    <div class="nav-actions">
        <a href="auth/login.php" class="btn-nav-login">Log in</a>
        <a href="auth/register.php" class="btn-nav-signup">Sign up</a>
    </div>
</nav>

<div id="heroCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="6500">
    <div class="carousel-indicators">
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active" aria-label="Student learning"></button>
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1" aria-label="Teacher tools"></button>
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2" aria-label="Exam practice"></button>
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="3" aria-label="Learning rewards"></button>
    </div>
    <div class="carousel-inner">
        <div class="carousel-item active slide-1">
            <div class="slide-body">
                <div class="slide-eyebrow">Ghanaian JHS learning</div>
                <h1 class="slide-headline">Learn. Practise. <em>Improve.</em></h1>
                <p class="slide-sub">Build confidence with curriculum topics and focused quizzes.</p>
                <div class="slide-ctas">
                    <a href="auth/register.php" class="btn-slide-primary">Start learning free <i data-lucide="arrow-right"></i></a>
                </div>
            </div>
        </div>
        <div class="carousel-item slide-2">
            <div class="slide-body">
                <div class="slide-eyebrow">For teachers</div>
                <h2 class="slide-headline">See progress. <em>Give support.</em></h2>
                <p class="slide-sub">Follow class results and find where students need help.</p>
                <div class="slide-ctas">
                    <a href="auth/register.php?role=teacher" class="btn-slide-primary">Join as a teacher <i data-lucide="arrow-right"></i></a>
                </div>
            </div>
        </div>
        <div class="carousel-item slide-3">
            <div class="slide-body">
                <div class="slide-eyebrow">BECE preparation</div>
                <h2 class="slide-headline">Prepare with <em>confidence.</em></h2>
                <p class="slide-sub">Practise each topic and use your results to guide revision.</p>
                <div class="slide-ctas">
                    <a href="auth/register.php" class="btn-slide-primary">Begin practising <i data-lucide="arrow-right"></i></a>
                </div>
            </div>
        </div>
        <div class="carousel-item slide-4">
            <div class="slide-body">
                <div class="slide-eyebrow">Stay motivated</div>
                <h2 class="slide-headline">Progress worth <em>celebrating.</em></h2>
                <p class="slide-sub">Earn points and badges as you build a steady learning habit.</p>
                <div class="slide-ctas">
                    <a href="auth/register.php" class="btn-slide-primary">Create an account <i data-lucide="arrow-right"></i></a>
                </div>
            </div>
        </div>
    </div>
    <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev" aria-label="Previous slide"><span class="carousel-control-prev-icon"></span></button>
    <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next" aria-label="Next slide"><span class="carousel-control-next-icon"></span></button>
    <div class="hero-stats-bar">
        <div class="hstat"><div class="hstat-num"><?= number_format((int)$studentCount['cnt']) ?></div><div class="hstat-label">Active students</div></div>
        <div class="hstat"><div class="hstat-num"><?= number_format((int)$quizCount['cnt']) ?></div><div class="hstat-label">Quizzes completed</div></div>
        <div class="hstat"><div class="hstat-num"><?= (int)$subjectCount['cnt'] ?></div><div class="hstat-label">JHS subjects</div></div>
        <div class="hstat"><div class="hstat-num">JHS 1-3</div><div class="hstat-label">Curriculum coverage</div></div>
    </div>
</div>

<main>
    <section class="content-section" id="subjects">
        <div class="container">
            <div class="section-heading center reveal">
                <div class="section-kicker">Explore the curriculum</div>
                <h2 class="section-title">Choose a subject and start building mastery</h2>
                <p class="section-copy">Clear lessons and focused practice across the core Ghanaian JHS curriculum.</p>
            </div>
            <div class="subject-grid reveal">
                <?php
                $subjects = [
                    ['calculator', 'Mathematics'], ['book-open', 'English Language'],
                    ['flask-conical', 'Integrated Science'], ['globe-2', 'Social Studies'],
                    ['monitor', 'ICT'], ['flag', 'French'],
                    ['heart-handshake', 'Religious and Moral Education'], ['message-circle', 'Ghanaian Language'],
                ];
                foreach ($subjects as [$icon, $name]):
                ?>
                    <a class="subject-link" href="auth/register.php">
                        <i class="subject-icon" data-lucide="<?= $icon ?>"></i>
                        <h3><?= htmlspecialchars($name) ?></h3>
                        <span>Start learning <i data-lucide="arrow-right"></i></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="content-section pathways" id="teachers">
        <div class="container">
            <div class="section-heading reveal">
                <div class="section-kicker">Learning works better together</div>
                <h2 class="section-title">Purpose-built for students and teachers</h2>
            </div>
            <div class="pathway-grid">
                <article class="pathway reveal">
                    <i class="pathway-icon" data-lucide="book-open-check"></i>
                    <h3>For students</h3>
                    <p>Learn at your own pace, practise each topic, and use real quiz results to focus your revision.</p>
                    <a class="text-link" href="auth/register.php?role=student">Create a student account <i data-lucide="arrow-right"></i></a>
                </article>
                <article class="pathway reveal">
                    <i class="pathway-icon" data-lucide="presentation"></i>
                    <h3>For teachers</h3>
                    <p>Follow student activity, review class performance, and offer support based on completed work.</p>
                    <a class="text-link" href="auth/register.php?role=teacher">Create a teacher account <i data-lucide="arrow-right"></i></a>
                </article>
            </div>
        </div>
    </section>

    <section class="final-cta">
        <div class="reveal">
            <h2>Start learning today. Keep improving tomorrow.</h2>
            <p>Create a free account and begin practising Ghanaian JHS subjects at your own pace.</p>
            <div class="cta-actions">
                <a href="auth/register.php" class="cta-primary">Start learning free <i data-lucide="arrow-right"></i></a>
                <a href="auth/login.php" class="cta-secondary">Log in to EduTrack</a>
            </div>
        </div>
    </section>
</main>

<footer class="et-footer">
    <div class="footer-inner">
        <div class="footer-brand"><span class="brand-mark" aria-hidden="true">🎓</span> EduTrack Ghana</div>
        <div class="footer-bottom">&copy; <?= date('Y') ?> EduTrack Ghana. Built for teaching and learning.</div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script>
    lucide.createIcons();
    const nav = document.getElementById('mainNav');
    const updateNav = () => nav.classList.toggle('scrolled', window.scrollY > 40);
    window.addEventListener('scroll', updateNav, { passive: true });
    updateNav();

    const heroCarousel = document.getElementById('heroCarousel');
    const updateHeroAccent = () => {
        const activeSlide = heroCarousel.querySelector('.carousel-item.active');
        if (!activeSlide) return;
        const activeStyles = getComputedStyle(activeSlide);
        const accent = activeStyles.getPropertyValue('--hero-accent').trim();
        const statsBg = activeStyles.getPropertyValue('--hero-stats-bg').trim();
        const button = activeStyles.getPropertyValue('--hero-button').trim();
        const buttonHover = activeStyles.getPropertyValue('--hero-button-hover').trim();
        heroCarousel.style.setProperty('--hero-active-accent', accent);
        heroCarousel.style.setProperty('--hero-active-stats-bg', statsBg);
        document.documentElement.style.setProperty('--active-hero-button', button);
        document.documentElement.style.setProperty('--active-hero-button-hover', buttonHover);
    };
    heroCarousel.addEventListener('slid.bs.carousel', updateHeroAccent);
    updateHeroAccent();

    const reveals = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });
        reveals.forEach((element) => observer.observe(element));
    } else {
        reveals.forEach((element) => element.classList.add('visible'));
    }
</script>
</body>
</html>
