<?php

/**
 * Standalone, hook-independent survey match viewer.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

$projectId = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
$identifier = trim((string) ($_GET['core_pubmatch_identifier'] ?? ''));

if ($projectId < 1) {
    http_response_code(400);
    echo 'Missing or invalid pid.';
    return;
}

header('Content-Type: text/html; charset=utf-8');

$cards = $module->getSurveyCardsForIdentifier($projectId, $identifier);
$identifierEscaped = htmlspecialchars($identifier, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CorePubMatch Survey View</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 16px; background: #f7f7f7; }
        .panel { max-width: 980px; margin: 0 auto; background: #fff; border: 1px solid #ddd; padding: 16px; border-radius: 6px; }
        .meta { color: #666; margin-bottom: 10px; }
        .card { border: 1px solid #d6d6d6; border-radius: 6px; background: #fafafa; padding: 12px; margin: 12px 0; }
        .title { font-size: 18px; font-weight: 600; margin: 0 0 6px; }
        .sub { color: #555; font-size: 13px; }
        .empty { border: 1px solid #ffd591; background: #fff7e6; border-radius: 6px; padding: 10px; }
    </style>
</head>
<body>
<div class="panel">
    <h2>Matched Publications</h2>
    <div class="meta">Identifier: <?= $identifierEscaped !== '' ? $identifierEscaped : '(none)' ?></div>

    <?php if (empty($cards)): ?>
        <div class="empty">No matches found for this identifier.</div>
    <?php else: ?>
        <?php foreach ($cards as $index => $card): ?>
            <?php
            $title = htmlspecialchars((string) ($card['title'] ?? ''), ENT_QUOTES);
            $authors = htmlspecialchars((string) ($card['authors'] ?? ''), ENT_QUOTES);
            $journal = htmlspecialchars((string) ($card['journal'] ?? ''), ENT_QUOTES);
            $pubYear = htmlspecialchars((string) ($card['pub_year'] ?? ''), ENT_QUOTES);
            $pmid = htmlspecialchars((string) ($card['pmid'] ?? ''), ENT_QUOTES);
            ?>
            <section class="card">
                <div class="sub">Publication <?= (int) $index + 1 ?></div>
                <div class="title"><?= $title !== '' ? $title : '(Untitled publication)' ?></div>
                <div class="sub"><?= $authors ?></div>
                <div class="sub">
                    <?= $journal ?>
                    <?= $pubYear !== '' ? ' (' . $pubYear . ')' : '' ?>
                    <?= $pmid !== '' ? ' • PMID: ' . $pmid : '' ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
