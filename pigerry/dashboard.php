<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

/* ------------------------------------------------------------ Helpers */

/** answer value => number of assessments, for one allowed column. */
function dash_counts(PDO $pdo, string $column): array
{
    if (!in_array($column, ['municipality', 'numswine', 'cluster_id'], true)) {
        throw new InvalidArgumentException('Column not allowed');
    }
    $map = [];
    foreach ($pdo->query("SELECT `{$column}` AS v, COUNT(*) AS c FROM `assessments` GROUP BY `{$column}`") as $r) {
        $map[(string) $r['v']] = (int) $r['c'];
    }
    return $map;
}

/** One [label, count] per answer choice of a single-choice question. */
function dash_choices(string $field, array $map): array
{
    foreach (assessment_fields() as $f) {
        if ($f['name'] === $field) {
            return array_map(
                fn($key, $label) => ['label' => $label, 'count' => $map[(string) $key] ?? 0],
                array_keys($f['options']),
                $f['options']
            );
        }
    }
    return [];
}

/** [label, count] = how many farmers ticked each option of a multiple-choice question (biggest first, "None" skipped). */
function dash_checks(PDO $pdo, string $field): array
{
    foreach (assessment_fields() as $f) {
        if ($f['name'] !== $field) {
            continue;
        }
        $opts = array_diff_key($f['options'], ['none' => 1]);
        $sums = [];
        foreach ($opts as $key => $_) {
            $sums[] = "COALESCE(SUM(`{$field}_{$key}`), 0) AS `{$key}`";
        }
        $row  = $pdo->query('SELECT ' . implode(', ', $sums) . ' FROM `assessments`')->fetch();
        $rows = [];
        foreach ($opts as $key => $label) {
            $rows[] = ['label' => $label, 'count' => (int) $row[$key]];
        }
        usort($rows, fn($a, $b) => $b['count'] <=> $a['count']);
        return $rows;
    }
    return [];
}

/** One chart card: title, small subtitle and an empty <canvas> that dashboard.js fills. */
function dash_chart(string $col, string $title, string $sub, string $id, int $height = 260): void
{
    echo '<div class="' . $col . '"><div class="d-panel d-reveal">'
        . '<h2>' . e($title) . '</h2><p class="d-panel-sub">' . e($sub) . '</p>'
        . '<div class="d-chart" style="height:' . $height . 'px"><canvas id="' . e($id) . '"></canvas></div>'
        . '</div></div>';
}

