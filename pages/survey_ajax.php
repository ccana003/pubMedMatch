<?php

/**
 * Survey AJAX endpoint for CorePubMatch card UI.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

$action = trim((string) ($_GET['action'] ?? ''));
$projectId = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
$debugEnabled = isset($_GET['core_pubmatch_debug']) && (string) $_GET['core_pubmatch_debug'] === '1';

http_response_code(200);
header('Content-Type: application/json');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
};

$logDebug = static function (string $message, array $context = []) use ($projectId): void {
    $logPath = dirname(__DIR__) . '/corepubmatch_debug.log';
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ';
    $line .= 'pid=' . $projectId . ' ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context);
    }
    $line .= PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
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
        if ($debugEnabled) {
            $logDebug('survey_matches ok', ['identifier' => $identifier]);
        }
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
        if ($debugEnabled) {
            $logDebug('save_survey_match ok', ['record_id' => $recordId, 'instance' => $instance, 'status' => $status]);
        }
        return;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    $logDebug('survey_ajax error', [
        'action' => $action,
        'message' => $e->getMessage(),
        'query' => $_GET,
        'post' => $_POST,
    ]);
    $respond(400, ['error' => $e->getMessage()]);
}
