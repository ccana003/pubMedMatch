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
    private const ESUMMARY_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi';
    private const CTGOV_STUDY_URL_TEMPLATE = 'https://clinicaltrials.gov/api/v2/studies/%s';

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

        $debug = [
            'investigator_count' => count($investigators),
            'pmids_found_total' => 0,
            'pmids_after_dedup' => 0,
            'existing_pmids_count' => 0,
            'new_pmids_count' => 0,
            'fetched_records_count' => 0,
            'prepared_records_count' => 0,
            'saved_records_count' => 0,
            'error_stage' => null,
            'save_errors' => [],
            'fetch_error' => null,
        ];

        $allPmids = [];
        foreach ($investigators as $name) {
            $query = sprintf('%s[Author] AND ("%s"[Date - Publication] : "%s"[Date - Publication])', $name, $startDate, $endDate);
            $allPmids = array_merge($allPmids, $this->searchPubMed($query));
        }

        $debug['pmids_found_total'] = count($allPmids);

        $uniquePmids = array_values(array_unique($allPmids));
        $debug['pmids_after_dedup'] = count($uniquePmids);

        $existingPmids = $this->getExistingPmids($project_id);
        $debug['existing_pmids_count'] = count($existingPmids);

        $newPmids = array_values(array_diff($uniquePmids, $existingPmids));
        $debug['new_pmids_count'] = count($newPmids);

        $records = [];
        if (!empty($newPmids)) {
            $fetchResult = $this->fetchDetails($newPmids, $investigators);
            $records = $fetchResult['records'];

            $debug['fetched_records_count'] = count($records);
            $debug['fetch_error'] = $fetchResult['error'];
            if ($fetchResult['error'] !== null) {
                $debug['error_stage'] = $fetchResult['error_stage'];
            }

            $saveResult = $this->saveRecords($project_id, $records);
            $debug['prepared_records_count'] = count($records);
            $debug['saved_records_count'] = (int) ($saveResult['saved_count'] ?? 0);
            $debug['save_errors'] = $saveResult['errors'] ?? [];

            if (!empty($debug['save_errors']) && $debug['error_stage'] === null) {
                $debug['error_stage'] = 'save_data';
            }
        }

        // TODO: Add PI notification workflow.
        // TODO: Add Core adjudication UI workflow.
        // TODO: Add scheduled cron execution path.
        // TODO: Add outbound email batching.

        return [
            'total_found' => count($uniquePmids),
            'new_records' => $debug['saved_records_count'],
            'prepared_records' => count($records),
            'debug' => $debug,
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

        $response = $this->httpRequest($url, 'GET');
        if ($response['body'] === null) {
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['esearchresult']['idlist'])) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $decoded['esearchresult']['idlist'])));
    }

    /**
     * Fetch PubMed metadata via EFetch XML.
     */
    public function fetchDetails(array $pmids, array $investigators = []): array
    {
        $pmids = array_values(array_unique(array_filter(array_map('trim', $pmids))));
        if (empty($pmids)) {
            return [
                'records' => [],
                'error' => null,
                'error_stage' => null,
            ];
        }

        $query = [
            'db' => 'pubmed',
            'retmode' => 'xml',
            'id' => implode(',', $pmids),
        ];

        // Try GET first, then POST fallback for environments that reject long querystrings.
        $getUrl = self::EFETCH_URL . '?' . http_build_query($query);
        $fetchResponse = $this->httpRequest($getUrl, 'GET');

        if ($fetchResponse['body'] === null) {
            $fetchResponse = $this->httpRequest(self::EFETCH_URL, 'POST', $query);
        }

        $xmlString = $fetchResponse['body'];
        if ($xmlString === null) {
            $status = $fetchResponse['status_code'] !== null ? ' (HTTP ' . $fetchResponse['status_code'] . ')' : '';
            $detail = $fetchResponse['error'] !== null ? ' ' . $fetchResponse['error'] : '';

            return [
                'records' => [],
                'error' => 'Unable to fetch publication details from PubMed EFetch.' . $status . $detail,
                'error_stage' => 'efetch_http',
            ];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        if ($xml === false || !isset($xml->PubmedArticle)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();

            $messages = [];
            foreach ($errors as $error) {
                $message = trim((string) $error->message);
                if ($message !== '') {
                    $messages[] = $message;
                }
            }

            $summaryFallback = $this->fetchSummaryDetails($pmids);
            if (empty($summaryFallback['records'])) {
                // Some environments return incomplete ESummary payloads for batches; retry per PMID.
                foreach ($pmids as $pmid) {
                    $single = $this->fetchSummaryDetails([$pmid]);
                    if (!empty($single['records'])) {
                        $summaryFallback['records'] = array_merge($summaryFallback['records'], $single['records']);
                    }
                }
            }

            if (!empty($summaryFallback['records'])) {
                return [
                    'records' => $summaryFallback['records'],
                    'error' => 'EFetch XML parse failed; used ESummary fallback for metadata.',
                    'error_stage' => 'esummary_fallback',
                ];
            }

            return [
                'records' => [],
                'error' => empty($messages)
                    ? 'PubMed EFetch returned unexpected XML.'
                    : 'PubMed EFetch XML parse error: ' . implode(' | ', $messages),
                'error_stage' => 'efetch_xml_parse',
            ];
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
            $correspondingEmail = '';
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

                    if ($correspondingEmail === '') {
                        $email = $this->extractEmailFromAuthorNode($author);
                        if ($email !== '') {
                            $correspondingEmail = $email;
                        }
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

            $nctIds = $this->extractNctIdsFromArticle($article, $title, $abstract);
            $contact = $this->resolveVerificationContact($nctIds, $investigators, $correspondingEmail);

            $records[] = [
                'pmid' => $pmid,
                'title' => $title,
                'abstract' => $abstract,
                'authors' => implode('; ', $authors),
                'journal' => $journal,
                'pub_year' => $pubYear,
                'verification_contact_name' => $contact['name'],
                'verification_contact_email' => $contact['email'],
                'verification_contact_source' => $contact['source'],
                'verification_contact_confidence' => $contact['confidence'],
                'verification_contact_nct_id' => $contact['nct_id'],
            ];
        }

        return [
            'records' => $records,
            'error' => null,
            'error_stage' => null,
        ];
    }

    /**
     * Fallback metadata retrieval using ESummary JSON.
     */
    private function fetchSummaryDetails(array $pmids): array
    {
        $query = [
            'db' => 'pubmed',
            'retmode' => 'json',
            'id' => implode(',', $pmids),
        ];

        $url = self::ESUMMARY_URL . '?' . http_build_query($query);
        $response = $this->httpRequest($url, 'GET');
        if ($response['body'] === null) {
            $response = $this->httpRequest(self::ESUMMARY_URL, 'POST', $query);
        }

        if ($response['body'] === null) {
            return [
                'records' => [],
            ];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['result']) || !is_array($decoded['result'])) {
            return [
                'records' => [],
            ];
        }

        $records = [];
        $result = $decoded['result'];
        $uids = isset($result['uids']) && is_array($result['uids']) ? $result['uids'] : [];
        if (empty($uids)) {
            foreach ($result as $key => $value) {
                if ($key === 'uids') {
                    continue;
                }
                if (is_array($value)) {
                    $uids[] = (string) $key;
                }
            }
        }

        foreach ($uids as $uid) {
            $entry = $result[$uid] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $pmid = trim((string) ($entry['uid'] ?? $uid));
            if ($pmid === '') {
                continue;
            }

            $authors = [];
            $entryAuthors = $entry['authors'] ?? [];
            if (is_array($entryAuthors)) {
                foreach ($entryAuthors as $author) {
                    if (is_array($author) && !empty($author['name'])) {
                        $authors[] = trim((string) $author['name']);
                    }
                }
            }

            $pubYear = '';
            $pubDate = trim((string) ($entry['pubdate'] ?? ''));
            if ($pubDate !== '' && preg_match('/\b(\d{4})\b/', $pubDate, $matches)) {
                $pubYear = $matches[1];
            }

            $records[] = [
                'pmid' => $pmid,
                'title' => trim((string) ($entry['title'] ?? $entry['sorttitle'] ?? '')),
                'abstract' => '',
                'authors' => implode('; ', $authors),
                'journal' => trim((string) ($entry['fulljournalname'] ?? $entry['source'] ?? '')),
                'pub_year' => $pubYear,
                'verification_contact_name' => '',
                'verification_contact_email' => '',
                'verification_contact_source' => '',
                'verification_contact_confidence' => '',
                'verification_contact_nct_id' => '',
            ];
        }

        return [
            'records' => $records,
        ];
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

        $pmids = $this->extractPmidsFromData($data);

        return array_values(array_unique($pmids));
    }

    /**
     * Save new publication records into REDCap.
     */
    public function saveRecords(int $project_id, array $records): array
    {
        if (empty($records)) {
            return [
                'saved_count' => 0,
                'errors' => [],
                'raw' => null,
            ];
        }

        $payload = [];
        $availableFields = $this->getProjectFieldNames($project_id);
        $optionalContactFieldMap = [
            'verification_contact_name' => 'verify_contact_name',
            'verification_contact_email' => 'verify_contact_email',
            'verification_contact_source' => 'verify_contact_source',
            'verification_contact_confidence' => 'verify_contact_confidence',
            'verification_contact_nct_id' => 'verify_contact_nct_id',
        ];

        foreach ($records as $record) {
            $recordId = $this->generateRecordId($record['pmid']);
            $row = [
                'record_id' => $recordId,
                'pmid' => $record['pmid'] ?? '',
                'title' => $record['title'] ?? '',
                'abstract' => $record['abstract'] ?? '',
                'authors' => $record['authors'] ?? '',
                'journal' => $record['journal'] ?? '',
                'pub_year' => $record['pub_year'] ?? '',
                'status' => '0',
            ];

            foreach ($optionalContactFieldMap as $recordKey => $redcapField) {
                if (isset($availableFields[$redcapField])) {
                    $row[$redcapField] = (string) ($record[$recordKey] ?? '');
                }
            }

            $payload[] = $row;
        }

        // Use JSON payload to keep row-oriented saves consistent across REDCap versions.
        $result = REDCap::saveData($project_id, 'json', json_encode($payload));

        $errors = [];
        $savedCount = count($payload);

        if (is_array($result)) {
            $rawErrors = $result['errors'] ?? [];
            if (is_string($rawErrors) && trim($rawErrors) !== '') {
                $errors[] = trim($rawErrors);
            } elseif (is_array($rawErrors)) {
                foreach ($rawErrors as $error) {
                    $message = trim((string) $error);
                    if ($message !== '') {
                        $errors[] = $message;
                    }
                }
            }

            if (isset($result['count']) && is_numeric($result['count'])) {
                $savedCount = (int) $result['count'];
            } elseif (isset($result['item_count']) && is_numeric($result['item_count'])) {
                $savedCount = (int) $result['item_count'];
            } elseif (isset($result['ids']) && is_array($result['ids'])) {
                $savedCount = count($result['ids']);
            } elseif (!empty($errors)) {
                $savedCount = 0;
            }
        }

        return [
            'saved_count' => $savedCount,
            'errors' => $errors,
            'raw' => $result,
        ];
    }

    /**
     * Recursively extract PMID values from REDCap getData array structures.
     */
    private function extractPmidsFromData(array $data): array
    {
        $pmids = [];

        $walk = static function ($value) use (&$walk, &$pmids): void {
            if (!is_array($value)) {
                return;
            }

            foreach ($value as $key => $nestedValue) {
                if ($key === 'pmid' && !is_array($nestedValue)) {
                    $pmid = trim((string) $nestedValue);
                    if ($pmid !== '') {
                        $pmids[] = $pmid;
                    }
                }

                if (is_array($nestedValue)) {
                    $walk($nestedValue);
                }
            }
        };

        $walk($data);

        return $pmids;
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
    private function httpRequest(string $url, string $method = 'GET', array $data = []): array
    {
        $method = strtoupper($method);
        $headers = "Accept: */*\r\nUser-Agent: CorePubMatch-REDCap-EM\r\n";
        $content = null;

        if ($method === 'POST') {
            $content = http_build_query($data);
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => 30,
                'ignore_errors' => true,
                'header' => $headers,
                'content' => $content,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $httpHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $statusCode = $this->extractHttpStatusCode($httpHeaders);
        $error = null;

        if ($response === false) {
            $error = error_get_last()['message'] ?? null;
        }

        return [
            'body' => $response === false ? null : $response,
            'status_code' => $statusCode,
            'error' => $error,
        ];
    }

    /**
     * Parse HTTP status from file_get_contents response headers.
     */
    private function extractHttpStatusCode(array $headers): ?int
    {
        if (empty($headers)) {
            return null;
        }

        $first = (string) $headers[0];
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $first, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Make deterministic REDCap record IDs from PMID.
     */
    private function generateRecordId(string $pmid): string
    {
        return 'PMID-' . preg_replace('/[^0-9A-Za-z_-]/', '', $pmid);
    }

    /**
     * Get data dictionary field names indexed for quick lookups.
     */
    private function getProjectFieldNames(int $project_id): array
    {
        $dictionary = REDCap::getDataDictionary($project_id, 'array');
        if (!is_array($dictionary)) {
            return [];
        }

        $fields = [];
        foreach ($dictionary as $fieldName => $meta) {
            if (is_string($fieldName) && $fieldName !== '') {
                $fields[$fieldName] = true;
            }
        }

        return $fields;
    }

    /**
     * Extract email from PubMed author affiliation block.
     */
    private function extractEmailFromAuthorNode(\SimpleXMLElement $author): string
    {
        if (!isset($author->AffiliationInfo)) {
            return '';
        }

        foreach ($author->AffiliationInfo as $affiliationInfo) {
            $affiliation = trim((string) $affiliationInfo->Affiliation);
            if ($affiliation === '') {
                continue;
            }

            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $affiliation, $matches)) {
                return strtolower(trim($matches[0]));
            }
        }

        return '';
    }

    /**
     * Extract trial NCT IDs from article metadata and text.
     */
    private function extractNctIdsFromArticle(\SimpleXMLElement $article, string $title, string $abstract): array
    {
        $candidates = [];
        $candidates = array_merge($candidates, $this->extractNctIdsFromText($title));
        $candidates = array_merge($candidates, $this->extractNctIdsFromText($abstract));

        if (isset($article->MedlineCitation->Article->DataBankList->DataBank)) {
            foreach ($article->MedlineCitation->Article->DataBankList->DataBank as $dataBank) {
                $bankName = strtolower(trim((string) $dataBank->DataBankName));
                if ($bankName !== 'clinicaltrials.gov') {
                    continue;
                }

                if (!isset($dataBank->AccessionNumberList->AccessionNumber)) {
                    continue;
                }

                foreach ($dataBank->AccessionNumberList->AccessionNumber as $accession) {
                    $candidates = array_merge($candidates, $this->extractNctIdsFromText((string) $accession));
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Extract normalized NCT IDs from text.
     */
    private function extractNctIdsFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\bNCT\d{8}\b/i', $text, $matches);
        if (empty($matches[0])) {
            return [];
        }

        $ids = [];
        foreach ($matches[0] as $match) {
            $ids[] = strtoupper(trim((string) $match));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Select verification contact from trial contacts, then PubMed fallback.
     */
    private function resolveVerificationContact(array $nctIds, array $investigators, string $pubmedEmail): array
    {
        foreach ($nctIds as $nctId) {
            $contact = $this->fetchClinicalTrialsContact($nctId, $investigators);
            if ($contact !== null && $contact['email'] !== '') {
                return [
                    'name' => $contact['name'],
                    'email' => $contact['email'],
                    'source' => $contact['source'],
                    'confidence' => (string) $contact['confidence'],
                    'nct_id' => $nctId,
                ];
            }
        }

        if ($pubmedEmail !== '') {
            return [
                'name' => '',
                'email' => $pubmedEmail,
                'source' => 'pubmed_affiliation',
                'confidence' => '0.60',
                'nct_id' => isset($nctIds[0]) ? (string) $nctIds[0] : '',
            ];
        }

        return [
            'name' => '',
            'email' => '',
            'source' => '',
            'confidence' => '',
            'nct_id' => isset($nctIds[0]) ? (string) $nctIds[0] : '',
        ];
    }

    /**
     * Retrieve trial contacts and rank them for verification outreach.
     */
    private function fetchClinicalTrialsContact(string $nctId, array $investigators): ?array
    {
        $url = sprintf(self::CTGOV_STUDY_URL_TEMPLATE, rawurlencode($nctId));
        $response = $this->httpRequest($url, 'GET');
        if ($response['body'] === null) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return null;
        }

        $candidates = [];
        $this->collectTrialContactCandidates($decoded, $candidates);
        if (empty($candidates)) {
            return null;
        }

        $best = null;
        foreach ($candidates as $candidate) {
            $score = $this->scoreContactCandidate($candidate, $investigators);
            if ($best === null || $score > $best['score']) {
                $best = [
                    'name' => $candidate['name'],
                    'email' => strtolower($candidate['email']),
                    'source' => $candidate['source'],
                    'score' => $score,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'name' => $best['name'],
            'email' => $best['email'],
            'source' => $best['source'],
            'confidence' => number_format(min(0.99, max(0.50, $best['score'] / 100)), 2, '.', ''),
        ];
    }

    /**
     * Recursively collect any name/email contacts in a trial payload.
     */
    private function collectTrialContactCandidates($value, array &$candidates, string $path = ''): void
    {
        if (!is_array($value)) {
            return;
        }

        $emailKeys = ['email', 'emailAddress'];
        $email = '';
        foreach ($emailKeys as $emailKey) {
            if (!empty($value[$emailKey]) && is_string($value[$emailKey])) {
                $email = trim($value[$emailKey]);
                break;
            }
        }

        if ($email !== '' && preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', $email)) {
            $name = '';
            if (!empty($value['name']) && is_string($value['name'])) {
                $name = trim($value['name']);
            } else {
                $firstName = !empty($value['firstName']) ? trim((string) $value['firstName']) : '';
                $lastName = !empty($value['lastName']) ? trim((string) $value['lastName']) : '';
                $name = trim($firstName . ' ' . $lastName);
            }

            $source = ($path === '') ? 'clinicaltrials_contact' : 'clinicaltrials_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($path));
            $candidates[] = [
                'name' => $name,
                'email' => $email,
                'source' => trim($source, '_'),
            ];
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $nextPath = $path === '' ? (string) $key : ($path . '.' . (string) $key);
                $this->collectTrialContactCandidates($nested, $candidates, $nextPath);
            }
        }
    }

    /**
     * Score candidate by source priority and investigator name match.
     */
    private function scoreContactCandidate(array $candidate, array $investigators): int
    {
        $score = 50;
        $source = strtolower((string) ($candidate['source'] ?? ''));
        $name = strtolower(trim((string) ($candidate['name'] ?? '')));

        if (strpos($source, 'overallcontact') !== false || strpos($source, 'centralcontact') !== false) {
            $score += 30;
        } elseif (strpos($source, 'overallofficial') !== false || strpos($source, 'official') !== false) {
            $score += 20;
        } elseif (strpos($source, 'location') !== false) {
            $score += 10;
        }

        if ($name !== '') {
            foreach ($investigators as $investigator) {
                $normalized = strtolower(preg_replace('/\s+/', ' ', trim((string) $investigator)));
                if ($normalized === '') {
                    continue;
                }

                if ($name === $normalized || strpos($name, $normalized) !== false || strpos($normalized, $name) !== false) {
                    $score += 30;
                    break;
                }
            }
        }

        return $score;
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
