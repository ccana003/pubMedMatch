<?php

/**
 * Manual PubMed sync endpoint.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

header('Content-Type: application/json');

try {
    $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
    if ($projectId < 1) {
        throw new RuntimeException('Invalid project_id.');
    }

    if (!defined('PROJECT_ID') || (int) PROJECT_ID !== $projectId) {
        throw new RuntimeException('Project context mismatch.');
    }

    if (!$module->canRunSync($projectId)) {
        http_response_code(403);
        throw new RuntimeException('You do not have permission to run PubMed sync.');
    }

    $result = $module->runPubMedIngestionWithResult($projectId);

    echo json_encode([
        'total_found' => (int) ($result['total_found'] ?? 0),
        'new_records' => (int) ($result['new_records'] ?? 0),
    ]);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }

    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
