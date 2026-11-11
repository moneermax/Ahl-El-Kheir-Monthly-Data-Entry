(() => {
'use strict';
document.addEventListener('click', async event => {
    const button = event.target.closest('[data-personnel-add]');
    if (!button) return;
    const editor = button.closest('[data-personnel-role]');
    const form = button.closest('form');
    const input = editor?.querySelector('[data-personnel-new-input]');
    const select = editor?.querySelector('select[name]');
    const status = editor?.querySelector('[data-personnel-status]');
    const csrf = form?.querySelector('input[name="_csrf"]')?.value || '';
    const name = (input?.value || '').trim();
    const role = editor?.dataset.personnelRole || '';
    if (!editor || !input || !select || !status || !csrf || !name) {
        if (status) status.textContent = 'اكتب الاسم أولاً.';
        return;
    }
    button.disabled = true;
    status.textContent = 'جارٍ حفظ الاسم...';
    try {
        const body = new URLSearchParams({ _csrf: csrf, role, name });
        const response = await fetch('api/save_personnel_option.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body,
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'تعذر حفظ الاسم.');
        let option = [...select.options].find(item => item.value === result.name);
        if (!option) { option = new Option(result.name, result.name); select.add(option); }
        select.value = result.name;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        input.value = '';
        status.textContent = result.duplicate ? 'الاسم موجود بالفعل؛ تم اختياره.' : 'تمت إضافة الاسم وحفظه للقائمة.';
    } catch (error) {
        status.textContent = error.message || 'تعذر حفظ الاسم.';
    } finally {
        button.disabled = false;
    }
});
})();