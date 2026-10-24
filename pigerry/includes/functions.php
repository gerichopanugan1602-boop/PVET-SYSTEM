<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function format_reference(int $id): string
{
    return 'SFS-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}
function support_services(): array
{
    return [
        'vet_consultation'  => 'Veterinary Consultation',
        'asf_prevention'    => 'ASF Prevention',
        'vaccination'       => 'Vaccination',
        'disease_diagnosis' => 'Disease Diagnosis',
        'farm_sanitation'   => 'Farm Sanitation',
        'feed_nutrition'    => 'Feed & Nutrition',
        'medicine'          => 'Medicine Recommendations',
        'emergency'         => 'Emergency Treatment',
        'training'          => 'Training & Seminars',
        'financial'         => 'Financial Assistance',
    ];
}

function outbreak_years(): array
{
    $years = [];
    for ($y = (int) date('Y'); $y >= 2019; $y--) {
        $years[$y] = (string) $y;
    }
    return $years;
}

/**
 * Barangays per municipality (Quirino province), keyed by the questionnaire's municipality code
 * (0 Cabarroguis, 1 Diffun, 2 Maddela, 3 Nagtipunan, 4 Aglipay, 5 Saguday).
 * Source: PhilAtlas list of barangays of Quirino (2020 PSA data).
 * The barangay NAME is what gets saved in the `barangay` column.
 */
function barangays_by_municipality(): array
{
    return [
        '0' => [ // Cabarroguis (17)
            'Banuar',
            'Burgos',
            'Calaocan',
            'Del Pilar',
            'Dibibi',
            'Dingasan',
            'Eden',
            'Gomez',
            'Gundaway',
            'Mangandingay',
            'San Marcos',
            'Santo Domingo',
            'Tucod',
            'Villa Peña',
            'Villamor',
            'Villarose',
            'Zamora',
        ],
        '1' => [ // Diffun (33)
            'Aklan Village',
            'Andres Bonifacio',
            'Aurora East',
            'Aurora West',
            'Baguio Village',
            'Balagbag',
            'Bannawag',
            'Cajel',
            'Campamento',
            'Diego Silang',
            'Don Faustino Pagaduan',
            'Don Mariano Perez, Sr.',
            'Doña Imelda',
            'Dumanisi',
            'Gabriela Silang',
            'Gregorio Pimentel',
            'Gulac',
            'Guribang',
            'Ifugao Village',
            'Isidro Paredes',
            'Liwayway',
            'Luttuad',
            'Magsaysay',
            'Makate',
            'Maria Clara',
            'Rafael Palma',
            'Ricarte Norte',
            'Ricarte Sur',
            'Rizal',
            'San Antonio',
            'San Isidro',
            'San Pascual',
            'Villa Pascua',
        ],
        '2' => [ // Maddela (32)
            'Abbag',
            'Balligui',
            'Buenavista',
            'Cabaruan',
            'Cabua-an',
            'Cofcaville',
            'Diduyon',
            'Dipintin',
            'Divisoria Norte',
            'Divisoria Sur',
            'Dumabato Norte',
            'Dumabato Sur',
            'Jose Ancheta',
            'Lusod',
            'Manglad',
            'Pedlisan',
            'Poblacion Norte',
            'Poblacion Sur',
            'San Bernabe',
            'San Dionisio I',
            'San Martin',
            'San Pedro',
            'San Salvador',
            'Santa Maria',
            'Santo Niño',
            'Santo Tomas',
            'Villa Agullana',
            'Villa Gracia',
            'Villa Hermosa Norte',
            'Villa Hermosa Sur',
            'Villa Jose V Ylanan',
            'Ysmael',
        ],
        '3' => [ // Nagtipunan (16)
            'Anak',
            'Asaklat',
            'Dipantan',
            'Dissimungal',
            'Guino',
            'La Conwap',
            'Landingan',
            'Mataddi',
            'Matmad',
            'Old Gumiad',
            'Ponggo',
            'San Dionisio II',
            'San Pugo',
            'San Ramos',
            'Sangbay',
            'Wasid',
        ],
        '4' => [ // Aglipay (25)
            'Alicia',
            'Cabugao',
            'Dagupan',
            'Diodol',
            'Dumabel',
            'Dungo',
            'Guinalbin',
            'Ligaya',
            'Nagabgaban',
            'Palacian',
            'Pinaripad Norte',
            'Pinaripad Sur',
            'Progreso',
            'Ramos',
            'Rang-ayan',
            'San Antonio',
            'San Benigno',
            'San Francisco',
            'San Leonardo',
            'San Manuel',
            'San Ramon',
            'Victoria',
            'Villa Pagaduan',
            'Villa Santiago',
            'Villa Ventura',
        ],
        '5' => [ // Saguday (9)
            'Cardenas',
            'Dibul',
            'Gamis',
            'La Paz',
            'Magsaysay',
            'Rizal',
            'Salvacion',
            'Santo Tomas',
            'Tres Reyes',
        ],
    ];
}

/** All barangay names (unique), as option => label, used for validation and display. */
function barangay_options(): array
{
    $all = [];
    foreach (barangays_by_municipality() as $names) {
        foreach ($names as $n) {
            $all[$n] = $n;
        }
    }
    return $all;
}

/**
 * Questionnaire.
 *
 * Questions marked [MODEL] are inputs of the clustering model (frozen.pkl).
 * Their answer choices use the model's own numeric codes ('0', '1', '2', ...),
 * exactly as in the training data. Do NOT reorder or rename these choices,
 * or farmers will be assigned to the wrong cluster.
 */
function assessment_steps(): array
{
    static $steps = null;
    if ($steps !== null) {
        return $steps;
    }

    $steps = [
        [
            'id'    => 1,
            'title' => 'Farmer Information',
            'intro' => 'Basic personal and socio-economic details of the swine farmer.',
            'fields' => [
                ['name' => 'age', 'label' => 'Age', 'type' => 'number', 'min' => 15, 'max' => 100, 'unit' => 'years'],
                ['name' => 'sex', 'label' => 'Sex', 'type' => 'radio', 'options' => [
                    'male' => 'Male', 'female' => 'Female',
                ]],
                ['name' => 'civil_status', 'label' => 'Civil status', 'type' => 'select', 'options' => [
                    'single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated',
                ]],
                ['name' => 'municipality', 'label' => 'Municipality', 'type' => 'select', 'options' => [
                        '0' => 'Cabarroguis',
                        '1' => 'Diffun',
                        '2' => 'Maddela',
                        '3' => 'Nagtipunan',
                        '4' => 'Aglipay',
                        '5' => 'Saguday',
                    ]],
                // Barangay depends on the municipality chosen above (see barangays_by_municipality()).
                ['name' => 'barangay', 'label' => 'Barangay', 'type' => 'select',
                    'depends' => 'municipality', 'groups' => barangays_by_municipality(),
                    'sql' => 'VARCHAR(100) NOT NULL', 'options' => barangay_options()],
                ['name' => 'education', 'label' => 'Highest educational attainment', 'type' => 'select', 'options' => [
                    'none'        => 'No formal schooling',
                    'elementary'  => 'Elementary',
                    'high_school' => 'High school',
                    'vocational'  => 'Vocational / technical',
                    'college'     => 'College',
                    'postgraduate'=> 'Postgraduate',
                ]],
                ['name' => 'employment', 'label' => 'Employment status', 'type' => 'radio', 'options' => [
                    'farming_only'    => 'Swine farming only',
                    'farming_other'   => 'Farming together with other work',
                    'employed'        => 'Employed outside farming',
                    'self_employed'   => 'Self-employed / business',
                    'not_working'     => 'Retired or not working',
                ]],
                // [MODEL]
                ['name' => 'income', 'label' => 'Average monthly household income', 'type' => 'radio', 'options' => [
                        '0' => 'Below ₱10,000',
                        '1' => '₱10,000–20,000',
                        '2' => '₱20,001–30,000',
                        '3' => '₱30,001 and above',
                    ]],
                ['name' => 'other_income', 'label' => 'Your other source of income or work', 'type' => 'select',
                    'hint' => 'Report your own income or work, not that of other household members. Remittances do not count as other work.', 'options' => [
                    'none'          => 'None',
                    'crops'         => 'Crop farming',
                    'livestock'     => 'Other livestock or poultry',
                    'business'      => 'Small business / trading',
                    'wage'          => 'Wage employment',
                    'remittance'    => 'Remittances',
                    'other'         => 'Other',
                ]],
            ],
        ],
        [
            'id'    => 2,
            'title' => 'Farm Profile',
            'intro' => 'Scale, management and condition of the swine operation.',
            'fields' => [
                // [MODEL] all fields in this section except none
                ['name' => 'farm_class', 'label' => 'Farm classification', 'type' => 'radio', 'options' => [
                        '0' => 'Backyard',
                        '1' => 'Commercial',
                    ]],
                ['name' => 'numswine', 'label' => 'Number of swine currently raised', 'type' => 'radio', 'options' => [
                        '0' => '1–10 pigs',
                        '1' => '11–20 pigs',
                        '2' => '21–50 pigs',
                        '3' => '51 and above',
                    ]],
                ['name' => 'yearsexp', 'label' => 'Years of experience in swine raising', 'type' => 'radio', 'options' => [
                        '0' => 'Below 1 year',
                        '1' => '1–3 years',
                        '2' => '4–7 years',
                        '3' => '8 years and above',
                    ]],
                ['name' => 'feedprac', 'label' => 'Main feeding practice', 'type' => 'radio', 'options' => [
                        '0' => 'Traditional feeding',
                        '1' => 'Commercial feeds',
                        '2' => 'Mixed feeding',
                    ]],
                ['name' => 'feedsrc', 'label' => 'Main source of feeds', 'type' => 'select', 'options' => [
                        '0' => 'Homemade feeds',
                        '1' => 'Agricultural supply store',
                        '2' => 'Commercial feed brand dealership',
                    ]],
                ['name' => 'housing', 'label' => 'Type of housing system', 'type' => 'radio', 'options' => [
                        '0' => 'Open',
                        '1' => 'Semi-closed',
                        '2' => 'Closed',
                    ]],
                ['name' => 'watersrc', 'label' => 'Main water source', 'type' => 'select', 'options' => [
                        '0' => 'Deep well',
                        '1' => 'Faucet',
                        '2' => 'River/Creek',
                    ]],
                ['name' => 'expenses', 'label' => 'Average monthly farm expenses', 'type' => 'radio', 'options' => [
                        '0' => 'Below ₱5,000',
                        '1' => '₱5,000–10,000',
                        '2' => '₱10,001–20,000',
                        '3' => '₱20,001 and above',
                    ]],
                ['name' => 'mortality', 'label' => 'Swine deaths in the past 12 months', 'type' => 'radio', 'options' => [
                        '0' => 'None',
                        '1' => '1–5',
                        '2' => '6–10',
                        '3' => 'More than 10',
                    ]],
                ['name' => 'diseases', 'label' => 'Diseases ever experienced on this farm', 'type' => 'checkbox', 'hint' => 'Include the entire history of this farm, not just the past 12 months. Select all that apply.', 'options' => [
                        'asf' => 'African Swine Fever',
                        'hogchol' => 'Hog Cholera',
                        'pneumo' => 'Pneumonia',
                        'diarr' => 'Diarrhea',
                        'skin' => 'Skin disease',
                        'none' => 'No diseases',
                    ]],
            ],
        ],
        [
            'id'    => 3,
            'title' => 'Veterinary & Biosecurity',
            'intro' => 'Disease awareness, prevention practices and use of veterinary services.',
            'fields' => [
                // [MODEL]
                ['name' => 'asf_aware', 'label' => 'Are you aware of African swine fever (ASF)?', 'type' => 'radio', 'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ]],
                // [MODEL]
                ['name' => 'asf_outbreak', 'label' => 'Has an ASF outbreak ever happened on this farm?', 'type' => 'radio',
                    'hint' => 'Use the same farm history as the diseases question.', 'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ]],
                ['name' => 'asf_outbreak_year', 'label' => 'Year of ASF outbreak on the farm (if any)', 'type' => 'select',
                    'showIf' => ['asf_outbreak', '1'],
                    'hint' => 'Optional. Leave blank if the year is unknown. Recorded for reference only.',
                    'required' => false, 'sql' => 'SMALLINT UNSIGNED NULL DEFAULT NULL', 'cast' => 'int',
                    'options' => outbreak_years()],
                ['name' => 'asf_symptoms', 'label' => 'ASF symptoms you can recognize', 'type' => 'checkbox', 'hint' => 'Select all that apply.', 'options' => [
                    'fever'        => 'High fever',
                    'appetite'     => 'Loss of appetite',
                    'skin'         => 'Reddish or purplish skin discoloration',
                    'vomiting'     => 'Vomiting',
                    'diarrhea'     => 'Diarrhea, sometimes bloody',
                    'breathing'    => 'Difficulty breathing or coughing',
                    'sudden_death' => 'Sudden death',
                    'none'         => 'None / cannot identify',
                ]],
                // [MODEL]
                ['name' => 'biosecurity', 'label' => 'Biosecurity practices followed', 'type' => 'checkbox', 'hint' => 'Select all that apply.', 'options' => [
                        'clean' => 'Cleaning/disinfection',
                        'footbath' => 'Foot bath',
                        'isolation' => 'Isolation of sick pigs',
                        'visitors' => 'Controlled visitors',
                        'waste' => 'Proper waste disposal',
                        'none' => 'No biosecurity measures',
                    ]],
                // [MODEL]
                ['name' => 'vet_consult', 'label' => 'When your pigs are sick, how often do you consult a veterinarian?', 'type' => 'radio', 'options' => [
                        '0' => 'Always',
                        '1' => 'Sometimes',
                        '2' => 'Rarely',
                        '3' => 'Never',
                    ]],
                // [MODEL]
                ['name' => 'disinfect_freq', 'label' => 'How often do you disinfect the pens?', 'type' => 'radio', 'options' => [
                        '0' => 'Daily',
                        '1' => 'Weekly',
                        '2' => 'Monthly',
                        '3' => 'Rarely',
                        '4' => 'Never',
                    ]],
                // [MODEL]
                ['name' => 'vaccinated', 'label' => 'Have your pigs received vaccinations?', 'type' => 'radio', 'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ]],
                ['name' => 'info_sources', 'label' => 'Sources of information on swine raising and disease control', 'type' => 'checkbox', 'hint' => 'Select all that apply.', 'options' => [
                    'vet_office'   => 'Provincial or municipal veterinary office',
                    'agrivet'      => 'Agri-vet store or feed dealer',
                    'farmers'      => 'Fellow farmers',
                    'social_media' => 'Social media or internet',
                    'radio_tv'     => 'Radio or television',
                    'seminars'     => 'Seminars and trainings',
                    'cooperative'  => 'Cooperative or association',
                ]],
            ],
        ],
        [
            'id'    => 4,
            'title' => 'Service Needs',
            'intro' => 'Support currently received and support the farmer needs.',
            'fields' => [
                ['name' => 'support_orgs', 'label' => 'Organizations currently providing support', 'type' => 'checkbox', 'hint' => 'Select all that apply.', 'options' => [
                    'pvet'        => 'Provincial Veterinary Office',
                    'municipal'   => 'Municipal agriculture office',
                    'da'          => 'Department of Agriculture',
                    'barangay'    => 'Barangay or local government unit',
                    'cooperative' => 'Cooperative or association',
                    'private_vet' => 'Private veterinarian or agri-vet',
                    'ngo'         => 'Non-government organization',
                    'none'        => 'None',
                ]],
                ['name' => 'needed_services', 'label' => 'Services needed', 'type' => 'checkbox', 'hint' => 'Select all that apply.',
                    'options' => support_services() + ['none' => 'No service needed at this time']],
                // [MODEL]
                ['name' => 'vet_access', 'label' => 'Access to veterinary services', 'type' => 'radio', 'options' => [
                        '0' => 'Very accessible',
                        '1' => 'Accessible',
                        '2' => 'Moderately accessible',
                        '3' => 'Difficult to access',
                        '4' => 'Not accessible',
                    ]],
                ['name' => 'service_location', 'label' => 'Preferred location for receiving services', 'type' => 'radio', 'options' => [
                    'farm_visit' => 'Veterinary visit to my farm',
                    'barangay'   => 'Barangay hall or community venue',
                    'municipal'  => 'Municipal agriculture office',
                    'provincial' => 'Provincial Veterinary Office',
                    'remote'     => 'Phone or online consultation',
                ]],
            ],
        ],
        [
            'id'    => 5,
            'title' => 'Preferences & Feedback',
            'intro' => 'Challenges, satisfaction with current services and openness to this system.',
            'fields' => [
                // [MODEL] optional: leave all unchecked when there is no challenge
                ['name' => 'challenges', 'label' => 'Challenges in getting veterinary services and information', 'type' => 'checkbox', 'required' => false, 'hint' => 'Select all that apply. Leave blank if none.', 'options' => [
                        'lackinfo' => 'Lack of information',
                        'transport' => 'Transportation difficulty',
                        'finance' => 'Financial limitations',
                        'lackvet' => 'Lack of veterinarians',
                        'lackmed' => 'Lack of medicines',
                        'internet' => 'Poor internet',
                        'distance' => 'Physical distance',
                    ]],
                ['name' => 'satisfaction', 'label' => 'Satisfaction with the veterinary services received so far', 'type' => 'radio', 'options' => [
                    'very_satisfied'    => 'Very satisfied',
                    'satisfied'         => 'Satisfied',
                    'neutral'           => 'Neutral',
                    'dissatisfied'      => 'Dissatisfied',
                    'very_dissatisfied' => 'Very dissatisfied',
                    'not_applicable'    => 'Have not received services',
                ]],
                ['name' => 'system_usage', 'label' => 'Willingness to use a digital assessment system like this', 'type' => 'radio', 'options' => [
                    'definitely' => 'Definitely',
                    'probably'   => 'Probably',
                    'unsure'     => 'Not sure',
                    'unlikely'   => 'Unlikely',
                ]],
            ],
        ],
    ];

    return $steps;
}

/**
 * Converts a validated assessment (the array saved to the database) into the
 * 35 inputs expected by ml/cluster.php -> predictCluster().
 * Keys = model field names, values = the model's numeric codes.
 */
function model_input(array $data): array
{
    static $map = [
        'FARM CLASS' => 'farm_class',
        'NUMSWINE' => 'numswine',
        'YEARSEXP' => 'yearsexp',
        'FEEDPRAC' => 'feedprac',
        'FEEDSRC' => 'feedsrc',
        'HOUSING' => 'housing',
        'WATERSRC' => 'watersrc',
        'INCOME' => 'income',
        'EXPENSES' => 'expenses',
        'MORTALITY' => 'mortality',
        'ASF_AWARE' => 'asf_aware',
        'ASF_OUTBREAK' => 'asf_outbreak',
        'DISINFECT_FREQ' => 'disinfect_freq',
        'VET_CONSULT' => 'vet_consult',
        'VACCINATED' => 'vaccinated',
        'VET_ACCESS' => 'vet_access',
        'DIS_ASF' => 'diseases_asf',
        'DIS_HOGCHOL' => 'diseases_hogchol',
        'DIS_PNEUMO' => 'diseases_pneumo',
        'DIS_DIARR' => 'diseases_diarr',
        'DIS_SKIN' => 'diseases_skin',
        'DIS_NONE' => 'diseases_none',
        'BIO_CLEAN' => 'biosecurity_clean',
        'BIO_FOOTHBATH' => 'biosecurity_footbath',
        'BIO_ISOLATION' => 'biosecurity_isolation',
        'BIO_VISITORS' => 'biosecurity_visitors',
        'BIO_WASTE' => 'biosecurity_waste',
        'BIO_NONE' => 'biosecurity_none',
        'CH_LACKINFO' => 'challenges_lackinfo',
        'CH_TRANSPORT' => 'challenges_transport',
        'CH_FINANCE' => 'challenges_finance',
        'CH_LACKVET' => 'challenges_lackvet',
        'CH_LACKMED' => 'challenges_lackmed',
        'CH_INTERNET' => 'challenges_internet',
        'CH_DISTANCE' => 'challenges_distance',
    ];

    $input = [];
    foreach ($map as $modelField => $column) {
        $input[$modelField] = $data[$column] ?? null;
    }
    return $input;
}

function assessment_fields(): array
{
    $all = [];
    foreach (assessment_steps() as $step) {
        foreach ($step['fields'] as $f) {
            $f['step'] = $step['id'];
            $all[] = $f;
        }
    }
    return $all;
}

/** Number of questions a person normally answers (conditional ones excluded). */
function assessment_question_count(): int
{
    return count(array_filter(assessment_fields(), fn($f) => empty($f['showIf'])));
}

function option_label(string $field, mixed $key): string
{
    foreach (assessment_fields() as $f) {
        if ($f['name'] === $field && isset($f['options'][$key])) {
            return $f['options'][$key];
        }
    }
    return (string) $key;
}

/* ------------------------------------------------------------------
 * Database schema (generated from the questionnaire)
 * ---------------------------------------------------------------- */

function assessment_columns(): array
{
    $cols = [];
    foreach (assessment_fields() as $f) {
        if ($f['type'] === 'checkbox') {
            foreach ($f['options'] as $key => $_label) {
                $cols[$f['name'] . '_' . $key] = 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0';
            }
            continue;
        }
        $cols[$f['name']] = $f['sql'] ?? match ($f['type']) {
            'number' => 'INT UNSIGNED NOT NULL',
            'text'   => 'VARCHAR(100) NOT NULL',
            default  => 'VARCHAR(64) NOT NULL',
        };
    }
    return $cols;
}

function assessment_table_sql(): string
{
    $lines = [
        '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT',
        '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        // Reserved for future model integration. Intentionally nullable and unassumed.
        '`cluster_id` INT NULL DEFAULT NULL',
    ];
    foreach (assessment_columns() as $col => $type) {
        $lines[] = "`{$col}` {$type}";
    }
    $lines[] = 'PRIMARY KEY (`id`)';
    $lines[] = 'KEY `idx_created_at` (`created_at`)';

    return "CREATE TABLE IF NOT EXISTS `assessments` (\n  "
        . implode(",\n  ", $lines)
        . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/* ------------------------------------------------------------------
 * Validation & persistence
 * ---------------------------------------------------------------- */

/**
 * @return array{data: array, errors: array, old: array}
 */
function validate_assessment(array $post): array
{
    $data   = [];
    $errors = [];
    $old    = [];
    $single = []; // submitted single-choice values, used by conditional fields

    $msgRequired = 'This question requires a response.';

    foreach (assessment_fields() as $f) {
        $name     = $f['name'];
        $type     = $f['type'];
        $required = $f['required'] ?? true;

        // Conditional field: skip and store NULL when its condition is not met.
        if (isset($f['showIf'])) {
            [$dependsOn, $expected] = $f['showIf'];
            if (($single[$dependsOn] ?? null) !== $expected) {
                $data[$name] = null;
                $old[$name]  = '';
                continue;
            }
        }

        switch ($type) {
            case 'checkbox':
                $raw      = $post[$name] ?? [];
                $selected = [];
                if (!is_array($raw)) {
                    $errors[$name] = 'Select valid options for this question.';
                    $raw = [];
                }
                foreach ($raw as $k) {
                    if (!is_string($k) || !isset($f['options'][$k])) {
                        $errors[$name] = 'Select valid options for this question.';
                        continue;
                    }
                    $selected[] = $k;
                }
                $selected = array_values(array_unique($selected));
                if (in_array('none', $selected, true) && count($selected) > 1) {
                    $errors[$name] = 'Select either the None option or the other choices, not both.';
                }
                $old[$name] = $selected;
                if ($required && !$selected) {
                    $errors[$name] = $msgRequired;
                }
                foreach ($f['options'] as $k => $_label) {
                    $data[$name . '_' . $k] = in_array((string) $k, $selected, true) ? 1 : 0;
                }
                break;

            case 'number':
                $raw        = trim((string) ($post[$name] ?? ''));
                $old[$name] = $raw;
                if ($raw === '' || !ctype_digit($raw)) {
                    $errors[$name] = $msgRequired;
                    break;
                }
                $n = (int) $raw;
                if ($n < $f['min'] || $n > $f['max']) {
                    $errors[$name] = "Enter a value between {$f['min']} and {$f['max']}.";
                    break;
                }
                $data[$name] = $n;
                break;

            case 'text':
                $raw        = trim((string) ($post[$name] ?? ''));
                $old[$name] = $raw;
                if ($raw === '') {
                    $errors[$name] = $msgRequired;
                } elseif (mb_strlen($raw) > ($f['max'] ?? 100)) {
                    $errors[$name] = 'This response is too long.';
                } else {
                    $data[$name] = $raw;
                }
                break;

            default: // select, radio
                $raw        = (string) ($post[$name] ?? '');
                $old[$name] = $raw;
                if ($raw === '' && !$required) {
                    $data[$name] = null; // optional question left blank
                    break;
                }
                if (!isset($f['options'][$raw])) {
                    $errors[$name] = $msgRequired;
                    break;
                }
                $single[$name] = $raw;
                $data[$name]   = (($f['cast'] ?? '') === 'int') ? (int) $raw : $raw;
        }
    }

    if (!isset($errors['diseases']) && isset($data['asf_outbreak'])
        && (bool) $data['diseases_asf'] !== ($data['asf_outbreak'] === '1')) {
        $errors['asf_outbreak'] = 'Your ASF outbreak answer must match African Swine Fever in the farm disease history. Review both answers.';
    }

    if (!isset($errors['biosecurity']) && !empty($data['biosecurity_none'])
        && isset($data['disinfect_freq']) && $data['disinfect_freq'] !== '4') {
        $errors['disinfect_freq'] = 'Disinfection is a biosecurity measure. Review the biosecurity choices or select Never.';
    }

    if (($data['employment'] ?? null) === 'farming_only'
        && in_array($data['other_income'] ?? null, ['crops', 'livestock', 'business', 'wage'], true)) {
        $errors['other_income'] = 'This income source describes other work. Review your employment status or your other income source.';
    }

    // The barangay must belong to the chosen municipality.
    if (!isset($errors['barangay']) && isset($data['barangay'], $data['municipality'])) {
        $valid = barangays_by_municipality()[(string) $data['municipality']] ?? [];
        if (!in_array($data['barangay'], $valid, true)) {
            $errors['barangay'] = 'Select a barangay that belongs to the chosen municipality.';
        }
    }

    $old['consent'] = !empty($post['consent']);
    if (!$old['consent']) {
        $errors['consent'] = 'Confirmation is required before the assessment can be submitted.';
    }

    return ['data' => $data, 'errors' => $errors, 'old' => $old];
}

function first_error_step(array $errors): int
{
    foreach (assessment_fields() as $f) {
        if (isset($errors[$f['name']])) {
            return (int) $f['step'];
        }
    }
    return isset($errors['consent']) ? count(assessment_steps()) + 1 : 1;
}

function save_assessment(PDO $pdo, array $data): int
{
    $cols         = array_keys($data);
    $columnList   = '`' . implode('`, `', $cols) . '`';
    $placeholders = ':' . implode(', :', $cols);

    $stmt = $pdo->prepare("INSERT INTO `assessments` ({$columnList}) VALUES ({$placeholders})");
    $stmt->execute($data);

    return (int) $pdo->lastInsertId();
}

function recent_assessments(PDO $pdo, int $limit = 6): array
{
    // Answers are stored as model codes; convert to readable labels for display.
    // (numswine is returned under its old name swine_count for the dashboard.)
    $stmt = $pdo->prepare(
        'SELECT id, created_at, municipality, barangay, farm_class, numswine AS swine_count, cluster_id
           FROM assessments
          ORDER BY id DESC
          LIMIT :n'
    );
    $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['municipality'] = option_label('municipality', $r['municipality']);
        $r['farm_class']   = option_label('farm_class', $r['farm_class']);
        $r['swine_count']  = option_label('numswine', $r['swine_count']);
    }
    unset($r);

    return $rows;
}

/* ------------------------------------------------------------------
 * Form rendering
 * ---------------------------------------------------------------- */

function render_field(array $f, array $old = [], array $errors = []): void
{
    $name     = $f['name'];
    $type     = $f['type'];
    $required = $f['required'] ?? true;
    $value    = $old[$name] ?? ($type === 'checkbox' ? [] : '');
    $error    = $errors[$name] ?? null;
    $id       = 'f_' . $name;

    // Conditional visibility
    $showAttr = '';
    $hidden   = false;
    if (isset($f['showIf'])) {
        [$dependsOn, $expected] = $f['showIf'];
        $showAttr = ' data-show-if="' . e($dependsOn . ':' . $expected) . '"';
        $hidden   = ((string) ($old[$dependsOn] ?? '')) !== $expected;
    }

    $wide  = in_array($type, ['radio', 'checkbox'], true);
    $col   = $wide ? 'col-12' : 'col-12 col-md-6';
    $classes = 'field ' . $col . ($error ? ' has-error' : '');

    echo '<div class="' . $classes . '" data-name="' . e($name) . '" data-type="' . e($type) . '"'
        . ' data-required="' . ($required ? '1' : '0') . '"' . $showAttr . ($hidden ? ' hidden' : '') . '>';

    $star = $required ? '<span class="req" aria-hidden="true">*</span>' : '';
    $hint = !empty($f['hint']) ? '<div class="field-hint">' . e($f['hint']) . '</div>' : '';

    if ($wide) {
        echo '<fieldset><legend class="field-label"><span class="lbl">' . e($f['label']) . '</span>' . $star . '</legend>' . $hint;
        echo '<div class="choice-grid' . ($type === 'checkbox' ? ' choice-grid--multi' : '') . '">';
        foreach ($f['options'] as $key => $label) {
            $key     = (string) $key;
            $checked = $type === 'checkbox'
                ? in_array($key, (array) $value, true)
                : ((string) $value === $key);
            $inputName = $type === 'checkbox' ? $name . '[]' : $name;
            echo '<label class="choice"><input type="' . $type . '" name="' . e($inputName) . '" value="' . e($key) . '"'
                . ($checked ? ' checked' : '')
                . ($type === 'checkbox' && $key === 'none' ? ' data-exclusive="1"' : '')
                . '><span>' . e($label) . '</span></label>';
        }
        echo '</div></fieldset>';
    } else {
        echo '<label class="field-label" for="' . e($id) . '"><span class="lbl">' . e($f['label']) . '</span>' . $star . '</label>' . $hint;
        $disabled = $hidden ? ' disabled' : '';

        if ($type === 'select') {
            $grouped = !empty($f['depends']);
            $attrs   = $grouped
                ? ' data-depends="' . e($f['depends']) . '" data-groups="' . e(json_encode($f['groups'], JSON_UNESCAPED_UNICODE)) . '"'
                : '';
            echo '<select class="form-select" id="' . e($id) . '" name="' . e($name) . '"' . $attrs . $disabled . '>';

            if ($grouped) {
                // Only the barangays of the municipality already chosen (e.g. after a validation error).
                $parentVal = (string) ($old[$f['depends']] ?? '');
                echo '<option value="">' . ($parentVal === '' ? 'Select a municipality first' : 'Select a barangay') . '</option>';
                foreach ($f['groups'][$parentVal] ?? [] as $label) {
                    $sel = ((string) $value === (string) $label) ? ' selected' : '';
                    echo '<option value="' . e($label) . '"' . $sel . '>' . e($label) . '</option>';
                }
            } else {
                echo '<option value="">Select an option</option>';
                foreach ($f['options'] as $key => $label) {
                    $sel = ((string) $value === (string) $key) ? ' selected' : '';
                    echo '<option value="' . e($key) . '"' . $sel . '>' . e($label) . '</option>';
                }
            }
            echo '</select>';
        } elseif ($type === 'number') {
            echo '<div class="input-unit">';
            echo '<input class="form-control" type="number" inputmode="numeric" id="' . e($id) . '" name="' . e($name) . '"'
                . ' min="' . (int) $f['min'] . '" max="' . (int) $f['max'] . '" step="1" value="' . e($value) . '">';
            if (!empty($f['unit'])) {
                echo '<span class="unit">' . e($f['unit']) . '</span>';
            }
            echo '</div>';
        } else {
            echo '<input class="form-control" type="text" id="' . e($id) . '" name="' . e($name) . '"'
                . ' maxlength="' . (int) ($f['max'] ?? 100) . '" autocomplete="off" value="' . e($value) . '">';
        }
    }

    echo '<div class="field-error" role="alert">' . e($error ?? 'This question requires a response.') . '</div>';
    echo '</div>';
}


function sample_clusters(): array
{
    return [
        ['label' => 'Cluster A', 'farmers' => 62],
        ['label' => 'Cluster B', 'farmers' => 94],
        ['label' => 'Cluster C', 'farmers' => 51],
        ['label' => 'Cluster D', 'farmers' => 41],
    ];
}
