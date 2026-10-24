<?php
declare(strict_types=1);

session_start(['save_path' => sys_get_temp_dir(), 'use_cookies' => false]);
require_once __DIR__ . '/../includes/functions.php';

function valid_assessment(): array
{
    return [
        'age' => '30', 'sex' => 'male', 'civil_status' => 'single',
        'municipality' => '0', 'barangay' => 'Gundaway',
        'education' => 'college', 'employment' => 'farming_only',
        'income' => '0', 'other_income' => 'none',
        'farm_class' => '0', 'numswine' => '0', 'yearsexp' => '0',
        'feedprac' => '0', 'feedsrc' => '0', 'housing' => '0',
        'watersrc' => '0', 'expenses' => '0', 'mortality' => '0',
        'diseases' => ['none'], 'asf_aware' => '1', 'asf_outbreak' => '0',
        'asf_outbreak_year' => '', 'asf_symptoms' => ['none'],
        'biosecurity' => ['none'], 'vet_consult' => '3',
        'disinfect_freq' => '4', 'vaccinated' => '0',
        'info_sources' => ['vet_office'], 'support_orgs' => ['none'],
        'needed_services' => ['none'], 'vet_access' => '0',
        'service_location' => 'farm_visit', 'challenges' => [],
        'satisfaction' => 'not_applicable', 'system_usage' => 'probably',
        'consent' => '1',
    ];
}

$failures = [];
$checks = 0;
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: $label\n";
    }
}

$base = valid_assessment();
$valid = validate_assessment($base);
check($valid['errors'] === [], 'A consistent assessment is accepted');

$sagudayBarangays = ['Cardenas', 'Dibul', 'Gamis', 'La Paz', 'Magsaysay', 'Rizal', 'Salvacion', 'Santo Tomas', 'Tres Reyes'];
foreach ($sagudayBarangays as $barangay) {
    $result = validate_assessment(array_replace($base, ['municipality' => '5', 'barangay' => $barangay]));
    check($result['errors'] === [], "Saguday / $barangay is accepted");
    check(($result['data']['municipality'] ?? null) === '5'
        && ($result['data']['barangay'] ?? null) === $barangay, "Saguday / $barangay is ready to save");
    check($result['old']['municipality'] === '5' && $result['old']['barangay'] === $barangay,
        "Saguday / $barangay selections are retained");
}
foreach ([['5', 'Gundaway'], ['0', 'Cardenas']] as [$municipality, $barangay]) {
    $result = validate_assessment(array_replace($base, ['municipality' => $municipality, 'barangay' => $barangay]));
    check(isset($result['errors']['barangay']), "Mismatched location $municipality / $barangay is rejected");
}
foreach (['0' => 'Cabarroguis', '1' => 'Diffun', '2' => 'Maddela', '3' => 'Nagtipunan', '4' => 'Aglipay', '5' => 'Saguday'] as $code => $municipality) {
    check(option_label('municipality', (string) $code) === $municipality, "Municipality code $code displays as $municipality");
}
foreach (assessment_fields() as $field) {
    if (!in_array($field['name'], ['municipality', 'barangay'], true)) {
        continue;
    }
    ob_start();
    render_field($field, ['municipality' => '5', 'barangay' => 'Tres Reyes']);
    $document = new DOMDocument();
    $document->loadHTML(ob_get_clean());
    $xpath = new DOMXPath($document);
    if ($field['name'] === 'municipality') {
        check($xpath->query('//select/option[@value="5" and @selected and text()="Saguday"]')->length === 1,
            'The municipality dropdown offers and retains Saguday');
    } else {
        $options = [];
        foreach ($xpath->query('//select/option[@value!=""]') as $option) {
            $options[] = $option->getAttribute('value');
        }
        check($options === $sagudayBarangays, 'The barangay dropdown renders exactly the nine Saguday barangays');
        check($xpath->query('//select/option[@value="Tres Reyes" and @selected]')->length === 1,
            'The barangay dropdown retains the selected Saguday barangay');
    }
}

