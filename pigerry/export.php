<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();                       // only logged-in admins can open or download from this page

$pageTitle  = 'Export';
$activePage = 'export';

/* ------------------------------------------------------------ Helpers */

/** Valid YYYY-MM-DD or null. */
function export_date(mixed $v): ?string
{
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** WHERE clause for the optional date range (fills $params). */
function export_where(?string $from, ?string $to, array &$params): string
{
    $w = [];
    if ($from !== null) {
        $w[] = 'DATE(`created_at`) >= :from';
        $params[':from'] = $from;
    }
    if ($to !== null) {
        $w[] = 'DATE(`created_at`) <= :to';
        $params[':to'] = $to;
    }
    return $w ? 'WHERE ' . implode(' AND ', $w) : '';
}

/** Header row. mode = 'readable' (text) or 'codes' (numeric codes, one 1/0 column per checkbox option). */
function export_headers(string $mode): array
{
    $h = $mode === 'codes'
        ? ['reference', 'date_submitted', 'time_submitted', 'cluster_id']
        : ['Reference number', 'Date submitted', 'Time submitted', 'Farmer group (cluster)'];

    foreach (assessment_fields() as $f) {
        if ($f['type'] === 'checkbox' && $mode === 'codes') {
            foreach ($f['options'] as $key => $_label) {
                $h[] = $f['name'] . '_' . $key;
            }
        } else {
            $h[] = $mode === 'codes' ? $f['name'] : $f['label'];
        }
    }
    return $h;
}

/** One data row, in the same order as export_headers(). */
function export_row(array $r, string $mode): array
{
    $t   = strtotime((string) $r['created_at']);
    $row = [
        format_reference((int) $r['id']),
        $t ? date('Y-m-d', $t) : (string) $r['created_at'],
        $t ? date('H:i:s', $t) : '',
        $r['cluster_id'] === null ? '' : ($mode === 'codes' ? (int) $r['cluster_id'] : 'Cluster ' . (int) $r['cluster_id']),
    ];

    foreach (assessment_fields() as $f) {
        $name = $f['name'];

        if ($f['type'] === 'checkbox') {
            if ($mode === 'codes') {
                foreach ($f['options'] as $key => $_label) {
                    $row[] = (int) ($r[$name . '_' . $key] ?? 0);
                }
            } else {
                $picked = [];
                foreach ($f['options'] as $key => $label) {
                    if (!empty($r[$name . '_' . $key])) {
                        $picked[] = $label;
                    }
                }
                $row[] = implode('; ', $picked);
            }
            continue;
        }

        $v = $r[$name] ?? null;
        if ($v === null || $v === '') {
            $row[] = '';
        } elseif ($mode === 'codes' || $f['type'] === 'number' || $f['type'] === 'text') {
            $row[] = $v;
        } else {
            $row[] = option_label($name, $v);
        }
    }
    return $row;
}

/** Stops a cell from being run as a formula when the CSV is opened in Excel. */
function csv_safe(mixed $v): mixed
{
    return (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
}

/** Column letter for a 0-based index: 0 => A, 26 => AA. */
function xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** One worksheet cell. Whole numbers are stored as numbers, everything else as text. */
function xlsx_cell(string $ref, mixed $v, int $style = 0): string
{
    if ($v === '' || $v === null) {
        return '';
    }
    $s = $style ? ' s="' . $style . '"' : '';
    if (is_int($v) || (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,14})$/', $v))) {
        return '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
    }
    $t = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $v) ?? '';
    return '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">'
        . htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
}

/* ---------------------------------------------- Live count (for the page) */
if (isset($_GET['count'])) {
    header('Content-Type: application/json; charset=utf-8');
    $db = try_db();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['error' => 'unavailable']);
        exit;
    }
    $params = [];
    $where  = export_where(export_date($_GET['from'] ?? null), export_date($_GET['to'] ?? null), $params);
    $stmt   = $db->prepare("SELECT COUNT(*) FROM `assessments` {$where}");
    $stmt->execute($params);
    echo json_encode(['count' => (int) $stmt->fetchColumn()]);
    exit;
}

