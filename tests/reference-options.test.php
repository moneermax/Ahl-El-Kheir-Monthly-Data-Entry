<?php
declare(strict_types=1);

if (!function_exists('mde_h')) {
    function mde_h(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

require __DIR__ . '/../includes/reference_options.php';

$expectedEnteredBy = ['أروى عباس', 'ايمان', 'ساره', 'سجود', 'فاطمه', 'لمياء', 'مها', 'مياده', 'ميساء', 'هبه', 'هناء'];
$expectedSupervisors = ['ساره', 'فاطمه', 'مها', 'مياده', 'ميساء', 'هبه', 'هناء'];

if ($mdeEnteredByOptions !== $expectedEnteredBy) {
    throw new RuntimeException('The entry operator list differs from the reviewed source list.');
}
if ($mdeSupervisorOptions !== $expectedSupervisors) {
    throw new RuntimeException('The supervisor list differs from the reviewed source list.');
}
if (count($mdeEnteredByOptions) !== count(array_unique($mdeEnteredByOptions)) || count($mdeSupervisorOptions) !== count(array_unique($mdeSupervisorOptions))) {
    throw new RuntimeException('Personnel dropdown options must not contain duplicates.');
}
if (mde_person_option_key('ح ميساء') !== mde_person_option_key('ميساء') || in_array('ح ميساء', $mdeSupervisorOptions, true)) {
    throw new RuntimeException('A standalone role prefix must not create a duplicate person option.');
}
if (mde_person_option_key('هناء خلف الله') !== mde_person_option_key('هناء')) {
    throw new RuntimeException('The confirmed full-name alias must resolve to the canonical هناء option.');
}
if (mde_person_option_key('هبه') !== mde_person_option_key('هبه خلف الله')) {
    throw new RuntimeException('The confirmed هبه خلف الله alias must resolve to the canonical هبه option.');
}
if (in_array('مياده ساره', $mdeSupervisorOptions, true) || in_array('مها ميساء', $mdeSupervisorOptions, true)) {
    throw new RuntimeException('Combined spreadsheet cell values must not appear as individual supervisors.');
}

ob_start();
mde_person_dropdown('enteredBy', 'entered_by', $mdeEnteredByOptions, 'بواسطة', '', true);
$nativePicker = ob_get_clean();
if (!str_contains($nativePicker, '<select') || !str_contains($nativePicker, 'data-person-dropdown-value') || str_contains($nativePicker, 'data-bs-toggle="dropdown"')) {
    throw new RuntimeException('The entry-register personnel picker must use a native select to escape table overflow clipping.');
}
echo "Personnel dropdown option tests passed.\\n";
