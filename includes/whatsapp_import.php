<?php
$whatsappImportProfile = $whatsappImportProfile ?? 'entry';
if (!in_array($whatsappImportProfile, ['entry', 'departure'], true)) {
    throw new InvalidArgumentException('Invalid WhatsApp import profile.');
}
?>
<section class="card border border-success-subtle p-3 mb-4" data-whatsapp-import data-profile="<?= mde_h($whatsappImportProfile) ?>">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="fs-4" aria-hidden="true">💬</span>
        <h2 class="h5 mb-0">الإدخال من رسالة واتساب</h2>
    </div>
    <textarea class="form-control mb-2" id="whatsappMessage" rows="4" aria-label="نص رسالة واتساب" placeholder="الصق رسالة واتساب هنا..."></textarea>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-success" id="whatsappAnalyze">تحليل الرسالة</button>
        <select class="form-select flex-grow-1" style="max-width:360px" id="whatsappRecord" disabled aria-label="السجل المستخرج"><option value="">لا توجد سجلات بعد</option></select>
        <button type="button" class="btn btn-success" id="whatsappApply" disabled>تحميل السجل في النموذج</button>
        <?php if ($whatsappImportProfile === 'departure'): ?><button type="button" class="btn btn-danger" id="whatsappApplyAll" disabled>تحميل جميع سجلات المغادرة</button><?php endif; ?>
    </div>
    <div class="small mt-2" id="whatsappDetails" aria-live="polite"></div>
    <div class="small text-primary mt-1" id="whatsappStatus" aria-live="polite"></div>
</section>
<script src="assets/js/whatsapp-parser.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/whatsapp-parser.js') ?>" defer></script>
<script src="assets/js/whatsapp-import.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/whatsapp-import.js') ?>" defer></script>