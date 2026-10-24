<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pageTitle  = 'Swine Health Guide';
$activePage = 'guide';

/* Edit the text below to match the advice your veterinary office gives. */
$warningSigns = [
    'Sudden death of one or more pigs',
    'High fever, shivering or pigs huddling together',
    'Loss of appetite or refusing to drink',
    'Red or purple blotches on the skin, especially ears, belly and legs',
    'Bleeding from the nose or in the stool, vomiting or diarrhea',
    'Abortions or stillbirths in sows',
];

$practices = [
    ['Limit who and what enters', 'Keep visitors, vehicles and outside equipment away from the pigs. Use a footbath or disinfectant at the pen entrance and change boots and clothes before handling pigs.'],
    ['Do not feed kitchen waste', 'Uncooked food waste and pork products can carry disease. Feed only safe, clean feed and clean water.'],
    ['Be careful with new pigs', 'Buy from sources you know. Keep new pigs apart from the herd for a period of time (ask the veterinary office how long) before mixing them.'],
    ['Keep pens clean', 'Clean and disinfect pens, feeders and tools regularly. Remove manure and wet bedding, and keep rats, flies and stray animals out.'],
    ['Fence your pigs', 'Keep pigs inside a secure pen so they cannot meet other pigs, wild boar or stray animals.'],
    ['Handle dead pigs safely', 'Do not sell, butcher or eat a pig that died of illness, and do not throw it in rivers or open areas. Ask the veterinary office how to dispose of it safely.'],
];

require __DIR__ . '/includes/sidebar.php';
?>
<link href="assets/css/guide.css" rel="stylesheet">

<section class="page-head">
    <div class="page">
        <h1 class="page-title">Swine health guide</h1>
        <p class="page-sub">Simple steps to keep your pigs healthy and what to do when something looks wrong.</p>
    </div>
</section>

<!-- Warning signs -->
<section class="section section--tight">
    <div class="page">
        <div class="g-alert">
            <h2>Call the veterinary office right away if you see:</h2>
            <ul class="g-signs">
                <?php foreach ($warningSigns as $sign): ?>
                    <li><?= e($sign) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="g-alert-note">Keep sick pigs separated and do not move or sell any pigs until you have been advised.</p>
        </div>
    </div>
</section>

<!-- Everyday practices -->
<section class="section section--tight">
    <div class="page">
        <h2 class="g-heading">Everyday protection for your farm</h2>

        <div class="row g-3">
            <?php foreach ($practices as $i => $p): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="g-card">
                        <span class="g-num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <h3><?= e($p[0]) ?></h3>
                        <p><?= e($p[1]) ?></p>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Call to action -->
<section class="section section--tight">
    <div class="page">
        <div class="g-cta">
            <div>
                <h2>Not sure what support you need?</h2>
                <p>Answer a few questions about your farm and see which services may help.</p>
            </div>
            <a href="assessment.php" class="btn btn-pvet">Start the assessment</a>
        </div>
        <p class="g-note">This guide gives general advice. For problems on your farm, follow the instructions of your veterinary office.</p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>