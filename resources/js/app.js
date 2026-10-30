import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#togglePassword').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.querySelector('#password');
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (!confirm(btn.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
});
