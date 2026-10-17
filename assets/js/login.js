document.addEventListener('DOMContentLoaded', function () {

    // ========================================
    // PASSWORD VISIBILITY TOGGLE
    // ========================================
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    if (passwordInput && togglePassword) {
        const eyeIcon = togglePassword.querySelector('i');

        if (eyeIcon) {
            // Password is hidden initially: show slashed eye
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');

            togglePassword.addEventListener('click', function () {
                const isHidden = passwordInput.type === 'password';

                passwordInput.type = isHidden ? 'text' : 'password';

                // Slash when hidden; normal eye when visible
                eyeIcon.classList.toggle('fa-eye-slash', !isHidden);
                eyeIcon.classList.toggle('fa-eye', isHidden);

                togglePassword.setAttribute(
                    'aria-label',
                    isHidden ? 'Hide password' : 'Show password'
                );
            });
        }
    }

    // ========================================
    // FLOATING ERROR NOTIFICATION
    // ========================================

    const errorMessage = document.getElementById('errorMessage');

    if (errorMessage) {
        // Hide the notification after 3 seconds
        setTimeout(function () {
            errorMessage.classList.add('toast-hide');

            // Remove notification after its exit animation
            setTimeout(function () {
                errorMessage.remove();
            }, 400);
        }, 3000);
    }

});