<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/ml/cluster.php';
require_once __DIR__ . '/ml/suggestions.php';

$pageTitle  = 'Assessment';
$activePage = 'assessment';

$steps       = assessment_steps();
$reviewStep  = count($steps) + 1;
$errors      = [];
$old         = [];
$formError   = null;
$startStep   = 1;
$submittedRef = null;
$submittedCluster = null;

// One-time confirmation after a successful submission (post/redirect/get).
if (!empty($_SESSION['submitted_ref'])) {
    $submittedRef = (string) $_SESSION['submitted_ref'];
    $submittedCluster = $_SESSION['submitted_cluster'] ?? null;
    unset($_SESSION['submitted_ref'], $_SESSION['submitted_cluster']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $formError = 'Your session has expired. Please review your answers and submit again.';
        $old       = validate_assessment($_POST)['old'];
        $startStep = $reviewStep;
    } else {
        $result = validate_assessment($_POST);
        $old    = $result['old'];
        $errors = $result['errors'];

        if ($errors) {
            $formError = 'Some questions need your attention. They are marked below.';
            $startStep = first_error_step($errors);
        } else {
            try {
                $id = save_assessment(db(), $result['data']);

                // Run the clustering model. A model problem must not lose the saved assessment.
                $cluster = null;
                try {
                    $prediction = predictCluster(model_input($result['data']));
                    if (!empty($prediction['ok'])) {
                        $cluster = (int) $prediction['cluster'];
                        $upd = db()->prepare('UPDATE `assessments` SET `cluster_id` = :c WHERE `id` = :id');
                        $upd->execute([':c' => $cluster, ':id' => $id]);
                    } else {
                        error_log('[Swine Farmer Services] Model error: ' . implode('; ', $prediction['errors'] ?? ['unknown']));
                    }
                } catch (Throwable $mx) {
                    error_log('[Swine Farmer Services] Model failed: ' . $mx->getMessage());
                }

                $_SESSION['submitted_ref']     = format_reference($id);
                $_SESSION['submitted_cluster'] = $cluster;
                header('Location: assessment.php');
                exit;
            } catch (Throwable $ex) {
                error_log('[Swine Farmer Services] Save failed: ' . $ex->getMessage());
                $formError = 'The assessment could not be saved. Check that MySQL is running in XAMPP and that the settings in config/database.php are correct, then submit again.';
                $startStep = $reviewStep;
            }
        }
    }
}

require __DIR__ . '/includes/sidebar.php';
?>
<link href="assets/css/assessment.css" rel="stylesheet">

<section class="page-head">
    <div class="page">
        <h1 class="page-title">Farmer &amp; Farm Assessment</h1>
        <p class="page-sub">
            Complete the sections below. Responses are recorded for research and for planning veterinary and agricultural services.
        </p>
    </div>
</section>

<section class="section section--tight">
    <div class="page">

<?php if ($submittedRef): ?>

        <?php $clusterInfo = $submittedCluster !== null ? cluster_info((int) $submittedCluster) : null; ?>

        <div class="panel confirmation">
            <h2>Assessment received</h2>
            <p>Your responses have been recorded.</p>
            <dl class="fact-list fact-list--inline">
                <div>
                    <dt>Reference number</dt>
                    <dd><?= e($submittedRef) ?></dd>
                </div>
                <?php if ($clusterInfo): ?>
                <div>
                    <dt>Farmer group</dt>
                    <dd>Cluster <?= (int) $submittedCluster ?></dd>
                </div>
                <?php endif; ?>
            </dl>

            <?php if ($clusterInfo): ?>
                <h3>Recommendation</h3>
                <?php foreach ($clusterInfo['paragraphs'] as $para): ?>
                    <p><?= e($para) ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Your farmer group could not be determined right now. Your answers are saved and can be reviewed later.</p>
            <?php endif; ?>

            <div class="hero-actions">
                <a href="assessment.php" class="btn btn-pvet">New assessment</a>
                <a href="home.php" class="text-link">Back to home</a>
            </div>
        </div>

<?php else: ?>

        <?php if ($formError): ?>
            <div class="notice notice--error" role="alert"><?= e($formError) ?></div>
        <?php endif; ?>

        <ol class="stepper" id="stepper">
            <?php foreach ($steps as $step): ?>
                <li>
                    <button type="button" class="stepper-item" data-goto="<?= (int) $step['id'] ?>">
                        <span class="s-num"><?= str_pad((string) $step['id'], 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="s-label"><?= e($step['title']) ?></span>
                    </button>
                </li>
            <?php endforeach; ?>
            <li>
                <button type="button" class="stepper-item" data-goto="<?= $reviewStep ?>">
                    <span class="s-num"><?= str_pad((string) $reviewStep, 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="s-label">Review</span>
                </button>
            </li>
        </ol>

        <form id="assessmentForm" class="assessment-form" method="post" action="assessment.php"
              data-start-step="<?= (int) $startStep ?>" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <?php foreach ($steps as $step): ?>
                <section class="form-step" data-step="<?= (int) $step['id'] ?>" data-title="<?= e($step['title']) ?>" hidden>
                    <header class="step-head">
                        <p class="step-count">Section <?= str_pad((string) $step['id'], 2, '0', STR_PAD_LEFT) ?> of <?= str_pad((string) count($steps), 2, '0', STR_PAD_LEFT) ?></p>
                        <h2><?= e($step['title']) ?></h2>
                        <p><?= e($step['intro']) ?></p>
                    </header>

                    <div class="row g-4">
                        <?php foreach ($step['fields'] as $field): ?>
                            <?php render_field($field, $old, $errors); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <section class="form-step" data-step="<?= $reviewStep ?>" data-title="Review Assessment" hidden>
                <header class="step-head">
                    <p class="step-count">Final step</p>
                    <h2>Review Assessment</h2>
                    <p>Check your answers before submitting. Use Edit to change any section.</p>
                </header>

                <div id="reviewSummary" class="review"></div>

                <div class="field consent<?= isset($errors['consent']) ? ' has-error' : '' ?>" data-name="consent" data-type="checkbox" data-required="1">
                    <label class="choice">
                        <input type="checkbox" name="consent" value="1"<?= !empty($old['consent']) ? ' checked' : '' ?>>
                        <span>I confirm that the information provided is accurate and may be used for research and service planning by the Provincial Veterinary Office.</span>
                    </label>
                    <div class="field-error" role="alert"><?= e($errors['consent'] ?? 'Confirmation is required before the assessment can be submitted.') ?></div>
                </div>
            </section>

            <div class="form-actions">
                <button type="button" class="btn btn-outline-pvet" id="btnBack">Back</button>
                <div class="form-actions-right">
                    <span class="step-status" id="stepStatus" aria-live="polite"></span>
                    <button type="button" class="btn btn-pvet" id="btnNext">Continue</button>
                    <button type="submit" class="btn btn-pvet" id="btnSubmit" hidden>Submit assessment</button>
                </div>
            </div>
        </form>

<?php endif; ?>

    </div>
</section>
<script src="assets/js/barangay.js" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>