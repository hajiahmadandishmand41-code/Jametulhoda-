/* main.js — dependency-free progressive enhancement.
 * Navigation, form affordances and media fallbacks are optional enhancements;
 * every permission, validation and state change remains server-side.
 */
(function () {
    'use strict';

    function focusable(container) {
        return Array.prototype.slice.call(container.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])'
        )).filter(function (element) {
            return element.getAttribute('aria-hidden') !== 'true' && element.offsetParent !== null;
        });
    }

    function setupDrawer(options) {
        var toggle = document.querySelector(options.toggle);
        var panel = document.querySelector(options.panel);
        var overlay = document.querySelector(options.overlay);
        if (!toggle || !panel) {
            return;
        }

        var lastFocused = null;
        var mediaQuery = window.matchMedia(options.desktopQuery || '(min-width: 721px)');

        function setOpen(open, returnFocus) {
            panel.classList.toggle('is-open', open);
            document.body.classList.toggle(options.bodyClass, open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
            if (overlay) {
                overlay.hidden = !open;
            }
            if (open) {
                lastFocused = document.activeElement;
                var items = focusable(panel);
                if (items.length) {
                    items[0].focus();
                }
            } else if (returnFocus && lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        function syncDesktopState() {
            if (mediaQuery.matches) {
                panel.classList.remove('is-open');
                panel.removeAttribute('aria-hidden');
                document.body.classList.remove(options.bodyClass);
                if (overlay) {
                    overlay.hidden = true;
                }
                toggle.setAttribute('aria-expanded', 'false');
            } else if (!panel.classList.contains('is-open')) {
                panel.setAttribute('aria-hidden', 'true');
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!panel.classList.contains('is-open'), true);
        });

        if (overlay) {
            overlay.addEventListener('click', function () {
                setOpen(false, true);
            });
        }

        panel.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.closest && target.closest('a[href]')) {
                setOpen(false, false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!panel.classList.contains('is-open')) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false, true);
                return;
            }
            if (event.key === 'Tab') {
                var items = focusable(panel);
                if (!items.length) {
                    return;
                }
                var first = items[0];
                var last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        window.addEventListener('resize', syncDesktopState);
        if (mediaQuery.addEventListener) {
            mediaQuery.addEventListener('change', syncDesktopState);
        }
        syncDesktopState();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.documentElement.classList.add('js-ready');
        document.body.classList.remove('no-js');

        setupDrawer({
            toggle: '.nav-toggle',
            panel: '#primary-nav',
            overlay: '.nav-overlay',
            bodyClass: 'nav-open',
            desktopQuery: '(min-width: 721px)'
        });
        setupDrawer({
            toggle: '.admin-nav-toggle',
            panel: '#admin-sidebar',
            overlay: '.admin-sidebar-overlay',
            bodyClass: 'admin-nav-open',
            desktopQuery: '(min-width: 861px)'
        });

        document.querySelectorAll('[data-branding-preview]').forEach(function (input) {
            var image = document.getElementById(input.dataset.brandingPreview);
            var status = document.getElementById(input.dataset.brandingStatus);
            var original = image.src;
            var originalStatus = status.textContent;
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                image.src = original;
                status.textContent = originalStatus;
                input.setCustomValidity('');
                if (!file) return;
                if (!/^image\/(png|jpeg|webp)$/.test(file.type) || file.size > 2 * 1024 * 1024) {
                    input.setCustomValidity('تصویر PNG، JPG یا WEBP تا ۲ مگابایت انتخاب کنید.');
                    input.reportValidity();
                    status.textContent = 'فایل انتخابی مجاز نیست.';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function () {
                    if (input.files[0] !== file) return;
                    image.src = reader.result;
                    status.textContent = 'پیش‌نمایش فایل انتخابی؛ هنوز ذخیره نشده است.';
                };
                reader.readAsDataURL(file); // data: images are permitted by existing CSP
            });
        });

        var contentType = document.querySelector('select[name="content_type"]');
        var eventFields = document.querySelector('.content-extra-event');
        var reportFields = document.querySelector('.content-extra-report');
        function updateContentExtras() {
            if (!contentType) {
                return;
            }
            var isEvent = contentType.value === 'event';
            var isReport = contentType.value === 'report';
            if (eventFields) {
                eventFields.classList.toggle('is-hidden', !isEvent);
                eventFields.hidden = !isEvent;
                eventFields.setAttribute('aria-hidden', isEvent ? 'false' : 'true');
            }
            if (reportFields) {
                reportFields.classList.toggle('is-hidden', !isReport);
                reportFields.hidden = !isReport;
                reportFields.setAttribute('aria-hidden', isReport ? 'false' : 'true');
            }
        }
        if (contentType) {
            contentType.addEventListener('change', updateContentExtras);
            updateContentExtras();
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form && form.getAttribute && form.getAttribute('data-confirm')) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            }
        });

        // A stale upload must not leave a broken image or a collapsed card.
        document.querySelectorAll('img').forEach(function (image) {
            image.addEventListener('error', function () {
                var parent = image.closest('.card-media, .detail-cover, .gallery-item, .media-preview-cell');
                if (parent) {
                    parent.classList.add('image-failed');
                    image.setAttribute('alt', '');
                }
            }, { once: true });
        });
    });
})();
