<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/ml/suggestions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();                       // only logged-in admins can view or delete records

$pageTitle  = 'Records';
$activePage = 'records';

/** "Oct 7, 2026, 3:45 PM" */
function record_date(string $value): string
{
    $t = strtotime($value);
    return $t ? date('M j, Y, g:i A', $t) : $value;
}

/** The answer a farmer gave for one question, as readable text. */
function record_answer(array $f, array $row): string
{
    $name = $f['name'];

    if ($f['type'] === 'checkbox') {
        $picked = [];
        foreach ($f['options'] as $key => $label) {
            if (!empty($row[$name . '_' . $key])) {
                $picked[] = $label;
            }
        }
        return $picked ? implode(', ', $picked) : 'None selected';
    }

    $value = $row[$name] ?? null;
    if ($value === null || $value === '') {
        return 'Not answered';
    }
    if ($f['type'] === 'number') {
        return $value . (!empty($f['unit']) ? ' ' . $f['unit'] : '');
    }
    if (isset($f['options'])) {
        return option_label($name, $value);
    }
    return (string) $value;
}

/** Small form with a Delete button (asks for confirmation first). */
function delete_form(int $id, string $q, int $page, string $class): void
{
    $ref = format_reference($id);
    echo '<form method="post" action="records.php" class="inline-form" '
        . 'onsubmit="return confirm(\'Delete ' . e($ref) . ' and all its answers? This cannot be undone.\');">';
    echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<input type="hidden" name="q" value="' . e($q) . '">';
    echo '<input type="hidden" name="page" value="' . $page . '">';
    echo '<button type="submit" class="' . e($class) . '">Delete</button>';
    echo '</form>';
}

/* ---------------------------------------------------------------- Delete */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $delId = (int) ($_POST['id'] ?? 0);
    $ref   = format_reference($delId);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $_SESSION['records_flash'] = ['error', 'Your session expired. Please try again.'];
    } elseif ($delId > 0) {
        try {
            $stmt = db()->prepare('DELETE FROM `assessments` WHERE `id` = :id');
            $stmt->execute([':id' => $delId]);
            $_SESSION['records_flash'] = $stmt->rowCount() > 0
                ? ['ok', $ref . ' was deleted.']
                : ['error', $ref . ' was not found.'];
        } catch (Throwable $ex) {
            error_log('[Swine Farmer Services] Delete failed: ' . $ex->getMessage());
            $_SESSION['records_flash'] = ['error', 'The record could not be deleted.'];
        }
    }

    $back = array_filter([
        'q'    => trim((string) ($_POST['q'] ?? '')),
        'page' => (int) ($_POST['page'] ?? 1) > 1 ? (int) $_POST['page'] : null,
    ], fn($v) => $v !== null && $v !== '');
    header('Location: records.php' . ($back ? '?' . http_build_query($back) : ''));
    exit;
}

/* ------------------------------------------------------------------ Data */
$flash = $_SESSION['records_flash'] ?? null;
unset($_SESSION['records_flash']);

$id      = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$q       = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 40);
$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$pdo    = try_db();
$rows   = [];
$total  = 0;
$pages  = 1;
$record = null;

if ($pdo) {
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM `assessments` WHERE `id` = :id');
            $stmt->execute([':id' => $id]);
            $record = $stmt->fetch() ?: null;
        } else {
            // Search by reference number: "SFS-00003", "00003" or "3" all work.
            $where  = '';
            $like   = null;
            if ($q !== '') {
                $digits = ltrim((string) preg_replace('/\D+/', '', $q), '0');
                if ($digits === '') {
                    $where = 'WHERE 1 = 0';
                } else {
                    $where = 'WHERE CAST(`id` AS CHAR) LIKE :q';
                    $like  = '%' . $digits . '%';
                }
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `assessments` {$where}");
            if ($like !== null) {
                $stmt->bindValue(':q', $like);
            }
            $stmt->execute();
            $total = (int) $stmt->fetchColumn();
            $pages = max(1, (int) ceil($total / $perPage));
            $page  = min($page, $pages);

            $stmt = $pdo->prepare(
                "SELECT id, created_at, municipality, barangay, cluster_id
                   FROM `assessments` {$where}
                  ORDER BY id DESC
                  LIMIT :n OFFSET :o"
            );
            if ($like !== null) {
                $stmt->bindValue(':q', $like);
            }
            $stmt->bindValue(':n', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':o', ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }
    } catch (Throwable $ex) {
        error_log('[Swine Farmer Services] Records failed: ' . $ex->getMessage());
        $pdo = null;
    }
}

$pagerQuery = $q !== '' ? '&q=' . urlencode($q) : '';

require __DIR__ . '/includes/sidebar.php';
?>
<link href="assets/css/record.css" rel="stylesheet">

<?php if ($id > 0): ?>