/* ------------------------------------------------------------- Download */
if (isset($_GET['download'])) {
    ini_set('display_errors', '0');          // a stray notice would corrupt the file

    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    $mode   = ($_GET['mode'] ?? 'readable') === 'codes' ? 'codes' : 'readable';
    $from   = export_date($_GET['from'] ?? null);
    $to     = export_date($_GET['to'] ?? null);
    if ($from !== null && $to !== null && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $db = try_db();
    if (!$db) {
        http_response_code(500);
        exit('The database is not available. Check that MySQL is running in XAMPP.');
    }
    if ($format === 'xlsx' && !class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Excel export needs the PHP zip extension. Enable extension=zip in php.ini and restart Apache, or download the CSV instead.');
    }

    $params = [];
    $where  = export_where($from, $to, $params);
    $stmt   = $db->prepare("SELECT * FROM `assessments` {$where} ORDER BY `id` ASC");
    $stmt->execute($params);

    $headers  = export_headers($mode);
    $filename = 'swine-farmer-assessments-' . date('Ymd-His') . '.' . $format;

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");        // UTF-8 BOM so Excel shows the characters correctly
        fputcsv($out, $headers, ',', '"', '\\');
        while ($r = $stmt->fetch()) {
            fputcsv($out, array_map('csv_safe', export_row($r, $mode)), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    // ---- Excel (.xlsx): a small hand-built workbook, no extra libraries needed ----
    $ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $cols = count($headers);

    $colXml = '';
    foreach ($headers as $i => $h) {
        $w = min(42, max(12, mb_strlen($h) + 3));
        $colXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    $sheet   = [];
    $sheet[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $ns . '">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $colXml . '</cols><sheetData>';

    $cells = '';
    foreach ($headers as $i => $h) {
        $cells .= xlsx_cell(xlsx_col($i) . '1', $h, 1);
    }
    $sheet[] = '<row r="1">' . $cells . '</row>';

    $n = 1;
    while ($r = $stmt->fetch()) {
        $n++;
        $cells = '';
        foreach (export_row($r, $mode) as $i => $v) {
            $cells .= xlsx_cell(xlsx_col($i) . $n, $v);
        }
        $sheet[] = '<row r="' . $n . '">' . $cells . '</row>';
    }
    $sheet[] = '</sheetData><autoFilter ref="A1:' . xlsx_col($cols - 1) . '1"/></worksheet>';

    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $parts = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '">'
            . '<sheets><sheet name="Assessments" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . $ns . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => implode('', $sheet),
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Could not create the Excel file.');
    }
    foreach ($parts as $path => $xml) {
        $zip->addFromString($path, $xml);
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

/* ---------------------------------------------------------------- Page */

$pdo   = try_db();
$total = 0;
$first = null;
$last  = null;
$today = date('Y-m-d');

if ($pdo) {
    try {
        $row   = $pdo->query('SELECT COUNT(*) AS c, MIN(DATE(`created_at`)) AS a, MAX(DATE(`created_at`)) AS b, CURDATE() AS t FROM `assessments`')->fetch();
        $total = (int) $row['c'];
        $first = $row['a'];
        $last  = $row['b'];
        $today = (string) $row['t'];
    } catch (Throwable $ex) {
        error_log('[Swine Farmer Services] Export page failed: ' . $ex->getMessage());
        $pdo = null;
    }
}

$zipOk       = class_exists('ZipArchive');
$columnCount = count(export_headers('readable'));
$codeColumns = count(export_headers('codes'));

/* Stylesheet: change the path if your CSS folder is named differently. */
$exportCss = 'assets/css/export.css';
$cssVer    = is_file(__DIR__ . '/' . $exportCss) ? filemtime(__DIR__ . '/' . $exportCss) : 1;

require __DIR__ . '/includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= e($exportCss) ?>?v=<?= (int) $cssVer ?>">

<section class="page-head">
    <div class="page">
        <h1 class="page-title">Export</h1>
        <p class="page-sub">Download the assessment records as an Excel or CSV file.</p>
    </div>
</section>

<section class="section section--tight">
    <div class="page">

    <?php if (!$pdo): ?>
        <div class="notice notice--error">The page could not be loaded. Check that MySQL is running in XAMPP.</div>
    <?php elseif ($total === 0): ?>
        <div class="notice">There are no assessments to export yet.
            <a href="assessment.php" class="text-link">Start an assessment</a></div>
    <?php else: ?>

        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <form class="x-panel" id="exportForm" method="get" action="export.php">
                    <input type="hidden" name="download" value="1">

                    <fieldset class="x-group">
                        <legend>File format</legend>
                        <div class="x-choices">
                            <label class="x-choice">
                                <input type="radio" name="format" value="xlsx" <?= $zipOk ? 'checked' : 'disabled' ?>>
                                <span><strong>Excel (.xlsx)</strong>
                                    <span><?= $zipOk ? 'Opens directly in Excel, with a frozen header and filters.' : 'Not available: enable extension=zip in php.ini.' ?></span></span>
                            </label>
                            <label class="x-choice">
                                <input type="radio" name="format" value="csv" <?= $zipOk ? '' : 'checked' ?>>
                                <span><strong>CSV (.csv)</strong>
                                    <span>Plain text. Works in Excel, Google Sheets and other tools.</span></span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="x-group">
                        <legend>How answers are written</legend>
                        <div class="x-choices">
                            <label class="x-choice">
                                <input type="radio" name="mode" value="readable" checked>
                                <span><strong>Readable text</strong>
                                    <span>For example &ldquo;Backyard&rdquo;. Checkbox answers share one cell.</span></span>
                            </label>
                            <label class="x-choice">
                                <input type="radio" name="mode" value="codes">
                                <span><strong>Numeric codes</strong>
                                    <span>The model&rsquo;s codes, and one 1/0 column per checkbox option.</span></span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="x-group">
                        <legend>Date range</legend>
                        <p class="x-hint">Leave both dates empty to export every record.</p>
                        <div class="x-dates">
                            <div>
                                <label for="xFrom">From</label>
                                <input type="date" class="form-control" id="xFrom" name="from" min="<?= e((string) $first) ?>" max="<?= e($today) ?>">
                            </div>
                            <div>
                                <label for="xTo">To</label>
                                <input type="date" class="form-control" id="xTo" name="to" min="<?= e((string) $first) ?>" max="<?= e($today) ?>">
                            </div>
                        </div>
                        <div class="x-presets">
                            <button type="button" class="x-chip" data-days="all">All records</button>
                            <button type="button" class="x-chip" data-days="0">Today</button>
                            <button type="button" class="x-chip" data-days="6">Last 7 days</button>
                            <button type="button" class="x-chip" data-days="29">Last 30 days</button>
                        </div>
                    </fieldset>

                    <div class="x-footer">
                        <p class="x-count" id="xCount" aria-live="polite"><strong><?= $total ?></strong> record<?= $total === 1 ? '' : 's' ?> will be exported</p>
                        <button type="submit" class="btn btn-pvet" id="xDownload">Download file</button>
                    </div>
                </form>
            </div>

            <div class="col-12 col-lg-5">
                <div class="x-panel">
                    <h2>What is in the file</h2>
                    <ul class="x-facts">
                        <li><span>Records in the system</span><b><?= $total ?></b></li>
                        <li><span>Date of the first record</span><b><?= e(date('M j, Y', strtotime((string) $first))) ?></b></li>
                        <li><span>Date of the latest record</span><b><?= e(date('M j, Y', strtotime((string) $last))) ?></b></li>
                        <li><span>Columns (readable text)</span><b><?= $columnCount ?></b></li>
                        <li><span>Columns (numeric codes)</span><b><?= $codeColumns ?></b></li>
                    </ul>
                    <p class="x-note">
                        Each row is one assessment: reference number, date and time, farmer group (cluster), then every
                        answer from the questionnaire. The file contains farmer information, so keep it safe.
                    </p>
                </div>
            </div>
        </div>

    <?php endif; ?>

    </div>
</section>

<?php if ($pdo && $total > 0): ?>
<script>
(function () {
    'use strict';

    var form = document.getElementById('exportForm');
    var from = document.getElementById('xFrom');
    var to = document.getElementById('xTo');
    var countEl = document.getElementById('xCount');
    var btn = document.getElementById('xDownload');
    var today = <?= json_encode($today) ?>;
    var first = <?= json_encode((string) $first) ?>;
    var requestId = 0;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function shift(dateStr, days) {            // dateStr - days, as YYYY-MM-DD
        var p = dateStr.split('-');
        var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
        d.setUTCDate(d.getUTCDate() - days);
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
    }

    function showCount(n) {
        countEl.textContent = '';
        var strong = document.createElement('strong');
        strong.textContent = n;
        countEl.appendChild(strong);
        countEl.appendChild(document.createTextNode(' record' + (n === 1 ? '' : 's') + ' will be exported'));
        btn.disabled = (n === 0);
    }

    function updateCount() {
        var id = ++requestId;
        var qs = 'count=1&from=' + encodeURIComponent(from.value) + '&to=' + encodeURIComponent(to.value);
        fetch('export.php?' + qs, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('bad'); return r.json(); })
            .then(function (d) { if (id === requestId) showCount(d.count); })
            .catch(function () { if (id === requestId) countEl.textContent = 'The number of records could not be checked.'; });
    }

    from.addEventListener('change', updateCount);
    to.addEventListener('change', updateCount);

    document.querySelectorAll('.x-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var days = chip.getAttribute('data-days');
            if (days === 'all') {
                from.value = '';
                to.value = '';
            } else {
                from.value = shift(today, parseInt(days, 10));
                to.value = today;
            }
            updateCount();
        });
    });

    form.addEventListener('submit', function () {
        if (from.value && to.value && from.value > to.value) {   // swapped dates: the server fixes it too
            var t = from.value; from.value = to.value; to.value = t;
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>