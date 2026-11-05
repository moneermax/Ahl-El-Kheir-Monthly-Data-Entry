<?php
declare(strict_types=1);

// Names transcribed from the October reference CSV, plus confirmed additions.
// A leading standalone ح is a role prefix, not part of a person's name.
function mde_person_option_key(string $person): string
{
    $person = preg_replace('/^ح\s+/u', '', trim($person)) ?? trim($person);
    $person = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $person) ?? $person;
    $person = strtr($person, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    return strtolower(preg_replace('/\s+/u', ' ', trim($person)) ?? trim($person));
}
function mde_unique_person_options(array $options): array
{
    $unique = [];
    $seen = [];
    foreach ($options as $person) {
        $key = mde_person_option_key((string) $person);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = trim((string) $person);
    }
    return $unique;
}
// The same person may legitimately appear in both lists.
$mdeEnteredByOptions = mde_unique_person_options(['أروى عباس', 'ايمان', 'ساره', 'سجود', 'فاطمه', 'لمياء', 'مها', 'مياده', 'ميساء', 'هبه', 'هبه خلف الله', 'هناء', 'هناء خلف الله']);
$mdeSupervisorOptions = mde_unique_person_options(['ساره', 'فاطمه', 'مها', 'مياده', 'ميساء', 'هبه', 'هبه خلف الله', 'هناء', 'ح ميساء']);

/** Render a personnel picker. Use a native select in the horizontally scrollable entry register. */
function mde_person_dropdown(string $id, string $name, array $options, string $placeholder = 'اختر الاسم', string $selected = '', bool $nativeSelect = false): void
{
    if ($nativeSelect) {
        echo '<select class="form-select form-select-sm" id="' . mde_h($id) . '" name="' . mde_h($name) . '" data-person-dropdown-value>';
        echo '<option value="">' . mde_h($placeholder) . '</option>';
        foreach ($options as $person) {
            echo '<option value="' . mde_h($person) . '"' . ($selected === $person ? ' selected' : '') . '>' . mde_h($person) . '</option>';
        }
        echo '</select>';
        return;
    }
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