<!-- ===================== One assessment ===================== -->
<section class="page-head">
    <div class="page">
        <a href="records.php" class="back-link">&larr; All records</a>
        <div class="head-row">
            <div>
                <?php if ($record): ?>
                    <h1 class="page-title"><?= e(format_reference((int) $record['id'])) ?></h1>
                    <p class="page-sub">Submitted <?= e(record_date((string) $record['created_at'])) ?></p>
                <?php else: ?>
                    <h1 class="page-title">Record not found</h1>
                <?php endif; ?>
            </div>
            <?php if ($record): ?>
                <?php delete_form((int) $record['id'], '', 1, 'btn-delete'); ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section section--tight">
    <div class="page">
    <?php if (!$pdo): ?>
        <div class="notice notice--error">The records could not be loaded. Check that MySQL is running in XAMPP.</div>
    <?php elseif (!$record): ?>
        <p>There is no assessment with that reference number. <a href="records.php" class="text-link">Back to all records</a></p>
    <?php else: ?>

        <?php
            $cluster     = $record['cluster_id'] !== null ? (int) $record['cluster_id'] : null;
            $clusterInfo = $cluster !== null ? cluster_info($cluster) : null;
        ?>

        <div class="result-box">
            <dl class="facts">
                <div><dt>Reference number</dt><dd><?= e(format_reference((int) $record['id'])) ?></dd></div>
                <div><dt>Date submitted</dt><dd><?= e(record_date((string) $record['created_at'])) ?></dd></div>
                <div><dt>Farmer group</dt><dd><?= $cluster !== null ? 'Cluster ' . $cluster : 'Not determined' ?></dd></div>
            </dl>

            <?php if ($clusterInfo): ?>
                <h2>Recommendation</h2>
                <?php foreach ($clusterInfo['paragraphs'] as $para): ?>
                    <p><?= e($para) ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h2 class="answers-title">Answers</h2>
        <?php foreach (assessment_steps() as $step): ?>
            <div class="answer-block">
                <h3><?= e($step['title']) ?></h3>
                <dl>
                    <?php foreach ($step['fields'] as $f): ?>
                        <div class="answer-row">
                            <dt><?= e($f['label']) ?></dt>
                            <dd><?= e(record_answer($f, $record)) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
    </div>
</section>

<?php else: ?>

<!-- ===================== All records ===================== -->
<section class="page-head">
    <div class="page">
        <h1 class="page-title">Records</h1>
        <p class="page-sub">Every submitted assessment, newest first. Open one to see the answers.</p>
    </div>
</section>

<section class="section section--tight">
    <div class="page">
        <?php if ($flash): ?>
            <div class="notice<?= $flash[0] === 'error' ? ' notice--error' : '' ?>" role="status"><?= e($flash[1]) ?></div>
        <?php endif; ?>

        <form class="search" method="get" action="records.php" role="search">
            <label class="visually-hidden" for="q">Search reference number</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                   placeholder="Search reference number, e.g. SFS-00003" autocomplete="off">
            <button type="submit" class="btn btn-pvet">Search</button>
            <?php if ($q !== ''): ?>
                <a class="text-link" href="records.php">Clear</a>
            <?php endif; ?>
        </form>

    <?php if (!$pdo): ?>
        <div class="notice notice--error">The records could not be loaded. Check that MySQL is running in XAMPP.</div>
    <?php elseif (!$rows): ?>
        <div class="empty-box">
            <?php if ($q !== ''): ?>
                <p>No record matches &ldquo;<?= e($q) ?>&rdquo;.</p>
                <a href="records.php" class="text-link">Show all records</a>
            <?php else: ?>
                <p>No assessments have been submitted yet.</p>
                <a href="assessment.php" class="btn btn-pvet">Start an assessment</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="count">
            <?= $total ?> <?= $q !== '' ? 'match' . ($total === 1 ? '' : 'es') : 'assessment' . ($total === 1 ? '' : 's') ?>
        </p>

        <div class="table-wrap">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Reference number</th>
                        <th>Date submitted</th>
                        <th>Location</th>
                        <th>Farmer group</th>
                        <th><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="ref"><?= e(format_reference((int) $r['id'])) ?></td>
                            <td><?= e(record_date((string) $r['created_at'])) ?></td>
                            <td><?= e(option_label('municipality', $r['municipality'])) ?>, <?= e($r['barangay']) ?></td>
                            <td>
                                <?php if ($r['cluster_id'] !== null): ?>
                                    <span class="tag">Cluster <?= (int) $r['cluster_id'] ?></span>
                                <?php else: ?>
                                    <span class="tag tag--none">Not determined</span>
                                <?php endif; ?>
                            </td>
                            <td class="act">
                                <span class="row-actions">
                                    <a class="text-link" href="records.php?id=<?= (int) $r['id'] ?>">View answers</a>
                                    <?php delete_form((int) $r['id'], $q, $page, 'link-danger'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Pages">
                <?php if ($page > 1): ?>
                    <a class="text-link" href="records.php?page=<?= $page - 1 ?><?= $pagerQuery ?>">&larr; Newer</a>
                <?php else: ?><span></span><?php endif; ?>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="text-link" href="records.php?page=<?= $page + 1 ?><?= $pagerQuery ?>">Older &rarr;</a>
                <?php else: ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</section>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>