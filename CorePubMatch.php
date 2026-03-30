<?php

namespace UniversityofMiami\CorePubMatch;

use DateTime;
use ExternalModules\AbstractExternalModule;
use REDCap;
use Message;

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

        $investigatorEntries = $this->parseInvestigatorEntries($investigatorList);
        if (empty($investigatorEntries)) {
            throw new \RuntimeException('No investigator entries were found in module settings.');
        }

        $debug = [
            'investigator_count' => count($investigatorEntries),
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
            'notification_enabled' => false,
            'notification_subject' => null,
            'notification_intro' => null,
            'notification_record_ids' => [],
            'notifications' => [],
        ];

        $allPmids = [];
        $pmidToInvestigators = [];
        foreach ($investigatorEntries as $entry) {
            $name = $entry['name'];
            $query = sprintf('%s[Author] AND ("%s"[Date - Publication] : "%s"[Date - Publication])', $name, $startDate, $endDate);
            $pmids = $this->searchPubMed($query);
            foreach ($pmids as $pmid) {
                if (!isset($pmidToInvestigators[$pmid])) {
                    $pmidToInvestigators[$pmid] = [];
                }

                $entryKey = strtolower((string) ($entry['name'] ?? '')) . '|' . strtolower((string) ($entry['email'] ?? ''));
                if (!isset($pmidToInvestigators[$pmid][$entryKey])) {
                    $pmidToInvestigators[$pmid][$entryKey] = $entry;
                }
            }
            $allPmids = array_merge($allPmids, $pmids);
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
            $fetchResult = $this->fetchDetails($newPmids, array_column($investigatorEntries, 'name'));
            $fetchedRecords = $fetchResult['records'];
            $records = [];
            foreach ($fetchedRecords as $fetchedRecord) {
                $pmid = (string) ($fetchedRecord['pmid'] ?? '');
                $matchedInvestigators = $pmidToInvestigators[$pmid] ?? [];

                if (empty($matchedInvestigators)) {
                    $records[] = $fetchedRecord;
                    continue;
                }

                foreach ($matchedInvestigators as $matchedInvestigator) {
                    $record = $fetchedRecord;
                    $record['pi_name'] = is_array($matchedInvestigator) ? (string) ($matchedInvestigator['name'] ?? '') : '';
                    $record['pi_email'] = is_array($matchedInvestigator) ? (string) ($matchedInvestigator['email'] ?? '') : '';
                    $records[] = $record;
                }
            }

            $debug['fetched_records_count'] = count($records);
            $debug['fetch_error'] = $fetchResult['error'];
            if ($fetchResult['error'] !== null) {
                $debug['error_stage'] = $fetchResult['error_stage'];
            }

            $saveResult = $this->saveRecords($project_id, $records);
            $debug['prepared_records_count'] = count($records);
            $debug['saved_records_count'] = (int) ($saveResult['saved_count'] ?? 0);
            $debug['save_errors'] = $saveResult['errors'] ?? [];
            $debug['notification_record_ids'] = array_values(array_unique($saveResult['investigator_record_ids'] ?? []));

            if (!empty($debug['save_errors']) && $debug['error_stage'] === null) {
                $debug['error_stage'] = 'save_data';
            }
        }

        $notificationsEnabled = $this->getProjectSetting('enable_notifications', $project_id) === true
            || $this->getProjectSetting('enable_notifications', $project_id) === '1';
        $debug['notification_enabled'] = $notificationsEnabled;

        if ($notificationsEnabled && !empty($debug['notification_record_ids'])) {
            $subjectTemplate = trim((string) $this->getProjectSetting('email_subject_template', $project_id));
            if ($subjectTemplate === '') {
                $subjectTemplate = 'CorePubMatch publication review request';
            }
            $introTemplate = trim((string) $this->getProjectSetting('email_intro_template', $project_id));
            if ($introTemplate === '') {
                $introTemplate = 'Dear {{pi_name}},<br><br>The following publication records were added in the latest CorePubMatch sync and are ready for your review.';
            }
            $debug['notification_subject'] = $subjectTemplate;
            $debug['notification_intro'] = $introTemplate;

            $fieldMetadata = $this->getProjectFieldMetadata($project_id);
            $publicationForm = isset($fieldMetadata['pmid'])
                ? (string) ($fieldMetadata['pmid']['form_name'] ?? 'publications')
                : 'publications';
            $piReviewForm = isset($fieldMetadata['pi_name'])
                ? (string) ($fieldMetadata['pi_name']['form_name'] ?? 'pi_review')
                : 'pi_review';
            $piReviewLinkBase = $this->getPiReviewLinkBase($project_id, $piReviewForm);
            $notifications = $this->collectAndSendNotifications(
                $project_id,
                $debug['notification_record_ids'],
                $piReviewLinkBase,
                $publicationForm,
                $piReviewForm,
                $subjectTemplate,
                $introTemplate
            );
            $debug['notifications'] = $notifications;
        }

        // TODO: Add Core adjudication UI workflow.
        // TODO: Add scheduled cron execution path.

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
        $investigatorRecordIds = [];
        $fieldMetadata = $this->getProjectFieldMetadata($project_id);
        $repeatingInstruments = $this->getRepeatingInstruments($project_id);
        $coreNameDefault = trim((string) $this->getProjectSetting('core_name', $project_id));
        $optionalContactFieldMap = [
            'verification_contact_name' => 'verify_contact_name',
            'verification_contact_email' => 'verify_contact_email',
            'verification_contact_source' => 'verify_contact_source',
            'verification_contact_confidence' => 'verify_contact_confidence',
            'verification_contact_nct_id' => 'verify_contact_nct_id',
        ];
        $publicationForm = isset($fieldMetadata['pmid'])
            ? (string) ($fieldMetadata['pmid']['form_name'] ?? 'publications')
            : 'publications';
        $piReviewForm = isset($fieldMetadata['pi_name'])
            ? (string) ($fieldMetadata['pi_name']['form_name'] ?? 'pi_review')
            : 'pi_review';
        $coreReviewForm = isset($fieldMetadata['core_name'])
            ? (string) ($fieldMetadata['core_name']['form_name'] ?? 'core_review')
            : 'core_review';

        $grouped = [];
        foreach ($records as $record) {
            $piName = trim((string) ($record['pi_name'] ?? ''));
            $piEmail = strtolower(trim((string) ($record['pi_email'] ?? '')));
            $investigatorKey = $this->buildInvestigatorKey($piName, $piEmail);

            if (!isset($grouped[$investigatorKey])) {
                $grouped[$investigatorKey] = [
                    'name' => $piName,
                    'email' => $piEmail,
                    'records' => [],
                ];
            }

            $grouped[$investigatorKey]['records'][] = $record;
        }

        foreach ($grouped as $group) {
            $recordId = $this->generateInvestigatorRecordId($group['name'], $group['email']);
            $investigatorRecordIds[] = $recordId;
            $baseRow = ['record_id' => $recordId];

            if (isset($fieldMetadata['investigator_name'])) {
                $baseRow['investigator_name'] = $group['name'];
            }
            if (isset($fieldMetadata['investigator_email'])) {
                $baseRow['investigator_email'] = $group['email'];
            }
            $baseRowIndex = count($payload);
            $payload[] = $baseRow;

            $instance = 1;
            foreach ($group['records'] as $record) {
                $publicationRow = [
                    'record_id' => $recordId,
                    'redcap_repeat_instrument' => $publicationForm,
                    'redcap_repeat_instance' => (string) $instance,
                    'pmid' => (string) ($record['pmid'] ?? ''),
                    'title' => (string) ($record['title'] ?? ''),
                    'abstract' => (string) ($record['abstract'] ?? ''),
                    'authors' => (string) ($record['authors'] ?? ''),
                    'journal' => (string) ($record['journal'] ?? ''),
                    'pub_year' => (string) ($record['pub_year'] ?? ''),
                    'status' => '0',
                ];
                $payload[] = $publicationRow;

                foreach ($optionalContactFieldMap as $recordKey => $redcapField) {
                    if (!isset($fieldMetadata[$redcapField])) {
                        continue;
                    }

                    $value = trim((string) ($record[$recordKey] ?? ''));
                    if ($value === '') {
                        continue;
                    }

                    $fieldForm = (string) ($fieldMetadata[$redcapField]['form_name'] ?? '');
                    if ($fieldForm === '' || $fieldForm === $publicationForm || isset($repeatingInstruments[$fieldForm])) {
                        if ($fieldForm === '' || $fieldForm === $publicationForm) {
                            $payload[count($payload) - 1][$redcapField] = $value;
                        } else {
                            $payload[] = [
                                'record_id' => $recordId,
                                'redcap_repeat_instrument' => $fieldForm,
                                'redcap_repeat_instance' => (string) $instance,
                                $redcapField => $value,
                            ];
                        }
                        continue;
                    }

                    $payload[$baseRowIndex][$redcapField] = $value;
                }

                if (isset($fieldMetadata['core_name']) && $coreNameDefault !== '' && $coreReviewForm !== '') {
                    $payload[] = [
                        'record_id' => $recordId,
                        'redcap_repeat_instrument' => $coreReviewForm,
                        'redcap_repeat_instance' => (string) $instance,
                        'core_name' => $coreNameDefault,
                    ];
                }

                if ((isset($fieldMetadata['pi_name']) || isset($fieldMetadata['pi_email'])) && isset($repeatingInstruments[$piReviewForm])) {
                    $piReviewRow = [
                        'record_id' => $recordId,
                        'redcap_repeat_instrument' => $piReviewForm,
                        'redcap_repeat_instance' => (string) $instance,
                    ];
                    if (isset($fieldMetadata['pi_name'])) {
                        $piReviewRow['pi_name'] = $group['name'];
                    }
                    if (isset($fieldMetadata['pi_email'])) {
                        $piReviewRow['pi_email'] = $group['email'];
                    }
                    $payload[] = $piReviewRow;
                }

                $instance++;
            }
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
            'investigator_record_ids' => array_values(array_unique($investigatorRecordIds)),
        ];
    }

    /**
     * Load publication rows for saved records and send one consolidated email per PI.
     */
    private function collectAndSendNotifications(
        int $project_id,
        array $recordIds,
        string $piReviewLinkBase,
        string $publicationForm,
        string $piReviewForm,
        string $subjectTemplate,
        string $introTemplate
    ): array {
        $recordIds = array_values(array_unique(array_filter(array_map('strval', $recordIds))));
        if (empty($recordIds)) {
            return [];
        }

        $data = REDCap::getData([
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => $recordIds,
        ]);

        $notifications = [];
        $recipientToIndex = [];

        foreach ($data as $recordId => $recordData) {
            $investigatorName = trim((string) ($recordData['investigator_name'] ?? ''));
            $investigatorEmail = strtolower(trim((string) ($recordData['investigator_email'] ?? '')));
            $piName = $investigatorName;
            $piEmail = $investigatorEmail;
            $publicationRows = [];

            $repeatInstances = $this->extractRepeatInstancesFromRecordData($recordData);
            foreach ($repeatInstances as $eventId => $forms) {
                if (!isset($forms[$publicationForm]) || !is_array($forms[$publicationForm])) {
                    continue;
                }

                $piReviewInstances = (isset($forms[$piReviewForm]) && is_array($forms[$piReviewForm]))
                    ? $forms[$piReviewForm]
                    : [];

                foreach ($forms[$publicationForm] as $instance => $publicationRow) {
                    if (!is_array($publicationRow)) {
                        continue;
                    }

                    $instanceNo = (int) $instance;
                    $piReviewRow = $piReviewInstances[$instanceNo] ?? $piReviewInstances[(string) $instanceNo] ?? [];
                    $rowPiName = trim((string) ($piReviewRow['pi_name'] ?? $publicationRow['pi_name'] ?? $recordData['pi_name'] ?? $investigatorName));
                    $rowPiEmail = strtolower(trim((string) ($piReviewRow['pi_email'] ?? $publicationRow['pi_email'] ?? $recordData['pi_email'] ?? $investigatorEmail)));

                    if ($rowPiName !== '') {
                        $piName = $rowPiName;
                    }
                    if ($rowPiEmail !== '') {
                        $piEmail = $rowPiEmail;
                    }

                    $publicationRows[] = [
                        'title' => (string) ($publicationRow['title'] ?? ''),
                        'authors' => (string) ($publicationRow['authors'] ?? ''),
                        'journal' => (string) ($publicationRow['journal'] ?? ''),
                        'pub_year' => (string) ($publicationRow['pub_year'] ?? ''),
                        'review_link' => $this->buildPiReviewLink($piReviewLinkBase, (string) $recordId, (string) $eventId, $piReviewForm, $instanceNo),
                    ];
                }
            }

            if (empty($publicationRows) || $piEmail === '' || filter_var($piEmail, FILTER_VALIDATE_EMAIL) === false) {
                $notifications[] = [
                    'record_id' => (string) $recordId,
                    'pi_name' => $piName,
                    'pi_email' => $piEmail,
                    'publication_count' => count($publicationRows),
                    'sent' => false,
                    'reason' => 'missing_publications_or_valid_email',
                ];
                continue;
            }

            if (isset($recipientToIndex[$piEmail])) {
                $index = $recipientToIndex[$piEmail];
                $notifications[$index]['publication_rows'] = array_merge($notifications[$index]['publication_rows'], $publicationRows);
                $notifications[$index]['record_ids'][] = (string) $recordId;
                continue;
            }

            $recipientToIndex[$piEmail] = count($notifications);
            $notifications[] = [
                'record_id' => (string) $recordId,
                'record_ids' => [(string) $recordId],
                'pi_name' => $piName,
                'pi_email' => $piEmail,
                'publication_rows' => $publicationRows,
            ];
        }

        foreach ($notifications as $index => $notification) {
            if (!isset($notification['publication_rows'])) {
                continue;
            }

            $piName = (string) ($notification['pi_name'] ?? '');
            $subject = str_replace('{{pi_name}}', $piName, $subjectTemplate);
            $intro = str_replace('{{pi_name}}', htmlspecialchars($piName, ENT_QUOTES), $introTemplate);
            $htmlBody = $intro . '<br><br>' . $this->buildConsolidatedPiEmailHtml($notification['publication_rows'], $piReviewLinkBase);
            $sendResult = $this->sendConsolidatedPiEmail((string) $notification['pi_email'], $piName, $subject, $htmlBody);

            $notifications[$index] = [
                'record_id' => (string) ($notification['record_id'] ?? ''),
                'record_ids' => $notification['record_ids'] ?? [],
                'pi_name' => $piName,
                'pi_email' => (string) ($notification['pi_email'] ?? ''),
                'publication_count' => count($notification['publication_rows']),
                'sent' => (bool) ($sendResult['sent'] ?? false),
                'send_path' => (string) ($sendResult['path'] ?? ''),
                'send_error' => (string) ($sendResult['error'] ?? ''),
            ];
        }

        return $notifications;
    }

    /**
     * Extract repeating data structure for classic and longitudinal getData payloads.
     */
    private function extractRepeatInstancesFromRecordData(array $recordData): array
    {
        if (isset($recordData['repeat_instances']) && is_array($recordData['repeat_instances'])) {
            return $recordData['repeat_instances'];
        }

        foreach ($recordData as $eventData) {
            if (is_array($eventData) && isset($eventData['repeat_instances']) && is_array($eventData['repeat_instances'])) {
                return $eventData['repeat_instances'];
            }
        }

        return [];
    }

    /**
     * Build consolidated PI review email HTML with all publication instances.
     */
    private function buildConsolidatedPiEmailHtml(array $publicationRows, string $reviewLinkBase): string
    {
        $rows = '';
        foreach ($publicationRows as $row) {
            $title = htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES);
            $authors = htmlspecialchars((string) ($row['authors'] ?? ''), ENT_QUOTES);
            $journal = htmlspecialchars((string) ($row['journal'] ?? ''), ENT_QUOTES);
            $pubYear = htmlspecialchars((string) ($row['pub_year'] ?? ''), ENT_QUOTES);
            $reviewLink = (string) ($row['review_link'] ?? '');
            $safeReviewLink = htmlspecialchars($reviewLink, ENT_QUOTES);
            $reviewAnchor = $reviewLink !== ''
                ? '<a href="' . $safeReviewLink . '" target="_blank" rel="noopener noreferrer">Open review</a>'
                : 'Unavailable';

            $rows .= '<tr>'
                . '<td style="border:1px solid #ddd;padding:8px;">' . $title . '</td>'
                . '<td style="border:1px solid #ddd;padding:8px;">' . $authors . '</td>'
                . '<td style="border:1px solid #ddd;padding:8px;">' . $journal . '</td>'
                . '<td style="border:1px solid #ddd;padding:8px;text-align:center;">' . $pubYear . '</td>'
                . '<td style="border:1px solid #ddd;padding:8px;">' . $reviewAnchor . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '<p>No publication rows were found for this sync run.</p>';
        }

        return '<table style="border-collapse:collapse;width:100%;">'
            . '<thead><tr>'
            . '<th style="border:1px solid #ddd;padding:8px;text-align:left;">Title</th>'
            . '<th style="border:1px solid #ddd;padding:8px;text-align:left;">Authors</th>'
            . '<th style="border:1px solid #ddd;padding:8px;text-align:left;">Journal</th>'
            . '<th style="border:1px solid #ddd;padding:8px;text-align:left;">Publication year</th>'
            . '<th style="border:1px solid #ddd;padding:8px;text-align:left;">PI review link</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p style="margin-top:12px;color:#666;font-size:12px;">Review links are scoped to each repeating pi_review instance in this run.</p>';
    }

    /**
     * Build PI review link for an explicit repeating instance.
     */
    private function buildPiReviewLink(string $reviewLinkBase, string $recordId, string $eventId, string $piReviewForm, int $instance): string
    {
        $instance = max(1, $instance);
        if ($reviewLinkBase === '') {
            return '';
        }

        $separator = (strpos($reviewLinkBase, '?') !== false) ? '&' : '?';
            return $reviewLinkBase
            . $separator . 'record=' . rawurlencode($recordId)
            . '&instrument=' . rawurlencode($piReviewForm)
            . '&event_id=' . rawurlencode($eventId)
            . '&instance=' . $instance;
    }

    /**
     * Resolve base survey link for pi_review form.
     */
    private function getPiReviewLinkBase(int $project_id, string $piReviewForm): string
    {
        if (method_exists('REDCap', 'getSurveyLink')) {
            try {
                $link = (string) REDCap::getSurveyLink('', $piReviewForm, null, null, null, $project_id);
                if ($link !== '') {
                    return $link;
                }
            } catch (\Throwable $e) {
                // Ignore and use fallback.
            }
        }

        return (string) APP_PATH_SURVEY_FULL;
    }

    /**
     * Send consolidated outbound notification email.
     */
    private function sendConsolidatedPiEmail(string $toEmail, string $piName, string $subject, string $htmlBody): array
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'sent' => false,
                'path' => 'validation',
                'error' => 'Invalid recipient email.',
            ];
        }

        $fromEmail = (defined('EMAIL_FROM') && is_string(EMAIL_FROM) && EMAIL_FROM !== '') ? EMAIL_FROM : 'no-reply@example.org';
        $fromName = 'CorePubMatch';

        if (class_exists('Message')) {
            $message = new Message();
            $message->setTo($toEmail);
            $message->setFrom($fromEmail);
            $message->setFromName($fromName);
            $message->setSubject($subject);
            $message->setBody($htmlBody);
            $message->set('isHtml', true);

            try {
                if ((bool) $message->send()) {
                    return [
                        'sent' => true,
                        'path' => 'Message',
                        'error' => '',
                    ];
                }
            } catch (\Throwable $e) {
                // Fall through to REDCap::email fallback.
            }
        }

        if (method_exists('REDCap', 'email')) {
            $sent = (bool) REDCap::email($toEmail, $fromEmail, $subject, $htmlBody, '', '', $fromName, true);
            return [
                'sent' => $sent,
                'path' => 'REDCap::email',
                'error' => $sent ? '' : 'REDCap::email returned false.',
            ];
        }

        return [
            'sent' => false,
            'path' => 'none',
            'error' => 'No compatible email sender found.',
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
    private function parseInvestigatorEntries(string $investigatorList): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $investigatorList) ?: [];
        $entries = [];

        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }

            $name = $row;
            $email = '';
            if (strpos($row, ',') !== false) {
                [$rawName, $rawEmail] = array_pad(explode(',', $row, 2), 2, '');
                $name = trim($rawName);
                $email = strtolower(trim($rawEmail));
            }

            $name = preg_replace('/\s+/', ' ', $name);
            if ($name === '') {
                continue;
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $email = '';
            }

            $entries[] = [
                'name' => $name,
                'email' => $email,
            ];
        }

        $unique = [];
        foreach ($entries as $entry) {
            $key = strtolower($entry['name']) . '|' . strtolower($entry['email']);
            if (!isset($unique[$key])) {
                $unique[$key] = $entry;
            }
        }

        return array_values($unique);
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
     * Make deterministic REDCap record IDs from investigator identity.
     */
    private function generateInvestigatorRecordId(string $name, string $email = ''): string
    {
        $normalizedName = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
        $normalizedEmail = strtolower(trim($email));
        $base = $normalizedName . '|' . $normalizedEmail;

        if ($base === '|') {
            return 'INV-' . substr(sha1((string) microtime(true)), 0, 12);
        }

        return 'INV-' . substr(sha1($base), 0, 12);
    }

    /**
     * Build a stable key for investigator grouping.
     */
    private function buildInvestigatorKey(string $name, string $email = ''): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '') . '|' . strtolower(trim($email));
    }

    /**
     * Get data dictionary field names indexed for quick lookups.
     */
    private function getProjectFieldMetadata(int $project_id): array
    {
        $dictionary = REDCap::getDataDictionary($project_id, 'array');
        if (!is_array($dictionary)) {
            return [];
        }

        $fields = [];
        foreach ($dictionary as $fieldName => $meta) {
            if (is_string($fieldName) && $fieldName !== '') {
                $fields[$fieldName] = is_array($meta) ? $meta : [];
            }
        }

        return $fields;
    }

    /**
     * Return repeating instrument names for the project keyed for lookup.
     */
    private function getRepeatingInstruments(int $project_id): array
    {
        if (!method_exists('\REDCap', 'getRepeatingFormsEvents')) {
            return [];
        }

        $raw = REDCap::getRepeatingFormsEvents($project_id);
        if (!is_array($raw)) {
            return [];
        }

        $forms = [];
        foreach ($raw as $eventData) {
            if (!is_array($eventData)) {
                continue;
            }

            $eventForms = $eventData['forms'] ?? [];
            if (!is_array($eventForms)) {
                continue;
            }

            foreach ($eventForms as $formKey => $formValue) {
                $candidates = [];

                // Some REDCap versions return indexed form names; others return associative form_name => metadata/label.
                if (is_string($formKey) && !is_numeric($formKey)) {
                    $candidates[] = $formKey;
                }
                if ((is_int($formKey) || ctype_digit((string) $formKey)) && is_string($formValue) && trim($formValue) !== '') {
                    $candidates[] = $formValue;
                } elseif (is_array($formValue) && isset($formValue['form_name'])) {
                    $candidates[] = (string) $formValue['form_name'];
                }

                foreach ($candidates as $candidate) {
                    $name = trim((string) $candidate);
                    if ($name !== '') {
                        $forms[$name] = true;
                    }
                }
            }
        }

        return $forms;
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
