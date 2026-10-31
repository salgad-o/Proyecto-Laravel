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

document.addEventListener('submit', (e) => {
    const msg = e.target.dataset?.confirm;
    if (msg && !confirm(msg)) {
        e.preventDefault();
    }
});