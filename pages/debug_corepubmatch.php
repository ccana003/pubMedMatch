<?php

/**
 * CorePubMatch diagnostics page.
 *
 * @var \UniversityofMiami\CorePubMatch\CorePubMatch $module
 */

$projectId = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
$logPath = dirname(__DIR__) . '/corepubmatch_debug.log';

header('Content-Type: text/plain; charset=utf-8');

echo "CorePubMatch Debug\n";
echo "==================\n";
echo 'Timestamp (UTC): ' . gmdate('Y-m-d H:i:s') . "\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
echo 'Project ID (pid): ' . $projectId . "\n";
echo 'PROJECT_ID constant: ' . (defined('PROJECT_ID') ? (string) PROJECT_ID : 'not defined') . "\n";
echo 'SCRIPT_NAME: ' . (string) ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
echo 'REQUEST_URI: ' . (string) ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "\n";

if ($projectId < 1) {
    echo "ERROR: missing/invalid pid\n";
    exit;
}

if (!$module->canRunSync($projectId)) {
    echo "WARNING: user may not have project design/superuser rights; some checks may be limited.\n";
}

echo "Suggested survey AJAX test URLs:\n";
$base = $module->getUrl('pages/survey_ajax.php') . '&NOAUTH&pid=' . $projectId;
echo '- Load matches: ' . $base . '&action=survey_matches&core_pubmatch_identifier=<identifier>&core_pubmatch_debug=1' . "\n";
echo '- Save status: POST ' . $base . '&action=save_survey_match&core_pubmatch_debug=1' . "\n";
echo "\n";

echo "Recent debug log tail (corepubmatch_debug.log):\n";
echo "----------------------------------------------\n";
if (!is_file($logPath)) {
    echo "(no log file found yet)\n";
    exit;
}

$lines = @file($logPath, FILE_IGNORE_NEW_LINES);
if (!is_array($lines) || empty($lines)) {
    echo "(log file is empty)\n";
    exit;
}

$tail = array_slice($lines, -100);
echo implode("\n", $tail) . "\n";
