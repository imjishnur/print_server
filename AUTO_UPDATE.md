# Auto-Update

The server can check GitHub for a new version and update itself automatically.

## How it works

1. **Version tracking** — the current installed version lives in the plain text
   file `VERSION` (e.g. `2.0.0`). This is what gets compared against GitHub.
2. **Checking** — the checker fetches `VERSION` straight from
   `https://raw.githubusercontent.com/<owner>/<repo>/<branch>/VERSION`. No
   GitHub API token needed, no rate-limit issues.
3. **Updating** — if the remote version is newer, the whole branch is
   downloaded as a zip, extracted to a temp folder, and only the files whose
   **content actually differs** (compared by SHA-256 hash) are applied.
   Files that are identical are left completely alone.
4. **Safety net per file**:
   - Every `.php` file is syntax-checked (`php -l`) *before* it's allowed to
     touch anything. If a file fails the check, it's skipped and logged —
     the rest of the update still proceeds.
   - The existing file is backed up to `backups/<timestamp>/...` before
     being replaced.
   - The swap is atomic: the new content is written to a temp file next to
     the target, then `rename()`d over it. There's never a moment where the
     file is half-written.
5. **`config.php` is never touched**, nor is anything else listed under
   `auto_update.exclude` in `config.php`. Add anything site-specific there.
6. If **any** file fails during an update, the local `VERSION` file is *not*
   bumped, so the next check retries automatically. Files that already
   updated successfully stay updated (re-applying them next time is a no-op
   since their hash will already match).

## Configuring it

Edit the `auto_update` block in `config.php`:

```php
'auto_update' => [
    'enabled'         => true,
    'check_interval'  => '1h',   // '10m', '30m', '1h', '2h', '1d' ... minimum 60s
    'github_owner'    => 'imjishnur',
    'github_repo'     => 'print_server',
    'branch'          => 'main',
    'exclude'         => ['config.php', '.git', 'backups', ...],
    'remove_deleted_files' => false,
    'backup_enabled'  => true,
    'max_backups'     => 10,
],
```

Set `enabled` to `false` to turn the whole feature off.

## How checks actually get triggered

There's no persistent background process for a plain PHP app running under
Apache/XAMPP, so checks are triggered two ways — use whichever fits how you
run the server:

- **Dashboard polling (default, no setup needed).** While `index.php` is
  open in a browser tab, it calls `check_update.php` in the background every
  `check_interval`. This never blocks the page or delays print jobs. There's
  also a "Check For Updates Now" button on the dashboard for an immediate
  manual check.
- **Windows Task Scheduler (recommended if the dashboard isn't always open).**
  Point a scheduled task at `check_update.bat` in this folder, with a repeat
  interval matching (or shorter than) `check_interval` — running it more
  often than the configured interval is harmless, it just reports
  `not_due` and exits.

  Example: Task Scheduler → Create Task → Trigger: "Repeat every 1 hour,
  indefinitely" → Action: `C:\path\to\print_server\check_update.bat`.

## Manual checks

- Browser: `http://<server-ip>/print_server_v2/check_update.php?force=1`
  (only reachable from the local network by default — see below).
- Command line: `php check_update.php --force`

## Logs & state

- `update.log` — every check and update action, timestamped.
- `.update_state.json` — last check time, last result, last error (used to
  drive the dashboard and the interval scheduling).
- `backups/<timestamp>/` — one folder per update run, containing the
  previous version of every file that was replaced. Pruned automatically,
  keeping the most recent `max_backups`.

## Access control

`check_update.php` only accepts web requests from `127.0.0.1`, `::1`, and
private LAN ranges (`10.x`, `192.168.x`, `172.16-31.x`) by default, so it
can't be triggered from the open internet even if the server is exposed.
Command-line invocation is always allowed.

## What it will NOT do

- It will never touch `config.php` or anything in `exclude`.
- It will never delete local files unless you explicitly set
  `remove_deleted_files` to `true`.
- It will never apply a `.php` file that fails a syntax check.
- It will never leave a partially-written file on disk (atomic rename only).
