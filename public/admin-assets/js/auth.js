/* Pages de connexion : affichage du mot de passe et validation minimale du formulaire. */
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('toggle-password');
    const input = document.getElementById('password');

    if (toggle && input) {
        toggle.addEventListener('click', function () {
            const visible = input.getAttribute('type') === 'text';
            input.setAttribute('type', visible ? 'password' : 'text');
            toggle.setAttribute('aria-label', visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe');

            const icon = toggle.querySelector('i');
            if (icon) {
                icon.className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
            }
        });
    }

    // Le code à 6 chiffres : on ne garde que les chiffres (collage depuis l'e-mail).
    const code = document.querySelector('input.otp-input');
    if (code) {
        code.addEventListener('input', function () {
            code.value = code.value.replace(/\D/g, '').slice(0, 6);
        });
    }
});
