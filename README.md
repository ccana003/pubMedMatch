# CorePubMatch

CorePubMatch is a REDCap External Module that performs **project-level PubMed publication matching** for configured investigators and a date range, then stores new publication metadata in project records for downstream adjudication.

## Features

- Manual, button-driven PubMed sync (no cron required, no custom JavaScript required).
- Pulls PMIDs from PubMed by investigator and publication date range.
- Deduplicates PMIDs across investigators.
- Skips PMIDs already present in REDCap.
- Carries forward matched investigator metadata to each saved record (`pi_name`, `pi_email`) based on the investigator line that produced the PMID match.
- Saves new publications into REDCap fields:
  - `record_id`
  - `pmid`
  - `title`
  - `abstract`
  - `authors`
  - `journal`
  - `pub_year`
  - `status` (default `0`)
- Enriches verification contacts when possible:
  - Extracts `NCT########` trial IDs from PubMed title/abstract/databank metadata.
  - Looks up contact emails from ClinicalTrials.gov when NCT IDs are present.
  - Falls back to corresponding-author email parsed from PubMed affiliations.

## Installation

1. Create a module directory under your REDCap External Modules path:
   ```
   <redcap-root>/modules/core_pub_match_v1.0.0/
   ```
2. Copy all files from this repository into that directory:
   - `config.json`
   - `CorePubMatch.php`
   - `ajax.php`
   - `survey_matches.php`
   - `pages/run_pubmed.php`
   - `pages/survey_matches.php`
   - `pages/survey_match_view.php`
   - `js/pubmed.js`
   - `js/survey_stepb.js`
   - `DEBUGGING.md`
3. In REDCap Control Center:
   - Go to **External Modules**.
   - Enable **CorePubMatch** at the system level.
4. In your target project:
   - Enable **CorePubMatch** for the project.

## Module Configuration (Project Settings)

After enabling the module for a project, configure these project settings:

- **Investigator entries** (`investigator_names`, textarea): one investigator per line in either format:
  - `Full Name`
  - `Full Name, email@example.org`
- **Start date** (`start_date`, text): `YYYY-MM-DD` or `YYYY/MM/DD`.
- **End date** (`end_date`, text): `YYYY-MM-DD` or `YYYY/MM/DD`.
- **Enable cron** (`enable_cron`, checkbox): optional future-use flag.
- **Public survey link secret** (`public_link_secret`, text): optional HMAC secret for public survey signature validation (`cpm_sig`).

## Running PubMed Sync

1. Open your REDCap project and go to **Project Setup**.
2. Locate the **CorePubMatch** panel, or open **External Modules** and click **Configure** for CorePubMatch.
3. Click **Run PubMed Sync**.
4. The page reloads back to Project Setup with a status message:
   - `Done. X new records (Y total found).`
   - If there are write or fetch issues, status includes diagnostics such as:
     - `Prepared P; saved X.`
     - `Fetch issue (efetch_http|efetch_xml_parse): ...`
     - `Save errors: ...`
   - `Error: ...` (if an issue occurs)

If rendering on Project Setup fails unexpectedly, CorePubMatch now writes best-effort runtime diagnostics to:

- PHP `error_log`
- `<module-root>/corepubmatch_runtime.log`

## Survey Match View (Hook-independent)

To avoid survey-hook instability in some REDCap environments, you can render a
standalone match view page and pass the identifier in the URL:

```
.../modules/core_pub_match_v1.0.0/pages/survey_match_view.php?pid=<project_id>&core_pubmatch_identifier=<record_id_or_email_or_name>
```

This page is read-only and displays matched publication cards (title/authors/journal/year/PMID).

## Survey Step B (In-survey AJAX + PI review save)

If a public survey link includes:

- `core_pubmatch_identifier=<record_id_or_email_or_name>`
- `cpm_sig=<sha256_hmac(identifier, public_link_secret)>` (required only if secret is set)

CorePubMatch injects matched-publications cards into the survey and hides native survey fields. Data is loaded from:

`ajax.php` (`cpm_action=survey_matches`, NOAUTH, JSON).

Per-card save now writes:

- `is_mine`
- `pi_confidence`
- `is_core_related`
- `level_of_support`
- `pi_review_date`
- `status` = `2` (Ready for Core Review)

## Example Query Behavior

For each investigator name, the module constructs a PubMed query like:

```
{name}[Author] AND ("{start_date}"[Date - Publication] : "{end_date}"[Date - Publication])
```

Example:

```
Smith JA[Author] AND ("2020/01/01"[Date - Publication] : "2024/12/31"[Date - Publication])
```

The module then:

1. Combines PMIDs from all investigator queries.
2. Removes duplicates.
3. Removes PMIDs already in REDCap.
4. Fetches metadata via PubMed EFetch.
5. Attaches matched PI metadata (`pi_name`, `pi_email`) from the configured investigator entry used to find each PMID.
6. Groups publications by investigator and writes publication/review rows as repeating instances under one investigator-level `record_id`.

If EFetch GET requests fail in your hosting environment, the module automatically retries with POST.
If EFetch XML parsing fails, the module falls back to ESummary JSON metadata so records can still be inserted.

## Security Model

Manual sync is restricted to users with one of the following:

- REDCap `SUPER_USER`, or
- Project Design rights.

The endpoint also validates project context and rejects invalid project IDs.

## Data Model Expectations

This module expects these REDCap fields to exist in the target project:

- `record_id`
- `investigator_name` (recommended on a non-repeating parent/Main form)
- `investigator_email` (recommended on a non-repeating parent/Main form)
- `pmid`
- `title`
- `abstract`
- `authors`
- `journal`
- `pub_year`
- `status`
- `pi_name`
- `pi_email`

Expected repeating setup for investigator-centric workflows:

- `publications` should be configured as a repeating instrument (one instance per publication).
- `pi_review` should be repeating if you want one PI review row per publication.
- `core_review` can remain repeating for downstream core adjudication workflows, but this module no longer auto-populates `core_name`.

Optional fields for contact routing (if present) are auto-populated:

- `verify_contact_name`
- `verify_contact_email`
- `verify_contact_source`
- `verify_contact_confidence`
- `verify_contact_nct_id`

## Future Extensions

The code includes TODO markers for:

- PI notification workflow.
- Core adjudication UI.
- Scheduled cron execution.
- Email batching.
