(() => {
'use strict';
document.addEventListener('click', event => {
    const option = event.target.closest('[data-person-dropdown-option]');
    if (!option) return;
    const root = option.closest('[data-person-dropdown]');
    if (!root) return;
    const input = root.querySelector('[data-person-dropdown-value]');
    const toggle = root.querySelector('[data-person-dropdown-toggle]');
    if (!input || !toggle) return;
    input.value = option.dataset.value || '';
    toggle.textContent = option.textContent.trim();
    root.querySelectorAll('[data-person-dropdown-option]').forEach(item => {
        const active = item === option;
        item.classList.toggle('active', active);
        item.setAttribute('aria-checked', active ? 'true' : 'false');
    });
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
});
})();
