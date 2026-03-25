<?php

/**
 * Manual PubMed sync endpoint.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

$respondJson = static function (int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
};

$redirectToProjectSetup = static function (int $projectId, string $message, string $type = 'info'): void {
    $url = APP_PATH_WEBROOT . 'ProjectSetup/index.php?pid=' . $projectId
        . '&core_pubmatch_status=' . rawurlencode($message)
        . '&core_pubmatch_status_type=' . rawurlencode($type);

    header('Location: ' . $url);
};

try {
    if ($projectId < 1) {
        throw new RuntimeException('Invalid project_id.');
    }

    if (!defined('PROJECT_ID') || (int) PROJECT_ID !== $projectId) {
        throw new RuntimeException('Project context mismatch.');
    }

    if (!$module->canRunSync($projectId)) {
        throw new RuntimeException('You do not have permission to run PubMed sync.');
    }

    $result = $module->runPubMedIngestionWithResult($projectId);

    $totalFound = (int) ($result['total_found'] ?? 0);
    $newRecords = (int) ($result['new_records'] ?? 0);
    $preparedRecords = (int) ($result['prepared_records'] ?? $newRecords);
    $debug = (array) ($result['debug'] ?? []);
    $saveErrors = (array) ($debug['save_errors'] ?? []);

    $statusMessage = "Done. {$newRecords} new records ({$totalFound} total found).";
    if ($preparedRecords > $newRecords) {
        $statusMessage .= " Prepared {$preparedRecords}; saved {$newRecords}.";
    }

    if (!empty($saveErrors)) {
        $statusMessage .= ' Save errors: ' . implode(' | ', array_map('strval', $saveErrors));
    }

    if (!empty($debug['error_stage']) && !empty($debug['fetch_error'])) {
        $statusMessage .= ' Fetch issue (' . $debug['error_stage'] . '): ' . (string) $debug['fetch_error'];
    }

    if ($wantsJson) {
        $respondJson(200, [
            'total_found' => $totalFound,
            'new_records' => $newRecords,
            'prepared_records' => $preparedRecords,
            'debug' => $debug,
        ]);
        return;
    }

    $redirectToProjectSetup($projectId, $statusMessage, 'info');
    return;
} catch (Throwable $e) {
    if ($wantsJson) {
        $respondJson(400, [
            'error' => $e->getMessage(),
        ]);
        return;
    }

    $redirectToProjectSetup($projectId, 'Error: ' . $e->getMessage(), 'error');
    return;
}
