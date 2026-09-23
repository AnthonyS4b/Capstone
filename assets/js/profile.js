/**
 * profile.js — "My profile" modal (includes/profile_modal.php)
 * Profile picture: crop to a centred square, resize to 256 px in the
 * browser (the server has no image library), then upload.
 */
(function () {
    'use strict';

    const input   = document.getElementById('pfPhotoInput');
    const removeB = document.getElementById('pfPhotoRemove');
    const status  = document.getElementById('pfPhotoStatus');
    if (!input) return;

    const SIZE = 256;
    const MAX_BYTES = 8 * 1024 * 1024; // original file; the upload itself is ~20–40 KB

    function setStatus(text, isError) {
        if (!status) return;
        status.textContent = text || '';
        status.classList.toggle('is-error', !!isError);
    }

    // Every avatar on the page (sidebar, profile) follows the new picture
    function showAvatar(path) {
        document.querySelectorAll('[data-user-avatar]').forEach(el => {
            if (!el.dataset.initials) el.dataset.initials = el.textContent.trim();
            el.innerHTML = '';
            if (path) {
                const img = document.createElement('img');
                img.src = path + (path.includes('?') ? '&' : '?') + 't=' + Date.now();
                img.alt = '';
                el.appendChild(img);
            } else {
                el.textContent = el.dataset.initials || '';
            }
        });
        if (removeB) removeB.hidden = !path;
        const addLabel = document.querySelector('.pf-photo-actions label.pf-link');
        if (addLabel) addLabel.textContent = path ? 'Change photo' : 'Add a photo';
    }

    function toast(type, title, message) {
        if (typeof showToast === 'function') showToast(type, title, message);
    }

    function csrf() {
        return window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    async function send(form) {
        form.append('csrf_token', csrf());
        const res = await fetch('ajax/upload_avatar.php', {
            method: 'POST',
            body: form,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        let data = {};
        try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
        if (!res.ok || !data.success) throw new Error(data.message || 'Could not update your picture.');
        return data;
    }

    // Centre-crop to a square and scale down
    function squareBlob(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                const side = Math.min(img.naturalWidth, img.naturalHeight);
                const sx = (img.naturalWidth - side) / 2;
                const sy = (img.naturalHeight - side) / 2;
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = SIZE;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff'; // transparent PNGs get a white background
                ctx.fillRect(0, 0, SIZE, SIZE);
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, sx, sy, side, side, 0, 0, SIZE, SIZE);
                URL.revokeObjectURL(url);
                canvas.toBlob(b => b ? resolve(b) : reject(new Error('Could not read that picture.')), 'image/jpeg', 0.9);
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('That file is not a picture we can read.')); };
            img.src = url;
        });
    }

    input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        input.value = ''; // allow picking the same file again later
        if (!file) return;
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            setStatus('Please choose a JPG, PNG or WebP picture.', true);
            return;
        }
        if (file.size > MAX_BYTES) {
            setStatus('That picture is larger than 8 MB.', true);
            return;
        }
        setStatus('Uploading…');
        try {
            const blob = await squareBlob(file);
            const form = new FormData();
            form.append('action', 'upload');
            form.append('avatar', blob, 'avatar.jpg');
            const data = await send(form);
            showAvatar(data.avatar);
            setStatus('');
            toast('success', 'Photo updated', 'Your new profile picture is saved.');
        } catch (err) {
            setStatus(err.message, true);
        }
    });

    removeB?.addEventListener('click', async () => {
        const ok = typeof confirmDialog === 'function'
            ? await confirmDialog({ title: 'Remove your photo?', message: 'Your initials will show instead.', confirmText: 'Remove', tone: 'danger', icon: 'fa-user' })
            : confirm('Remove your profile picture?');
        if (!ok) return;
        setStatus('Removing…');
        try {
            const form = new FormData();
            form.append('action', 'remove');
            await send(form);
            showAvatar(null);
            setStatus('');
            toast('success', 'Photo removed', 'Your initials show instead.');
        } catch (err) {
            setStatus(err.message, true);
        }
    });

    // PIN fields: digits only
    document.querySelectorAll('#userProfileModal .pf-pin').forEach(el => el.addEventListener('input', () => {
        const digits = el.value.replace(/\D/g, '').slice(0, 4);
        if (digits !== el.value) el.value = digits;
    }));
})();
