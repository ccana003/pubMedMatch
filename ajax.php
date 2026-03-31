<?php

/**
 * CorePubMatch public AJAX endpoint.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

header('Content-Type: application/json');

$action = trim((string) ($_GET['cpm_action'] ?? ''));
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

    if ($action === 'survey_matches') {
        $matches = $module->getSurveyCardsForIdentifier($projectId, $identifier);
        $respond(200, [
            'identifier' => $identifier,
            'matches' => $matches,
        ]);
        return;
    }

    if ($action === 'save_review') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid save payload.');
        }

        $recordId = trim((string) ($decoded['record_id'] ?? ''));
        $instance = (int) ($decoded['instance'] ?? 0);
        $save = $module->saveSurveyReviewValues($projectId, $recordId, $instance, [
            'is_mine' => (string) ($decoded['is_mine'] ?? ''),
            'pi_confidence' => (string) ($decoded['pi_confidence'] ?? ''),
            'is_core_related' => (string) ($decoded['is_core_related'] ?? ''),
            'level_of_support' => (string) ($decoded['level_of_support'] ?? ''),
            'pi_review_date' => (string) ($decoded['pi_review_date'] ?? ''),
        ]);

        if (!empty($save['errors'])) {
            throw new RuntimeException(implode(' | ', $save['errors']));
        }

        $respond(200, ['success' => true]);
        return;
    }

    throw new RuntimeException('Invalid action.');
} catch (Throwable $e) {
    $respond(400, ['error' => $e->getMessage()]);
}
