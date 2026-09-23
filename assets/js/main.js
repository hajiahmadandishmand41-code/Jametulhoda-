/* main.js — Phase 5 + Phase 6
 *
 * Minimal, dependency-free progressive enhancement. The site is fully usable
 * without JavaScript; this only wires up the mobile navigation toggle and a
 * confirmation prompt for destructive admin forms (data-confirm). Server-side
 * checks (role, CSRF) stay the real guard either way.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.getElementById('primary-nav');
        if (!toggle || !nav) {
            return;
        }

        toggle.addEventListener('click', function () {
            var isOpen = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close the menu with the Escape key for keyboard users.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });

        // Destructive forms ask before submitting (delete actions).
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form && form.getAttribute && form.getAttribute('data-confirm')) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            }
        });
    });
})();
