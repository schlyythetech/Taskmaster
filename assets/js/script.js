document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    const togglePasswordBtns = document.querySelectorAll('.toggle-password');
    if (togglePasswordBtns) {
        togglePasswordBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const passwordInput = document.querySelector(this.getAttribute('data-toggle'));
                const icon = this.querySelector('i');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    }

    // Forgot password overlay
    const forgotPasswordLink = document.getElementById('forgot-password-link');
    const forgotPasswordOverlay = document.getElementById('forgot-password-overlay');
    const cancelBtn = document.getElementById('cancel-forgot-password');
    const closeBtn = document.getElementById('close-overlay');
    
    // Ensure overlay is hidden by default
    if (forgotPasswordOverlay) {
        forgotPasswordOverlay.style.display = 'none';
        forgotPasswordOverlay.classList.remove('active');
    }
    
    // Function to open overlay
    function openOverlay() {
        if (forgotPasswordOverlay) {
            // First make sure it's visible
            forgotPasswordOverlay.style.display = 'flex';
            
            // Force a reflow before adding the active class to ensure the transition works
            forgotPasswordOverlay.offsetHeight;
            
            // Add active class after a small delay to trigger animation
            setTimeout(() => {
                forgotPasswordOverlay.classList.add('active');
                
                // Focus on email input after animation completes
                setTimeout(() => {
                    const emailInput = document.getElementById('reset-email');
                    if (emailInput) emailInput.focus();
                }, 300);
            }, 10);
        }
    }
    
    // Function to close overlay
    function closeOverlay() {
        if (forgotPasswordOverlay) {
            forgotPasswordOverlay.classList.remove('active');
            
            // Wait for animation to complete before hiding
            setTimeout(() => {
                forgotPasswordOverlay.style.display = 'none';
                
                // Reset the form when closing
                const form = forgotPasswordOverlay.querySelector('form');
                if (form) {
                    form.reset();
                    form.classList.remove('was-validated');
                }
            }, 300);
        }
    }
    
    // Event listeners for opening/closing overlay
    if (forgotPasswordLink) {
        forgotPasswordLink.addEventListener('click', function(e) {
            e.preventDefault();
            openOverlay();
        });
    }
    
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeOverlay);
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', closeOverlay);
    }

    // Close overlay when clicking outside content
    if (forgotPasswordOverlay) {
        forgotPasswordOverlay.addEventListener('click', function(e) {
            if (e.target === forgotPasswordOverlay) {
                closeOverlay();
            }
        });
    }
    
    // Close overlay with Escape key
    document.addEventListener('keydown', function(e) {
        if (forgotPasswordOverlay && 
            forgotPasswordOverlay.style.display === 'flex' && 
            e.key === 'Escape') {
            closeOverlay();
        }
    });

    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    
    if (forms) {
        Array.from(forms).forEach(form => {
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                
                form.classList.add('was-validated');
                
                // Ensure the forgot password overlay is closed when submitting login form
                if (form.getAttribute('action') && form.getAttribute('action').includes('login.php')) {
                    if (forgotPasswordOverlay) {
                        forgotPasswordOverlay.style.display = 'none';
                        forgotPasswordOverlay.classList.remove('active');
                    }
                }
            }, false);
        });
    }

    // Password match validation for signup
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm-password');
    
    if (confirmPasswordInput && passwordInput) {
        // Check on input in either field
        const validatePasswordMatch = function() {
            if (passwordInput.value !== confirmPasswordInput.value) {
                confirmPasswordInput.setCustomValidity("Passwords don't match");
            } else {
                confirmPasswordInput.setCustomValidity('');
            }
        };
        
        confirmPasswordInput.addEventListener('input', validatePasswordMatch);
        passwordInput.addEventListener('input', validatePasswordMatch);
    }
    
    // Add animation to auth container
    const authContainer = document.querySelector('.auth-container');
    if (authContainer) {
        authContainer.style.opacity = '0';
        authContainer.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            authContainer.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            authContainer.style.opacity = '1';
            authContainer.style.transform = 'translateY(0)';
        }, 100);
    }
}); 