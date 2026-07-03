/**
 * EduTrack Ghana - Main JS
 * assets/js/app.js
 */

// Sidebar toggle
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const main    = document.getElementById('mainContent');
    if (!sidebar) return;
    sidebar.classList.toggle('open');
}

// Close sidebar on outside click (mobile)
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (!sidebar || !toggle) return;
    if (window.innerWidth <= 768 &&
        !sidebar.contains(e.target) &&
        !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
    }
});

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(alert) {
        if (!alert.querySelector('.btn-close')) return;
        setTimeout(function() {
            if (alert && alert.parentNode) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity .5s';
                setTimeout(function() { alert.remove(); }, 500);
            }
        }, 5000);
    });
});

// Animate progress bars on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.factor-fill, .progress-bar').forEach(function(bar) {
        const target = bar.style.width;
        bar.style.width = '0';
        setTimeout(function() {
            bar.style.transition = 'width 0.8s ease';
            bar.style.width = target;
        }, 200);
    });
});

// Confirm before quiz submit
document.addEventListener('DOMContentLoaded', function() {
    var qForm = document.getElementById('quizForm');
    if (qForm) {
        qForm.addEventListener('submit', function(e) {
            // Already confirmed via button onclick
        });
    }
});

// Active nav highlight from URL
document.addEventListener('DOMContentLoaded', function() {
    var path = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(function(link) {
        if (link.getAttribute('href') && path.endsWith(link.getAttribute('href').split('/').pop())) {
            link.classList.add('active');
        }
    });
});

// Score animation on quiz result page
document.addEventListener('DOMContentLoaded', function() {
    var scoreEl = document.querySelector('.result-score');
    if (!scoreEl) return;
    var target = parseInt(scoreEl.textContent);
    var count  = 0;
    var step   = Math.max(1, Math.floor(target / 40));
    var interval = setInterval(function() {
        count = Math.min(count + step, target);
        scoreEl.textContent = count + '%';
        if (count >= target) clearInterval(interval);
    }, 25);
});

// Tooltip initialization
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap !== 'undefined') {
        var tooltipEls = document.querySelectorAll('[title]');
        tooltipEls.forEach(function(el) {
            new bootstrap.Tooltip(el, { trigger: 'hover' });
        });
    }
});