/* ------------------------------------- Records of one day (calendar JSON) */
if (isset($_GET['day'])) {
    header('Content-Type: application/json; charset=utf-8');
    $day = (string) $_GET['day'];
    $ok  = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    $db  = $ok ? try_db() : null;
    if (!$db) {
        http_response_code($ok ? 500 : 400);
        exit(json_encode(['error' => 'unavailable']));
    }
    try {
        $stmt = $db->prepare('SELECT id, created_at, municipality, barangay, cluster_id FROM `assessments`
                               WHERE DATE(`created_at`) = :d ORDER BY `created_at` DESC, `id` DESC');
        $stmt->execute([':d' => $day]);
        $records = [];
        foreach ($stmt as $r) {
            $records[] = [
                'ref'   => format_reference((int) $r['id']),
                'url'   => 'records.php?id=' . (int) $r['id'],
                'time'  => date('g:i A', strtotime((string) $r['created_at'])),
                'place' => option_label('municipality', $r['municipality']) . ', ' . $r['barangay'],
                'group' => $r['cluster_id'] !== null ? 'Cluster ' . (int) $r['cluster_id'] : 'Not determined',
            ];
        }
        echo json_encode(['label' => date('M j, Y', strtotime($day)), 'records' => $records], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        error_log('[Swine Farmer Services] Day records failed: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'failed']);
    }
    exit;
}

/* --------------------------------------------------------------- Data */

$pdo = try_db();
$total = $todayCount = $weekCount = 0;
$today = date('Y-m-d');
$topGroup = null;
$profile = [];
$data = [];

if ($pdo) {
    try {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM `assessments`')->fetchColumn();
        $today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn(); // same clock that stamps each assessment

        $calendar = [];
        foreach ($pdo->query('SELECT DATE(`created_at`) AS d, COUNT(*) AS c FROM `assessments` GROUP BY DATE(`created_at`)') as $r) {
            $calendar[(string) $r['d']] = (int) $r['c'];
        }

        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $d      = (new DateTimeImmutable($today))->modify("-{$i} day")->format('Y-m-d');
            $days[] = ['label' => date('M j', strtotime($d)), 'count' => $calendar[$d] ?? 0];
        }
        $todayCount = end($days)['count'];
        $weekCount  = array_sum(array_column(array_slice($days, -7), 'count'));

        $groupMap = dash_counts($pdo, 'cluster_id');
        $groups   = [];
        for ($k = 0; $k < 5; $k++) {
            $groups[] = ['label' => 'Cluster ' . $k, 'count' => $groupMap[(string) $k] ?? 0];
            if (($groupMap[(string) $k] ?? 0) > ($topGroup['count'] ?? 0)) {
                $topGroup = end($groups);
            }
        }

        $barangays = [];
        $stmt = $pdo->query('SELECT MIN(`barangay`) AS name, `municipality` AS m, COUNT(*) AS c FROM `assessments`
                              GROUP BY `municipality`, LOWER(TRIM(`barangay`)) ORDER BY c DESC, name ASC LIMIT 8');
        foreach ($stmt as $r) {
            $barangays[] = ['label' => $r['name'] . ' (' . option_label('municipality', $r['m']) . ')', 'count' => (int) $r['c']];
        }

        // What each farmer group looks like (vaccinated, ASF outbreak, vet hard or impossible to reach).
        $profile = $pdo->query("SELECT cluster_id AS g, COUNT(*) AS n, SUM(vaccinated = '1') AS vac,
                                       SUM(asf_outbreak = '1') AS asf, SUM(vet_access IN ('3', '4')) AS far
                                  FROM `assessments` WHERE cluster_id IS NOT NULL
                                 GROUP BY cluster_id ORDER BY cluster_id")->fetchAll();

        // Everything the charts and the calendar need, handed to assets/js/dashboard.js.
        $data = [
            'total'      => $total,
            'today'      => $today,
            'calendar'   => (object) $calendar,
            'days'       => $days,
            'groups'     => $groups,
            'muni'       => dash_choices('municipality', dash_counts($pdo, 'municipality')),
            'swine'      => dash_choices('numswine', dash_counts($pdo, 'numswine')),
            'diseases'   => dash_checks($pdo, 'diseases'),
            'bio'        => dash_checks($pdo, 'biosecurity'),
            'challenges' => dash_checks($pdo, 'challenges'),
            'barangays'  => $barangays,
        ];
    } catch (Throwable $ex) {
        error_log('[Swine Farmer Services] Dashboard failed: ' . $ex->getMessage());
        $pdo = null;
    }
}

$pct  = fn(mixed $part, mixed $whole): string => (int) $whole > 0 ? round((int) $part * 100 / (int) $whole) . '%' : '–';
$kpis = [
    ['Farmer records', $total, 'All submitted assessments',
        '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>'],
    ['Submitted today', $todayCount, date('M j, Y', strtotime($today)),
        '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>'],
    ['Last 7 days', $weekCount, 'Recent submissions',
        '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>'],
    ['Most common group', $topGroup['label'] ?? '–', $topGroup ? $pct($topGroup['count'], $total) . ' of assessments' : 'No groups yet',
        '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/>'],
];

require __DIR__ . '/includes/sidebar.php';
?>
<link href="assets/css/dashboard.css" rel="stylesheet">

<section class="page-head">
    <div class="page">
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">Overview of the submitted farmer assessments. Pick a date to see its records.</p>
    </div>
</section>

<section class="section section--tight">
    <div class="page">

<?php if (!$pdo): ?>
        <div class="notice notice--error">The dashboard could not be loaded. Check that MySQL is running in XAMPP.</div>
<?php else: ?>

        <!-- Key numbers -->
        <div class="row g-3 mb-3">
            <?php foreach ($kpis as [$label, $value, $note, $icon]): ?>
                <div class="col-6 col-lg-3">
                    <div class="d-stat d-reveal">
                        <span class="d-stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg></span>
                        <p class="d-stat-label"><?= e($label) ?></p>
                        <p class="d-stat-value"><?= e($value) ?></p>
                        <p class="d-stat-note"><?= e($note) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php if ($total === 0): ?>
        <div class="notice mb-3">No assessments have been submitted yet, so there is nothing to chart.
            <a href="assessment.php" class="text-link">Start an assessment</a></div>
    <?php else: ?>
        <div class="row g-3 mb-3">
            <?php dash_chart('col-12 col-lg-8', 'Submissions per day', 'Last 14 days', 'chDays'); ?>
            <?php dash_chart('col-12 col-lg-4', 'Farmer groups', 'Cluster assigned by the model', 'chGroups'); ?>
        </div>
    <?php endif; ?>

        <!-- Calendar + records of the selected date -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-5">
                <div class="d-panel d-reveal">
                    <div class="cal-head">
                        <h2 class="cal-title" id="calTitle" aria-live="polite"></h2>
                        <div class="cal-nav">
                            <button type="button" class="cal-btn" id="calPrev" aria-label="Previous month"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button>
                            <button type="button" class="cal-btn" id="calNext" aria-label="Next month"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button>
                        </div>
                    </div>
                    <div class="cal-week" aria-hidden="true"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                    <div class="cal-grid" id="calGrid"></div>
                    <div class="cal-foot">
                        <span class="cal-legend">Has assessments</span>
                        <button type="button" class="cal-today-btn" id="calToday">Go to today</button>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-7">
                <div class="d-panel d-reveal">
                    <div class="day-head">
                        <h2 id="dayTitle">Records</h2>
                        <span class="day-count" id="dayCount"></span>
                    </div>
                    <div id="dayBody"><p class="d-empty">Loading&hellip;</p></div>
                </div>
            </div>
        </div>

    <?php if ($total > 0): ?>
        <div class="row g-3 mb-3">
            <?php dash_chart('col-12 col-lg-6', 'By municipality', 'Where the assessed farms are located', 'chMuni'); ?>
            <?php dash_chart('col-12 col-lg-6', 'Number of swine raised', 'Farm size', 'chSwine'); ?>
        </div>

        <div class="row g-3 mb-3">
            <?php dash_chart('col-12 col-lg-4', 'Diseases on the farm', 'Number of farms reporting each', 'chDis'); ?>
            <?php dash_chart('col-12 col-lg-4', 'Biosecurity practices', 'Number of farms following each', 'chBio'); ?>
            <?php dash_chart('col-12 col-lg-4', 'Service challenges', 'Number of farms facing each', 'chChal'); ?>
        </div>

        <div class="row g-3">
            <?php dash_chart('col-12 col-lg-6', 'Top barangays', 'Barangays with the most assessments', 'chBrgy', 300); ?>
            <div class="col-12 col-lg-6">
                <div class="d-panel d-reveal">
                    <h2>Group profile</h2>
                    <p class="d-panel-sub">Share of each farmer group</p>
                    <?php if (!$profile): ?>
                        <p class="d-empty">No groups yet.</p>
                    <?php else: ?>
                        <div class="d-table-wrap">
                            <table class="d-table">
                                <thead><tr><th>Group</th><th>Farms</th><th>Vaccinated</th><th>ASF outbreak</th><th>Vet hard to reach</th></tr></thead>
                                <tbody>
                                <?php foreach ($profile as $r): ?>
                                    <tr>
                                        <td>Cluster <?= (int) $r['g'] ?></td>
                                        <td><?= (int) $r['n'] ?></td>
                                        <td><?= e($pct($r['vac'], $r['n'])) ?></td>
                                        <td><?= e($pct($r['asf'], $r['n'])) ?></td>
                                        <td><?= e($pct($r['far'], $r['n'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

        <script type="application/json" id="dash-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
        <script src="assets/js/dashboard.js" defer></script>
<?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>