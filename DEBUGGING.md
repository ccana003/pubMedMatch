# CorePubMatch Debugging Checklist

If REDCap auto-disables the module during enable/disable, use this checklist to capture the **real fatal error** quickly.

## 1) Check REDCap External Module error logs

- Control Center → External Modules → CorePubMatch
- Look for module load errors (class load, hook signature mismatch, parse errors).

## 2) Check PHP/server logs

- Web server/PHP error log (`php_error.log`, Apache/Nginx error logs).
- Search for `core_pub_match` or `CorePubMatch`.

## 3) Validate module files on server

From the module directory:

```bash
php -l CorePubMatch.php
php -l pages/run_pubmed.php
```

If either command fails, fix syntax first and re-enable.

## 4) Confirm deployment path/version

- Ensure module folder name follows your installed version naming.
- Confirm `config.json` and `CorePubMatch.php` are from the same revision.

## 5) Re-test with minimal scope

- Enable module with only manual sync workflow (`Run PubMed Sync`) first.
- Once stable, add any survey/front-end customizations incrementally.

## Information to share when reporting issues

- REDCap version
- PHP version
- Exact fatal error line/message from logs
- Module commit hash deployed
