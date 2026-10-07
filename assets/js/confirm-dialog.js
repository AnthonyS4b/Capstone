/**
 * confirm-dialog.js
 * A Bootstrap modal replacement for window.confirm().
 *
 *   confirmDialog({
 *       title: 'Archive product?',
 *       message: 'Cat food will be moved to the archive.',   // plain text
 *       detail: 'You can restore it from the Archive page.',  // optional, plain text
 *       confirmText: 'Archive',
 *       tone: 'warning',            // 'warning' | 'danger' | 'primary'
 *       icon: 'fa-box-archive'      // optional Font Awesome icon
 *   }).then(ok => { if (ok) doIt(); });
 *
 * Resolves true only when the confirm button is pressed; Cancel, the close
 * button, Escape and clicking outside all resolve false.
 */
(function () {
    // Styles travel with the script, so any page that loads it gets a styled dialog
    if (!document.getElementById('confirmDialogStyles')) {
        const style = document.createElement('style');
        style.id = 'confirmDialogStyles';
        style.textContent = `
.cd-modal .cd-dialog {
    max-width: 400px;
}

/* Opened on top of another modal: dim that one too */
.modal.cd-modal { z-index: 1065; }
.modal-backdrop ~ .modal-backdrop { z-index: 1060; }

.cd-modal .modal-content {
    border: none;
    border-radius: 14px;
    box-shadow: 0 20px 50px rgba(22, 34, 26, 0.25);
    overflow: hidden;
}

.cd-modal .modal-body {
    padding: 24px 24px 8px;
    text-align: center;
}

.cd-modal .cd-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 14px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
}

.cd-modal .cd-icon-warning { background: #fdf2e3; color: #b45309; }
.cd-modal .cd-icon-danger  { background: #fdeceb; color: #b42318; }
.cd-modal .cd-icon-primary { background: #eaf0ea; color: #2c5530; }

.cd-modal .cd-title {
    font-size: 17px;
    font-weight: 650;
    color: #16221a;
    margin: 0 0 6px;
}

.cd-modal .cd-message {
    font-size: 14px;
    color: #3b4a41;
    margin: 0 0 4px;
    overflow-wrap: anywhere;
}

.cd-modal .cd-detail {
    font-size: 13px;
    color: #61706a;
    margin: 0;
}

.cd-modal .modal-footer {
    border: none;
    padding: 16px 24px 22px;
    display: flex;
    gap: 8px;
    flex-wrap: nowrap;
}

.cd-modal .cd-btn {
    flex: 1;
    margin: 0;
    padding: 9px 14px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}

.cd-modal .cd-btn:focus-visible {
    outline: 2px solid #2c5530;
    outline-offset: 2px;
}

.cd-modal .cd-btn-cancel {
    background: #fff;
    border-color: #cfd4cf;
    color: #16221a;
}

.cd-modal .cd-btn-cancel:hover { background: #f6f7f6; }

.cd-modal .cd-btn-warning { background: #8B4513; color: #fff; }
.cd-modal .cd-btn-warning:hover { background: #6b3410; }
.cd-modal .cd-btn-danger { background: #b42318; color: #fff; }
.cd-modal .cd-btn-danger:hover { background: #912018; }
.cd-modal .cd-btn-primary { background: #2c5530; color: #fff; }
.cd-modal .cd-btn-primary:hover { background: #1f3d23; }
`;
        document.head.appendChild(style);
    }

    const TONES = {
        warning: { icon: 'fa-box-archive', btn: 'cd-btn-warning' },
        danger:  { icon: 'fa-trash-alt',   btn: 'cd-btn-danger' },
        primary: { icon: 'fa-question',    btn: 'cd-btn-primary' },
    };

    function text(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    window.confirmDialog = function (opts = {}) {
        const tone = TONES[opts.tone] ? opts.tone : 'primary';
        const icon = opts.icon || TONES[tone].icon;

        // Only one confirm dialog at a time
        document.getElementById('confirmDialogModal')?.remove();

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade cd-modal" id="confirmDialogModal" tabindex="-1" aria-hidden="true"
                 aria-labelledby="confirmDialogTitle" aria-describedby="confirmDialogMessage">
                <div class="modal-dialog modal-dialog-centered cd-dialog">
                    <div class="modal-content">
                        <div class="modal-body">
                            <div class="cd-icon cd-icon-${tone}" aria-hidden="true"><i class="fas ${text(icon)}"></i></div>
                            <h5 class="cd-title" id="confirmDialogTitle">${text(opts.title || 'Are you sure?')}</h5>
                            <p class="cd-message" id="confirmDialogMessage">${text(opts.message || '')}</p>
                            ${opts.detail ? `<p class="cd-detail">${text(opts.detail)}</p>` : ''}
                            ${opts.showInput ? `<textarea id="confirmDialogInput" class="form-control mt-3" placeholder="${text(opts.inputPlaceholder || 'Reason...')}" rows="3" style="font-size: 14px; color: #3b4a41; resize: none;"></textarea>` : ''}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="cd-btn cd-btn-cancel" data-bs-dismiss="modal">${text(opts.cancelText || 'Cancel')}</button>
                            <button type="button" class="cd-btn ${TONES[tone].btn}" data-cd-confirm>${text(opts.confirmText || 'Confirm')}</button>
                        </div>
                    </div>
                </div>
            </div>`);

        const el = document.getElementById('confirmDialogModal');
        const modal = new bootstrap.Modal(el);

        return new Promise(resolve => {
            let confirmed = false;
            let inputValue = '';
            el.querySelector('[data-cd-confirm]').addEventListener('click', () => {
                confirmed = true;
                if (opts.showInput) {
                    inputValue = el.querySelector('#confirmDialogInput').value.trim();
                }
                modal.hide();
            });
            // Resolve after the fade-out so a follow-up modal/toast does not clash
            el.addEventListener('hidden.bs.modal', () => {
                el.remove();
                if (opts.showInput) {
                    resolve(confirmed ? { confirmed: true, value: inputValue } : false);
                } else {
                    resolve(confirmed);
                }
            }, { once: true });
            // Default focus on Cancel for destructive actions, confirm otherwise
            el.addEventListener('shown.bs.modal', () => {
                if (opts.showInput) {
                    el.querySelector('#confirmDialogInput').focus();
                } else {
                    el.querySelector(tone === 'danger' ? '.cd-btn-cancel' : '[data-cd-confirm]').focus();
                }
            }, { once: true });
            modal.show();
        });
    };
})();
