<?php

/**
 * Survey AJAX endpoint for CorePubMatch card UI.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

$action = trim((string) ($_GET['action'] ?? ''));
$projectId = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

http_response_code(200);
header('Content-Type: application/json');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
};

try {
    if ($projectId < 1) {
        throw new RuntimeException('Invalid project context.');
    }

    if (!defined('PROJECT_ID') || (int) PROJECT_ID !== $projectId) {
        throw new RuntimeException('Project context mismatch.');
    }

    if ($action === 'survey_matches') {
        $identifier = trim((string) ($_GET['core_pubmatch_identifier'] ?? ''));
        if ($identifier === '') {
            throw new RuntimeException('Missing identifier.');
        }

        $respond(200, [
            'matches' => $module->getSurveyMatches($projectId, $identifier),
        ]);
        return;
    }

    if ($action === 'save_survey_match') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid payload.');
        }

        $recordId = trim((string) ($decoded['record_id'] ?? ''));
        $instance = (int) ($decoded['instance'] ?? 0);
        $status = trim((string) ($decoded['status'] ?? ''));
        if ($recordId === '' || $instance < 1 || !in_array($status, ['0', '1', '2'], true)) {
            throw new RuntimeException('Invalid save parameters.');
        }

        $saveResult = $module->saveSurveyMatchStatus($projectId, $recordId, $instance, $status);
        if (!empty($saveResult['errors'])) {
            throw new RuntimeException(implode(' | ', $saveResult['errors']));
        }

        $respond(200, ['ok' => true]);
        return;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    $respond(400, ['error' => $e->getMessage()]);
}
