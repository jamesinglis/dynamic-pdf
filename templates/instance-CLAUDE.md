# Dynamic PDF Instance

This repository is an Elevate certificate or poster campaign built from the shared
`dynamic-pdf` PHP 8.2+ generator (FPDF/FPDI). Campaign details, hostnames, deploy
branches and special procedures belong in this repo's tracked `CLAUDE.local.md`.
Claude Code loads that file automatically alongside this one. Keep those notes in
the instance repo; do not add `CLAUDE.local.md` to `.gitignore`.

## Core and campaign files

`CLAUDE.md` is a core file copied byte-identical from the upstream instance template.
Change shared behaviour in `dynamic-pdf`, release it, then sync the chosen tag into
this instance. Do not edit copied core files to make campaign-specific changes.

Core files: `index.php`, `helpers.php`, `callbacks.php`, `defaults.php`,
`expire-cache.php`, `test-links.php`, `rotate-keys.php`, `.htaccess`, `composer.json`,
`composer.lock`, `readme.md`, `cache/index.php`, `config-example.json`, `CLAUDE.md`.
The sync also merges `.gitignore` and writes `.dynamic-pdf-version.json`.

Instance-owned files: `config.json`, `config-override.json`, `custom-callbacks.php`,
`resources/`, `CLAUDE.local.md`, `.ddev/`, campaign scripts and instance tests.
Read the existing config and callbacks before changing them. Keep unrelated work.

From the upstream repo (normally a sibling named `dynamic-pdf`):

```bash
bin/sync-core --tag=1.1.1 /path/to/instance
bin/sync-core --check --tag=1.1.1 /path/to/instance
bin/sync-core --check --tag=1.1.1 --ref=DEPLOY_BRANCH /path/to/instance
```

Before the first sync, move existing `CLAUDE.md` notes to `CLAUDE.local.md` and stage
that file. If a global ignore excludes it, use `git add -f CLAUDE.local.md` for this
named notes file. The sync refuses to erase unmoved notes, including with `--force`.
Use the actual deploy branch from the instance notes; some campaigns deploy from
Bitbucket and some repos serve multiple apps from different branches.

## Development and PDF checks

Use DDEV for local certificate tests. Read `.ddev/config.yaml` and instance notes
for the hostname and ports; do not copy another campaign's URL. Use synthetic names
and amounts in requests. Inspect the rendered PDF for positioning, wrapping and
font coverage, including accented names and `&`, as well as plain ASCII names.
Run this repo's tests where present; the upstream core suite runs on server PHP 8.2
because macOS and Linux iconv transliteration differ.

Text stays UTF-8 through callbacks. Upper-case with `mb_strtoupper($input, 'UTF-8')`.
Never encode to Latin-1 or cp1252 in a callback: the core converts exactly once via
`pdf_text()` before measuring and drawing. Use multibyte-aware slicing for names.
Generate FPDF font definitions as cp1252 and inspect `$cw` widths for accent
coverage; a `$uv` map alone does not prove the font draws those letters.

For layout debugging, use an uncommitted `config-override.json` to disable caching
and show borders. Keep debug mode off in production. `test-links.php` is governed
by `expose_test_links` and `environments`; unlisted hosts require a configured key.
An unlocked test-links page currently includes config keys in its JavaScript, so
do not share keys, page dumps or donor data. Never copy recipient CSVs into a repo.

## Git and deployment

Review drift and stage named files only. Keep `vendor/` ignored; track `composer.lock`.
Do not commit email drafts, generated PDFs, development certificate keys or IDE state.
Keep `drift/pre-standardise` branches local. Before a push, show the commit list and
get James's explicit go-ahead. Obtain his go-ahead before each Cloudways pull or upload.

Follow the approved rollout plan and this instance's notes for deployment. Cloudways
pulls run sequentially on a server. Verify deployed file checksums; local git status
does not establish what production runs. After pulling dependency changes, run
`composer install --no-dev --optimize-autoloader` in the app's `public_html`.
For a release rollout, follow its cache-clear, Varnish-purge and HTTPS checks; verify
cache counts before and after `php expire-cache.php --all`, since its success message
does not establish that files were deleted. Enable force-HTTPS where off as approved
with that app's pull. Never rotate keys or change host config as incidental cleanup.

The core `.htaccess` protects `.json` and `.md` files that reach Apache, including
both instruction files. Cloudways nginx serves existing static PDFs and raw fonts
directly; do not rely on `.htaccess` to hide those files.