$cases = [
    'An ASF outbreak requires ASF in disease history' => [
        ['asf_outbreak' => '1'], 'asf_outbreak',
    ],
    'ASF in disease history requires an outbreak answer of Yes' => [
        ['diseases' => ['asf']], 'asf_outbreak',
    ],
];
foreach (['0', '1', '2', '3'] as $frequency) {
    $cases["No biosecurity conflicts with disinfection code $frequency"] = [
        ['disinfect_freq' => $frequency], 'disinfect_freq',
    ];
}
foreach (['crops', 'livestock', 'business', 'wage'] as $work) {
    $cases["Swine farming only conflicts with personal $work work"] = [
        ['other_income' => $work], 'other_income',
    ];
}
foreach (['diseases' => 'asf', 'asf_symptoms' => 'fever', 'biosecurity' => 'clean',
          'support_orgs' => 'pvet', 'needed_services' => 'vaccination'] as $group => $option) {
    $cases["None cannot accompany another $group choice"] = [
        [$group => ['none', $option]], $group,
    ];
}
foreach ($cases as $label => [$changes, $field]) {
    $result = validate_assessment(array_replace($base, $changes));
    check(isset($result['errors'][$field]), $label);
    foreach ($changes as $name => $value) {
        check($result['old'][$name] === $value, "$label: answers are retained");
    }
}

foreach ([
    'Remittances do not imply other work' => ['other_income' => 'remittance'],
    'Other work is allowed for a farmer with other employment' => ['employment' => 'farming_other', 'other_income' => 'wage'],
    'ASF history may have an unknown outbreak year' => ['diseases' => ['asf'], 'asf_outbreak' => '1'],
    'ASF history may have a known outbreak year' => ['diseases' => ['asf'], 'asf_outbreak' => '1', 'asf_outbreak_year' => '2020'],
    'Disease history is independent of annual mortality' => ['mortality' => '3'],
    'Regular disinfection is allowed with biosecurity measures' => ['biosecurity' => ['clean'], 'disinfect_freq' => '0'],
    'Cleaning without disinfection is allowed' => ['biosecurity' => ['clean'], 'disinfect_freq' => '4'],
] as $label => $changes) {
    check(validate_assessment(array_replace($base, $changes))['errors'] === [], $label);
}

$result = validate_assessment(array_replace($base, ['asf_outbreak_year' => '2020']));
check($result['errors'] === [], 'An irrelevant outbreak year does not block an otherwise valid assessment');
check($result['data']['asf_outbreak_year'] === null, 'No outbreak stores a NULL outbreak year');
check($result['old']['asf_outbreak_year'] === '', 'An irrelevant outbreak year is cleared');

$result = validate_assessment(array_replace($base, ['diseases' => ['asf'], 'asf_outbreak' => '1', 'asf_outbreak_year' => '1900']));
check(isset($result['errors']['asf_outbreak_year']), 'An applicable outbreak year must be a valid option');

$result = validate_assessment(array_replace($base, ['diseases' => ['none', 'unknown']]));
check(isset($result['errors']['diseases']), 'Unknown checkbox options are rejected');
$result = validate_assessment(array_replace($base, ['diseases' => [['asf']]]));
check(isset($result['errors']['diseases']), 'Nested checkbox values are rejected');

$expectedModelInput = [
    'FARM CLASS' => '0', 'NUMSWINE' => '0', 'YEARSEXP' => '0',
    'FEEDPRAC' => '0', 'FEEDSRC' => '0', 'HOUSING' => '0', 'WATERSRC' => '0',
    'INCOME' => '0', 'EXPENSES' => '0', 'MORTALITY' => '0',
    'ASF_AWARE' => '1', 'ASF_OUTBREAK' => '0', 'DISINFECT_FREQ' => '4',
    'VET_CONSULT' => '3', 'VACCINATED' => '0', 'VET_ACCESS' => '0',
    'DIS_ASF' => 0, 'DIS_HOGCHOL' => 0, 'DIS_PNEUMO' => 0,
    'DIS_DIARR' => 0, 'DIS_SKIN' => 0, 'DIS_NONE' => 1,
    'BIO_CLEAN' => 0, 'BIO_FOOTHBATH' => 0, 'BIO_ISOLATION' => 0,
    'BIO_VISITORS' => 0, 'BIO_WASTE' => 0, 'BIO_NONE' => 1,
    'CH_LACKINFO' => 0, 'CH_TRANSPORT' => 0, 'CH_FINANCE' => 0,
    'CH_LACKVET' => 0, 'CH_LACKMED' => 0, 'CH_INTERNET' => 0, 'CH_DISTANCE' => 0,
];
check(model_input($valid['data']) === $expectedModelInput, 'All 35 model input names, types and codes are unchanged');
$saguday = validate_assessment(array_replace($base, ['municipality' => '5', 'barangay' => 'Cardenas']));
check(model_input($saguday['data']) === $expectedModelInput, 'Saguday does not change the clustering inputs');
check(first_error_step(['asf_outbreak' => 'conflict']) === 3, 'An ASF conflict returns to the veterinary section');

echo "$checks checks, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
