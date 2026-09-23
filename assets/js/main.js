/* main.js — dependency-free progressive enhancement.
 *
 * Wires the mobile navigation drawer and destructive-form confirmations.
 * Security remains server-side: authorization, CSRF and validation are never
 * delegated to JavaScript.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.getElementById('primary-nav');
        var overlay = document.querySelector('.nav-overlay');

        function setMenu(open) {
            if (!toggle || !nav) {
                return;
            }
            nav.classList.toggle('is-open', open);
            document.body.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (overlay) {
                overlay.hidden = !open;
            }
        }

        if (toggle && nav) {
            toggle.addEventListener('click', function () {
                setMenu(!nav.classList.contains('is-open'));
            });

            if (overlay) {
                overlay.addEventListener('click', function () {
                    setMenu(false);
                    toggle.focus();
                });
            }

            nav.addEventListener('click', function (event) {
                var target = event.target;
                if (target && target.closest && target.closest('a')) {
                    setMenu(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                    setMenu(false);
                    toggle.focus();
                }
            });

            window.addEventListener('resize', function () {
                if (window.matchMedia('(min-width: 721px)').matches) {
                    setMenu(false);
                }
            });
        }

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
