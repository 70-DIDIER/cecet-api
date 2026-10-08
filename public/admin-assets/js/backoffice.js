/* Interface commune du back-office : menu latéral, plein écran. */
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar-wrapper');
    const toggleButton = document.querySelector('.sidebar-toggle-btn');

    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    if (toggleButton && sidebar) {
        toggleButton.addEventListener('click', function (event) {
            event.stopPropagation();
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show', sidebar.classList.contains('show'));
        });

        overlay.addEventListener('click', function () {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    const desktopToggle = document.querySelector('#desktop-sidebar-toggle');
    if (desktopToggle) {
        desktopToggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-minimized');

            const icon = desktopToggle.querySelector('i');
            if (icon) {
                icon.className = document.body.classList.contains('sidebar-minimized') ? 'bi bi-chevron-bar-right' : 'bi bi-chevron-bar-left';
            }

            // Les graphiques se redessinent à la nouvelle largeur.
            setTimeout(function () {
                window.dispatchEvent(new Event('resize'));
            }, 300);
        });
    }

    const fullscreenButton = document.querySelector('#btn-fullscreen');
    if (fullscreenButton) {
        const refreshIcon = function () {
            const icon = fullscreenButton.querySelector('i');
            if (icon) {
                icon.className = document.fullscreenElement ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen';
            }
        };

        fullscreenButton.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                document.documentElement.requestFullscreen();
            }
        });

        document.addEventListener('fullscreenchange', refreshIcon);
    }
});
