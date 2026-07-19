// app.js — interactions minimales (aucune dépendance externe).
// Les confirmations de suppression sont gérées via onsubmit dans le HTML.
// Ce fichier reste léger et prêt à accueillir de futures améliorations.
document.addEventListener('DOMContentLoaded', function () {
    // Auto-masquage des messages flash après 5 s.
    document.querySelectorAll('.flash').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 400);
        }, 5000);
    });
});
