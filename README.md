# CorePubMatch

CorePubMatch is a REDCap External Module that performs **project-level PubMed publication matching** for configured investigators and a date range, then stores new publication metadata in project records for downstream adjudication.

## Features

- Manual, button-driven PubMed sync (no cron required, no custom JavaScript required).
- Pulls PMIDs from PubMed by investigator and publication date range.
- Deduplicates PMIDs across investigators.
- Skips PMIDs already present in REDCap.
- Saves new publications into REDCap fields:
  - `record_id`
  - `pmid`
  - `title`
  - `abstract`
  - `authors`
  - `journal`
  - `pub_year`
  - `status` (default `0`)

## Installation

1. Create a module directory under your REDCap External Modules path:
   ```
   <redcap-root>/modules/core_pub_match_v1.0.0/
   ```
2. Copy all files from this repository into that directory:
   - `config.json`
   - `CorePubMatch.php`
   - `pages/run_pubmed.php`
   - `js/pubmed.js`
3. In REDCap Control Center:
   - Go to **External Modules**.
   - Enable **CorePubMatch** at the system level.
4. In your target project:
   - Enable **CorePubMatch** for the project.

## Module Configuration (Project Settings)

After enabling the module for a project, configure these project settings:

- **Investigator names** (`investigator_names`, textarea): one investigator name per line.
- **Start date** (`start_date`, text): `YYYY-MM-DD` or `YYYY/MM/DD`.
- **End date** (`end_date`, text): `YYYY-MM-DD` or `YYYY/MM/DD`.
- **Enable cron** (`enable_cron`, checkbox): optional future-use flag.

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
5. Inserts only new records.

## Security Model

Manual sync is restricted to users with one of the following:

- REDCap `SUPER_USER`, or
- Project Design rights.

The endpoint also validates project context and rejects invalid project IDs.

## Data Model Expectations

This module expects these REDCap fields to exist in the target project:

- `record_id`
- `pmid`
- `title`
- `abstract`
- `authors`
- `journal`
- `pub_year`
- `status`

## Future Extensions

The code includes TODO markers for:

- PI notification workflow.
- Core adjudication UI.
- Scheduled cron execution.
- Email batching.
