<?php

namespace UniversityofMiami\CorePubMatch;

use DateTime;
use ExternalModules\AbstractExternalModule;
use REDCap;

/**
 * CorePubMatch
 *
 * Project-level PubMed matching and adjudication module.
 */
class CorePubMatch extends AbstractExternalModule
{
    private const ESEARCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi';
    private const EFETCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi';

    /**
     * Inject a Run PubMed Sync button on Project Setup.
     */
    public function redcap_every_page_top($project_id = null): void
    {
        if (empty($project_id)) {
            return;
        }

        if (!$this->canRunSync($project_id)) {
            return;
        }

        if (!$this->isProjectSetupPage()) {
            return;
        }

        $runUrl = htmlspecialchars($this->getUrl('pages/run_pubmed.php') . '&project_id=' . (int) $project_id, ENT_QUOTES);

        $status = trim((string) ($_GET['core_pubmatch_status'] ?? 'Idle.'));
        $statusType = trim((string) ($_GET['core_pubmatch_status_type'] ?? 'info'));
        $statusColor = ($statusType === 'error') ? '#b00020' : '#555';
        $status = htmlspecialchars($status, ENT_QUOTES);

        echo <<<HTML
<div id="core-pubmatch-container" style="margin:15px 0;padding:12px;border:1px solid #d9d9d9;background:#fafafa;">
    <h4 style="margin-top:0;">CorePubMatch</h4>
    <form method="post" action="{$runUrl}" style="display:inline;">
        <button id="core-pubmatch-run" type="submit" class="btn btn-primary">Run PubMed Sync</button>
    </form>
    <span id="core-pubmatch-status" style="margin-left:10px;color:{$statusColor};">{$status}</span>
</div>
HTML;
    }

    /**
     * Run PubMed ingestion and return ingestion summary.
     */
    public function runPubMedIngestionWithResult(int $project_id): array
    {
        $investigatorList = (string) $this->getProjectSetting('investigator_names', $project_id);
        $startDate = $this->normalizeDate((string) $this->getProjectSetting('start_date', $project_id));
        $endDate = $this->normalizeDate((string) $this->getProjectSetting('end_date', $project_id));

        if ($investigatorList === '' || $startDate === null || $endDate === null) {
            throw new \RuntimeException('Project settings are incomplete. Configure investigator names and date range first.');
        }

        $investigators = $this->parseInvestigatorNames($investigatorList);
        if (empty($investigators)) {
            throw new \RuntimeException('No investigator names were found in module settings.');
        }

        $allPmids = [];
        foreach ($investigators as $name) {
            $query = sprintf('%s[Author] AND ("%s"[Date - Publication] : "%s"[Date - Publication])', $name, $startDate, $endDate);
            $allPmids = array_merge($allPmids, $this->searchPubMed($query));
        }

        $uniquePmids = array_values(array_unique($allPmids));
        $existingPmids = $this->getExistingPmids($project_id);
        $newPmids = array_values(array_diff($uniquePmids, $existingPmids));

        $records = [];
        if (!empty($newPmids)) {
            $records = $this->fetchDetails($newPmids);
            $this->saveRecords($project_id, $records);
        }

        // TODO: Add PI notification workflow.
        // TODO: Add Core adjudication UI workflow.
        // TODO: Add scheduled cron execution path.
        // TODO: Add outbound email batching.

        return [
            'total_found' => count($uniquePmids),
            'new_records' => count($records),
        ];
    }

