<?php

/**
 * Step B survey endpoint: returns matched publications as JSON (read-only).
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

header('Content-Type: application/json');

$projectId = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
$identifier = trim((string) ($_GET['core_pubmatch_identifier'] ?? ''));
$surveyHash = trim((string) ($_GET['s'] ?? ''));
$sig = trim((string) ($_GET['cpm_sig'] ?? ''));

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
};

try {
    if ($projectId < 1) {
        throw new RuntimeException('Invalid project.');
    }

    if ($identifier === '') {
        throw new RuntimeException('Missing identifier.');
    }

    if ($surveyHash === '') {
        throw new RuntimeException('Missing survey hash.');
    }

    if (!$module->isValidPublicSurveySignature($projectId, $identifier, $sig)) {
        throw new RuntimeException('Invalid signature.');
    }

    $cards = $module->getSurveyCardsForIdentifier($projectId, $identifier);
    $respond(200, [
        'identifier' => $identifier,
        'matches' => $cards,
    ]);
} catch (Throwable $e) {
    $respond(400, ['error' => $e->getMessage()]);
}
