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
                selectedRole.textContent = userPosition + ' · ' + 
                    (userRole === 'owner' ? 'Owner' : 'Employee');
                
                selectedAccountInfo.style.display = 'block';
                pinPrompt.textContent = 'Enter PIN to continue';
                
                // Reset PIN
                resetPin();
                clearLoginError();
                
                // Update keypad state (should ENABLE buttons because account is selected)
                updateKeypadState();
                
                showToast('info', 'Account Selected', 'Welcome, ' + userName + '! Please enter your PIN.');
            });
        });
        
        // Keyboard input handler
        document.addEventListener('keydown', function(e) {
            // Don't handle keys if typing in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }
            
            // Check if account is selected
            if (!isAccountSelected()) {
                // If no account selected, ignore all key presses SILENTLY
                if (e.key >= '0' && e.key <= '9' || e.key === 'Backspace' || e.key === 'Enter') {
                    e.preventDefault();
                    // NO TOAST - just ignore
                }
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

    // Add window load handler to ensure correct state
    window.addEventListener('load', function() {
        // Update keypad state based on account selection
        updateKeypadState();
    });