    /**
     * Query PubMed ESearch for PMIDs.
     */
    public function searchPubMed(string $query): array
    {
        $url = self::ESEARCH_URL . '?' . http_build_query([
            'db' => 'pubmed',
            'retmode' => 'json',
            'retmax' => '500',
            'term' => $query,
        ]);

        $response = $this->httpGet($url);
        if ($response === null) {
            return [];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['esearchresult']['idlist'])) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $decoded['esearchresult']['idlist'])));
    }

    /**
     * Fetch PubMed metadata via EFetch XML.
     */
    public function fetchDetails(array $pmids): array
    {
        $pmids = array_values(array_unique(array_filter(array_map('trim', $pmids))));
        if (empty($pmids)) {
            return [];
        }

        $url = self::EFETCH_URL . '?' . http_build_query([
            'db' => 'pubmed',
            'retmode' => 'xml',
            'id' => implode(',', $pmids),
        ]);

        $xmlString = $this->httpGet($url);
        if ($xmlString === null) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        if ($xml === false || !isset($xml->PubmedArticle)) {
            return [];
        }

        $records = [];
        foreach ($xml->PubmedArticle as $article) {
            $citation = $article->MedlineCitation;
            $articleData = $citation->Article;

            $pmid = trim((string) $citation->PMID);
            if ($pmid === '') {
                continue;
            }

            $title = trim((string) $articleData->ArticleTitle);
            $abstract = '';
            if (isset($articleData->Abstract->AbstractText)) {
                $parts = [];
                foreach ($articleData->Abstract->AbstractText as $abstractNode) {
                    $parts[] = trim((string) $abstractNode);
                }
                $abstract = trim(implode(' ', $parts));
            }

            $authors = [];
            if (isset($articleData->AuthorList->Author)) {
                foreach ($articleData->AuthorList->Author as $author) {
                    $lastName = trim((string) $author->LastName);
                    $foreName = trim((string) $author->ForeName);
                    $collective = trim((string) $author->CollectiveName);

                    if ($collective !== '') {
                        $authors[] = $collective;
                    } elseif ($lastName !== '' || $foreName !== '') {
                        $authors[] = trim($lastName . ', ' . $foreName, ', ');
                    }
                }
            }

            $journal = trim((string) $articleData->Journal->Title);

            $pubYear = trim((string) $articleData->Journal->JournalIssue->PubDate->Year);
            if ($pubYear === '') {
                $medlineDate = trim((string) $articleData->Journal->JournalIssue->PubDate->MedlineDate);
                if (preg_match('/\b(\d{4})\b/', $medlineDate, $matches)) {
                    $pubYear = $matches[1];
                }
            }

            $records[] = [
                'pmid' => $pmid,
                'title' => $title,
                'abstract' => $abstract,
                'authors' => implode('; ', $authors),
                'journal' => $journal,
                'pub_year' => $pubYear,
            ];
        }

        return $records;
    }

    /**
     * Get existing PMIDs from REDCap project data.
     */
    public function getExistingPmids(int $project_id): array
    {
        $data = REDCap::getData([
            'project_id' => $project_id,
            'return_format' => 'array',
            'fields' => ['pmid'],
        ]);

        $pmids = [];
        foreach ($data as $record) {
            $value = trim((string) ($record['pmid'] ?? ''));
            if ($value !== '') {
                $pmids[] = $value;
            }
        }

        return array_values(array_unique($pmids));
    }

    /**
     * Save new publication records into REDCap.
     */
    public function saveRecords(int $project_id, array $records): array
    {
        if (empty($records)) {
            return [];
        }

        $payload = [];
        foreach ($records as $record) {
            $recordId = $this->generateRecordId($record['pmid']);
            $payload[] = [
                'record_id' => $recordId,
                'pmid' => $record['pmid'] ?? '',
                'title' => $record['title'] ?? '',
                'abstract' => $record['abstract'] ?? '',
                'authors' => $record['authors'] ?? '',
                'journal' => $record['journal'] ?? '',
                'pub_year' => $record['pub_year'] ?? '',
                'status' => '0',
            ];
        }

        return REDCap::saveData($project_id, 'array', $payload);
    }

    /**
     * Verify user has rights to run manual sync.
     */
    public function canRunSync(int $project_id): bool
    {
        if (defined('SUPER_USER') && SUPER_USER) {
            return true;
        }

        if (!class_exists('UserRights') || !method_exists('UserRights', 'getPrivileges')) {
            return false;
        }

        global $userid;
        $rights = \UserRights::getPrivileges($project_id, $userid);
        $design = $rights[$userid][$project_id]['design'] ?? '0';

        return (string) $design === '1';
    }

    /**
     * Build valid investigator names list.
     */
    private function parseInvestigatorNames(string $investigatorList): array
    {
        $names = preg_split('/\r\n|\r|\n/', $investigatorList) ?: [];

        $cleaned = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $cleaned[] = preg_replace('/\s+/', ' ', $name);
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Accept YYYY-MM-DD or YYYY/MM/DD and normalize for PubMed query.
     */
    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $formats = ['Y-m-d', 'Y/m/d'];
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $date);
            if ($parsed instanceof DateTime) {
                return $parsed->format('Y/m/d');
            }
        }

        return null;
    }

    /**
     * Lightweight GET helper.
     */
    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => "Accept: */*\r\nUser-Agent: CorePubMatch-REDCap-EM\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }

    /**
     * Make deterministic REDCap record IDs from PMID.
     */
    private function generateRecordId(string $pmid): string
    {
        return 'PMID-' . preg_replace('/[^0-9A-Za-z_-]/', '', $pmid);
    }

    /**
     * Check current page path for Project Setup.
     */
    private function isProjectSetupPage(): bool
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        return (strpos($scriptName, '/ProjectSetup/index.php') !== false);
    }
}
