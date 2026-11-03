<?php
declare(strict_types=1);

// Names transcribed from the October reference CSV, plus confirmed additions.
// The same person may legitimately appear in both lists.
$mdeEnteredByOptions = ['أروى عباس', 'ايمان', 'ساره', 'سجود', 'فاطمه', 'لمياء', 'مها', 'مياده', 'ميساء', 'هبه', 'هناء', 'هناء خلف الله'];
$mdeSupervisorOptions = ['ساره', 'فاطمه', 'مها', 'مياده', 'ميساء', 'هبه', 'هناء', 'ح ميساء'];

/** Render a Bootstrap dropdown backed by a hidden form value. */
function mde_person_dropdown(string $id, string $name, array $options, string $placeholder = 'اختر الاسم', string $selected = ''): void
{
    $selectedLabel = $selected !== '' && in_array($selected, $options, true) ? $selected : $placeholder;
    echo '<div class="dropdown mde-person-dropdown" data-person-dropdown>';
    echo '<input type="hidden" id="' . mde_h($id) . '" name="' . mde_h($name) . '" value="' . mde_h($selected) . '" data-person-dropdown-value>';
    echo '<button type="button" class="form-select text-start" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false" aria-haspopup="menu" data-person-dropdown-toggle>' . mde_h($selectedLabel) . '</button>';
    echo '<ul class="dropdown-menu w-100" role="menu">';
    echo '<li><button type="button" class="dropdown-item' . ($selected === '' ? ' active' : '') . '" role="menuitemradio" aria-checked="' . ($selected === '' ? 'true' : 'false') . '" data-person-dropdown-option data-value="">' . mde_h($placeholder) . '</button></li>';
    foreach ($options as $person) {
        $active = $selected === $person;
        echo '<li><button type="button" class="dropdown-item' . ($active ? ' active' : '') . '" role="menuitemradio" aria-checked="' . ($active ? 'true' : 'false') . '" data-person-dropdown-option data-value="' . mde_h($person) . '">' . mde_h($person) . '</button></li>';
    }
    echo '</ul></div>';
}
