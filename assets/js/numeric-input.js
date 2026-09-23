/**
 * numeric-input.js
 * Keeps number fields to digits only — no minus sign, "e", "+" or letters,
 * whether typed or pasted.
 *
 *   <input type="text" inputmode="decimal" data-numeric="money">  → 1234.56 (max 2 decimals)
 *   <input type="text" inputmode="numeric" data-numeric="int">    → 1234
 *
 * Works for fields added later too (listeners are delegated on document).
 */
(function () {
    function kind(el) {
        return el && el.dataset ? el.dataset.numeric : null;
    }

    function clean(value, type) {
        let v = String(value).replace(type === 'int' ? /[^0-9]/g : /[^0-9.]/g, '');
        if (type === 'money') {
            const dot = v.indexOf('.');
            if (dot !== -1) {
                v = v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, '').slice(0, 2);
            }
        }
        return v;
    }

    document.addEventListener('keydown', function (e) {
        const type = kind(e.target);
        if (!type) return;
        if (e.ctrlKey || e.metaKey || e.altKey || e.key.length > 1) return; // shortcuts, arrows, Backspace…
        if (/[0-9]/.test(e.key)) return;
        if (type === 'money' && e.key === '.' && !e.target.value.includes('.')) return;
        e.preventDefault();
    }, true);

    // Capture phase: the value is clean before any page handler reads it
    document.addEventListener('input', function (e) {
        const type = kind(e.target);
        if (!type) return;
        const el = e.target;
        const cleaned = clean(el.value, type);
        if (cleaned !== el.value) {
            const pos = Math.max((el.selectionStart || 0) - (el.value.length - cleaned.length), 0);
            el.value = cleaned;
            try { el.setSelectionRange(pos, pos); } catch (err) { /* not focused */ }
        }
    }, true);

    // Exposed for code that sets values programmatically
    window.cleanNumericValue = clean;
})();
