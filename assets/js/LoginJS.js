// Toast notification function with 1-second display
    function showToast(type, title, message) {
        // Prevent duplicate toasts
        if (window.toastShowing) {
            return;
        }

        window.toastShowing = true;

        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            console.error('Toast container not found');
            window.toastShowing = false;
            return;
        }

        // Clear any existing toasts
        toastContainer.innerHTML = '';

        // Icon mapping
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const toastId = 'toast-' + Date.now();
        const icon = icons[type] || 'fa-bell';

        // Map type to Bootstrap color classes
        const bgColor = {
            success: 'bg-success',
            error: 'bg-danger',
            warning: 'bg-warning',
            info: 'bg-info'
        }[type] || 'bg-secondary';

        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgColor} border-0 mb-2 show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas ${icon} me-2"></i>
                        <strong>${title}</strong> ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-timer"></div>
            </div>
        `;

        toastContainer.insertAdjacentHTML('beforeend', toastHtml);

        const toastElement = document.getElementById(toastId);

        // Auto remove after 1 second
        setTimeout(function() {
            if (toastElement && toastElement.parentNode) {
                toastElement.remove();
            }
            window.toastShowing = false;
        }, 1000);
    }

    // ========== LOADING ANIMATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Check if there's an error OR if we arrived here from logout
        const hasError = document.body.innerHTML.includes('Login Failed') ||
                        document.querySelector('.bg-danger') !== null;
        const isLoggedOut = new URLSearchParams(window.location.search).get('logged_out') === '1';

        const loadingAnimation = document.getElementById('loading-animation');

        if (hasError || isLoggedOut) {
            if (loadingAnimation) {
                loadingAnimation.style.display = 'none';
            }
            return;
        }

        // Run loading animation only on a fresh page open (not after logout)
        if (loadingAnimation && !loadingAnimation.classList.contains('hidden')) {
            const progressBar = document.getElementById('progress-bar');
            const percentage = document.getElementById('percentage');
            const logoFill = document.querySelector('.logo-fill');
            const startTime = Date.now();
            const minDisplayTime = 2000;

            let progress = 0;
            const interval = setInterval(() => {
                if (progress < 100) {
                    progress += 1;
                    if (progressBar) progressBar.style.width = progress + '%';
                    if (percentage) percentage.textContent = progress + '%';

                    if (logoFill) {
                        logoFill.style.clipPath = `polygon(0 0, ${progress}% 0, ${progress}% 100%, 0 100%)`;
                    }

                    if (progress === 100) {
                        clearInterval(interval);

                        const elapsedTime = Date.now() - startTime;
                        const remainingTime = Math.max(0, minDisplayTime - elapsedTime);

                        setTimeout(() => {
                            if (loadingAnimation) {
                                loadingAnimation.classList.add('hidden');
                            }
                        }, remainingTime);
                    }
                }
            }, 30);
        }
    });

    // ========== PIN ENTRY SYSTEM ==========
    let currentPin = '';
    const maxPinLength = 4;

    // Function to check if an account is selected
    function isAccountSelected() {
        const userId = document.getElementById('user_id').value;
        return userId && userId !== '';
    }

    // Function to enable/disable keypad based on account selection
    function updateKeypadState() {
        const keypadButtons = document.querySelectorAll('.keypad-btn');
        const accountSelected = isAccountSelected();

        keypadButtons.forEach(button => {
            if (accountSelected) {
                // Account is selected - ENABLE buttons
                button.removeAttribute('disabled');
            } else {
                // No account selected - DISABLE buttons
                button.setAttribute('disabled', 'disabled');
            }
        });
    }

    // Update PIN dots
    function updatePinDots() {
        for (let i = 0; i < maxPinLength; i++) {
            const dot = document.getElementById('pinDot' + i);
            if (dot) {
                if (i < currentPin.length) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            }
        }
        if (currentPin.length > 0) {
            document.querySelector('.pin-dots-row')?.classList.remove('is-error');
        }
    }

    // Reset PIN
    function resetPin() {
        currentPin = '';
        updatePinDots();
    }

    // Show a failure without reloading, so the selected account survives
    function showLoginError(message) {
        const box = document.getElementById('loginError');
        if (box) {
            box.textContent = message;
            box.style.display = 'block';
        }
        resetPin();
        setKeypadBusy(false);

        // Restart the shake even when two wrong PINs come in a row
        const dots = document.querySelector('.pin-dots-row');
        if (dots) {
            dots.classList.remove('is-error');
            void dots.offsetWidth;
            dots.classList.add('is-error');
        }
    }

    function clearLoginError() {
        const box = document.getElementById('loginError');
        if (box) {
            box.textContent = '';
            box.style.display = 'none';
        }
    }

    // Block further input while a PIN is in flight so it cannot be sent twice
    let loginInFlight = false;
    function setKeypadBusy(busy) {
        loginInFlight = busy;
        document.querySelectorAll('.keypad-btn').forEach(function (button) {
            if (busy) {
                button.setAttribute('disabled', 'disabled');
            } else if (isAccountSelected()) {
                button.removeAttribute('disabled');
            }
        });
        const prompt = document.getElementById('pinPrompt');
        if (prompt && busy) prompt.textContent = 'Checking PIN...';
    }

    // Handle PIN submission
    function submitPin() {
        if (currentPin.length !== maxPinLength || loginInFlight) return;

        const form   = document.getElementById('loginForm');
        const userId = document.getElementById('user_id').value;
        if (!form || !userId) return;

        document.getElementById('pin').value = currentPin;
        clearLoginError();
        setKeypadBusy(true);

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
            credentials: 'same-origin'
        })
        .then(function (res) {
            return res.json().catch(function () {
                throw new Error('The server returned an unexpected response.');
            });
        })
        .then(function (data) {
            if (data && data.success) {
                const prompt = document.getElementById('pinPrompt');
                if (prompt) prompt.textContent = 'Signing you in...';
                window.location.href = data.redirect || 'dashboard.php';
                return;
            }
            const prompt = document.getElementById('pinPrompt');
            if (prompt) prompt.textContent = 'Enter PIN to continue';
            showLoginError((data && data.message) || 'Could not sign you in.');
        })
        .catch(function (err) {
            const prompt = document.getElementById('pinPrompt');
            if (prompt) prompt.textContent = 'Enter PIN to continue';
            showLoginError(err.message || 'Network error. Please try again.');
        });
    }

    // ========== INITIALIZATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Get all elements
        const accountCards = document.querySelectorAll('.account-card');
        const userIdInput = document.getElementById('user_id');
        const selectedAccountInfo = document.getElementById('selectedAccountInfo');
        const pinPrompt = document.getElementById('pinPrompt');
        const selectedName = document.getElementById('selectedName');
        const selectedRole = document.getElementById('selectedRole');
        const chosenAvatar = document.getElementById('lgChosenAvatar');
        const stepPick = document.getElementById('lgStepPick');
        const stepPin = document.getElementById('lgStepPin');

        // Two steps: pick an account, then enter its PIN
        function showStep(step) {
            if (!stepPick || !stepPin) return;
            stepPick.hidden = step !== 'pick';
            stepPin.hidden = step !== 'pin';
            if (step === 'pin') stepPin.focus({ preventScroll: true });

            // A long account list may have been scrolled; start the new step at its top
            const panel = document.querySelector('.lg-panel');
            if (panel && panel.getBoundingClientRect().top < 0) {
                panel.scrollIntoView({ block: 'start' });
            }
        }

        // Initially update keypad state (should be disabled since no account selected)
        updateKeypadState();

        // Add click handlers to number buttons
        document.querySelectorAll('.key').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // If button is disabled, do nothing (SILENTLY IGNORE)
                if (this.hasAttribute('disabled')) {
                    return;
                }

                // Process PIN entry
                if (currentPin.length < maxPinLength) {
                    const num = this.getAttribute('data-num');
                    if (num !== null) {
                        currentPin += num;
                        updatePinDots();

                        if (currentPin.length === maxPinLength) {
                            submitPin();
                        }
                    }
                }
            });
        });

        // Add click handler to backspace button
        const backspaceBtn = document.getElementById('backspace');
        if (backspaceBtn) {
            backspaceBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // If button is disabled, do nothing (SILENTLY IGNORE)
                if (this.hasAttribute('disabled')) {
                    return;
                }

                currentPin = currentPin.slice(0, -1);
                updatePinDots();
            });
        }

        // Add click handlers to account cards
        accountCards.forEach(card => {
            card.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Remove selected class from all cards
                accountCards.forEach(c => c.classList.remove('selected'));

                // Add selected class to clicked card
                this.classList.add('selected');

                // Get user data
                const userId = this.getAttribute('data-user-id');
                const userName = this.getAttribute('data-user-name');
                const userRole = this.getAttribute('data-user-role');
                const userPosition = this.getAttribute('data-user-position');

                // Set user ID
                userIdInput.value = userId;

                // Update display
                selectedName.textContent = userName;
                selectedRole.textContent = userPosition ||
                    (userRole === 'owner' ? 'Owner' : 'Employee');

                // Same photo (or initials) as the tile that was picked
                const tileAvatar = this.querySelector('.lg-avatar');
                if (chosenAvatar && tileAvatar) {
                    chosenAvatar.innerHTML = tileAvatar.innerHTML;
                    chosenAvatar.classList.toggle('is-owner', userRole === 'owner');
                }

                selectedAccountInfo.style.display = 'block';
                pinPrompt.textContent = 'Enter your 4-digit PIN';

                // Reset PIN
                resetPin();
                clearLoginError();

                // Update keypad state (should ENABLE buttons because account is selected)
                updateKeypadState();

                showStep('pin');
            });
        });

        // Back to the account list
        function backToAccounts() {
            if (loginInFlight) return;
            const chosen = document.querySelector('.account-card.selected');
            accountCards.forEach(c => c.classList.remove('selected'));
            userIdInput.value = '';
            resetPin();
            clearLoginError();
            document.querySelector('.pin-dots-row')?.classList.remove('is-error');
            updateKeypadState();
            showStep('pick');
            if (chosen) chosen.focus({ preventScroll: true });
        }

        document.getElementById('lgBack')?.addEventListener('click', backToAccounts);

        // Keyboard input handler
        document.addEventListener('keydown', function(e) {
            // Don't handle keys if typing in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }

            // No account chosen yet: leave keys alone so Enter/Space pick a focused tile
            if (!isAccountSelected()) {
                return;
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                backToAccounts();
                return;
            }

            // Enter on a focused non-digit button (e.g. "All accounts") activates it
            if (e.key === 'Enter' && e.target.closest('button') && !e.target.closest('.keypad-btn')) {
                return;
            }

            // Account is selected, process key presses
            if (e.key >= '0' && e.key <= '9') {
                e.preventDefault();
                if (currentPin.length < maxPinLength) {
                    currentPin += e.key;
                    updatePinDots();

                    if (currentPin.length === maxPinLength) {
                        submitPin();
                    }
                }
            } else if (e.key === 'Backspace') {
                e.preventDefault();
                currentPin = currentPin.slice(0, -1);
                updatePinDots();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                submitPin();
            }
        });
    });

    // Till clock on the brand panel
    (function () {
        const time = document.getElementById('lgTime');
        const date = document.getElementById('lgDate');
        if (!time || !date) return;
        function tick() {
            const now = new Date();
            time.textContent = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            date.textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
        }
        tick();
        setInterval(tick, 15000);
    })();

    // Add window load handler to ensure correct state
    window.addEventListener('load', function() {
        // Update keypad state based on account selection
        updateKeypadState();
    });