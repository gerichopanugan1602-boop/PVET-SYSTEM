<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pageTitle  = 'Home';
$activePage = 'home';

$services = array_values(support_services());

require __DIR__ . '/includes/sidebar.php';
?>
<link href="assets/css/home.css" rel="stylesheet">

<!-- Title + video in the middle -->
<section class="home-top">
    <div class="page">
        <header class="home-intro">
            <p class="eyebrow">Provincial Veterinary Office</p>
            <h1 class="display-title">Better support starts with understanding the farmer.</h1>
            <p class="lead-text">
                A digital assessment system that helps identify swine farming conditions,
                service needs, and appropriate veterinary support.
            </p>
        </header>

        <div class="home-video">
            <video autoplay muted loop playsinline preload="auto" aria-hidden="true">
                <source src="assets/video/hero.mp4" type="video/mp4">
            </video>
        </div>
    </div>
</section>

<!-- How the system works -->
<section class="section">
    <div class="page">
        <h2 class="section-title">How the system works</h2>

        <div class="row g-4">
            <div class="col-12 col-md-4">
                <article class="process-item">
                    <span class="process-num">01</span>
                    <h3>Assessment</h3>
                    <p>Provide information about the farmer and farm.</p>
                </article>
            </div>
            <div class="col-12 col-md-4">
                <article class="process-item">
                    <span class="process-num">02</span>
                    <h3>Farmer Profile</h3>
                    <p>The system compares the answers with groups of similar farmers and finds the closest match.</p>
                </article>
            </div>
            <div class="col-12 col-md-4">
                <article class="process-item">
                    <span class="process-num">03</span>
                    <h3>Service Support</h3>
                    <p>Suggested services are shown based on the farmer's group, as a guide for the veterinary office.</p>
                </article>
            </div>
        </div>
    </div>
</section>

<!-- Types of support -->
<section class="section section--dark">
    <div class="page">
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <h2 class="section-title">Types of support the system can help identify</h2>
                <p class="muted-on-dark">
                    Services currently offered or coordinated by the veterinary office.
                </p>
            </div>
            <div class="col-12 col-lg-8">
                <ul class="support-list">
                    <?php foreach ($services as $i => $service): ?>
                        <li>
                            <span class="support-num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <span><?= e($service) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<script>
    /* Keep the video still for people whose device asks for less motion. */
    (function () {
        var v = document.querySelector('.home-video video');
        if (v && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            v.removeAttribute('autoplay');
            v.pause();
        }
    })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>