/**
 * pin-reveal.js — an eye button on PIN boxes that shows or hides the digits.
 *
 * Add data-pin-reveal to an <input type="password">; this wraps it and adds the
 * button. Used on the login page (Forgot PIN) and in My profile. Everything goes
 * back to hidden when the modal or collapsed section around it closes, so the next
 * person on a shared till does not find a PIN on show.
 */
(function () {
    'use strict';

    function setRevealed(input, revealed) {
        const button = input.parentElement.querySelector('.pin-reveal-btn');
        input.type = revealed ? 'text' : 'password';
        if (!button) return;
        button.setAttribute('aria-pressed', revealed ? 'true' : 'false');
        button.setAttribute('aria-label', revealed ? 'Hide PIN' : 'Show PIN');
        button.title = revealed ? 'Hide PIN' : 'Show PIN';
        const icon = button.querySelector('i');
        if (icon) icon.className = revealed ? 'fas fa-eye-slash' : 'fas fa-eye';
    }

    function enhance(root) {
        (root || document).querySelectorAll('input[data-pin-reveal]').forEach(function (input) {
            if (input.parentElement.classList.contains('pin-reveal-field')) return;

            const field = document.createElement('span');
            field.className = 'pin-reveal-field';
            input.parentNode.insertBefore(field, input);
            field.appendChild(input);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pin-reveal-btn';
            button.innerHTML = '<i class="fas fa-eye" aria-hidden="true"></i>';
            button.setAttribute('aria-controls', input.id);
            field.appendChild(button);
            setRevealed(input, false);

            button.addEventListener('click', function () {
                setRevealed(input, input.type === 'password');
                input.focus({ preventScroll: true });
            });
        });
    }

    function hideAll(root) {
        (root || document).querySelectorAll('input[data-pin-reveal]').forEach(function (input) {
            setRevealed(input, false);
        });
    }

    // A closing Bootstrap modal (My profile), or a section folding away inside it,
    // hides every PIN inside
    ['hidden.bs.modal', 'hidden.bs.collapse'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            hideAll(event.target);
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(); });
    } else {
        enhance();
    }

    window.PinReveal = { enhance: enhance, hideAll: hideAll };
})();
