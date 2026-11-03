<?php
declare(strict_types=1);

require __DIR__ . '/../includes/reference_options.php';

$expectedEnteredBy = ['أروى عباس', 'ايمان', 'ساره', 'سجود', 'فاطمه', 'لمياء', 'مها', 'مياده', 'ميساء', 'هبه', 'هناء', 'هناء خلف الله'];
$expectedSupervisors = ['ساره', 'فاطمه', 'مها', 'مياده', 'ميساء', 'هبه', 'هناء', 'ح ميساء'];

if ($mdeEnteredByOptions !== $expectedEnteredBy) {
    throw new RuntimeException('The entry operator list differs from the reviewed source list.');
}
if ($mdeSupervisorOptions !== $expectedSupervisors) {
    throw new RuntimeException('The supervisor list differs from the reviewed source list.');
}
if (count($mdeEnteredByOptions) !== count(array_unique($mdeEnteredByOptions)) || count($mdeSupervisorOptions) !== count(array_unique($mdeSupervisorOptions))) {
    throw new RuntimeException('Personnel dropdown options must not contain duplicates.');
}
if (in_array('مياده ساره', $mdeSupervisorOptions, true) || in_array('مها ميساء', $mdeSupervisorOptions, true)) {
    throw new RuntimeException('Combined spreadsheet cell values must not appear as individual supervisors.');
}

echo "Personnel dropdown option tests passed.\\n";
