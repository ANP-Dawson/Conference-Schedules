# Conference Schedules (`conferenceschedules`) — FreePBX module

Schedule outbound dial-out conferences. At a defined time, FreePBX originates calls to a
participant list and bridges answered legs into an existing conference room. Replaces
the cron + Asterisk call-file pattern with an AMI-driven, GUI-managed workflow that
records per-leg outcomes.

> **Status: Phase 1 (MVP).** Recurring schedules, per-schedule participants, AMI Originate,
> per-participant skip-if-in-room filter, and per-fire history are wired end-to-end.
> Retries, leader-wait, notify-email, and participant groups are deferred.

## Compatibility

| | |
| --- | --- |
| FreePBX | 16 LTS (primary), 17 (verified on 17.0.28 + Asterisk 22) |
| PHP | 7.4+ (tested on 8.2) |
| Asterisk | 18+ (chan_pjsip + app_confbridge) |
| MariaDB | 10.x (InnoDB, utf8mb4) |
| Conferences module | Required (this module references `meetme.exten`, not Conferences Pro) |

## What it does

1. **You** create a *schedule* in the GUI: name, conference room (existing one from
   the Conferences module), timezone, recurrence pattern (one-off / daily / weekly /
   monthly by day / monthly by Nth weekday / quarterly / custom cron), and a flat
   list of participants (each is either an internal extension or an external phone
   number).
2. A per-minute cron runs `fwconsole conferenceschedules:tick` which selects every
   enabled schedule whose `next_fire_utc` has elapsed.
3. For each due schedule, FreePBX checks who is currently in the conference room
   (via `confbridge list <exten>`) and **skips dialing any participant already
   present** when the schedule's concurrency policy is *Skip participants already
   in the conference* (the default). It then dispatches `Originate` AMI calls —
   one per remaining participant — to channel `Local/<value>@from-internal/n`
   against context `app-cs-bridge`. Outbound routes pick the trunk for external
   numbers. *Ring everyone* policy bypasses the skip and dials all participants.
4. Answered legs are dropped into the configured conference room via the generated
   dialplan. A history row records per-leg AMI responses (Success / Skipped / Error).
5. `next_fire_utc` is recomputed from the recurrence rule in the schedule's
   timezone.

The module also exposes a **Fire Now** button which bypasses the schedule and dispatches
immediately — useful for testing.

## Install

```bash
# 1. Drop the module into FreePBX's modules dir
cd /var/www/html/admin/modules
sudo git clone <repo-url> conferenceschedules
sudo chown -R asterisk:asterisk conferenceschedules

# 2. Install runtime dependencies
cd conferenceschedules
sudo -u asterisk composer install --no-dev

# 3. Register the module with FreePBX
sudo fwconsole ma install conferenceschedules
sudo fwconsole reload
```

After install, the GUI lives under **Applications → Conference Schedules**.

To uninstall:

```bash
sudo fwconsole ma uninstall conferenceschedules
```

This drops the five `conferenceschedules_*` tables and removes the per-minute cron line.

## Module signing

The repo ships a precomputed `module.sig` (clearsigned SHA-256 manifest of every
shipped file) and `tools/signing-key.pub` (the maintainer's public GPG key).
On first install, `install.php` imports the public key into the asterisk user's
GPG keyring. FreePBX's signature verifier then validates the bundled `module.sig`
against the imported key and the dashboard's *"Unsigned Module(s)"* notification
does **not** fire. End users have nothing extra to do — clone, install, done.

`.gitattributes` pins `* -text` so file bytes survive checkout on every platform;
without that, a Windows clone could mangle line endings and invalidate every
hash in `module.sig`.

### For the maintainer: re-sign after changes

After editing module files (or running `composer update`), regenerate the sig
on the FreePBX VM where the private key lives:

```bash
cd /var/www/html/admin/modules/conferenceschedules
sudo -u asterisk php tools/sign-module.php
git add module.sig
git commit -m "Re-sign module after <change>"
git push
```

If you ever lose the private key, generate a fresh one and replace
`tools/signing-key.pub` along with the new `module.sig`:

```bash
cat > /tmp/keygen.txt <<'EOF'
%no-protection
Key-Type: RSA
Key-Length: 4096
Key-Usage: sign
Name-Real: Conference Schedules Local
Name-Email: noreply@your-domain.local
Expire-Date: 0
%commit
EOF
sudo -u asterisk gpg --batch --gen-key /tmp/keygen.txt
rm /tmp/keygen.txt
sudo -u asterisk gpg --export --armor 'noreply@your-domain.local' \
    > tools/signing-key.pub
sudo -u asterisk php tools/sign-module.php
```

## Required AMI permissions

`Originate` requires the FreePBX manager user to have the `originate` write permission
in `/etc/asterisk/manager.conf`. Default FreePBX installs already grant this.

If you see `Permission denied` in history rows, check:

```ini
[admin]
read = system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan,originate
write = system,call,agent,user,config,command,reporting,originate
```

## Dialplan: `app-cs-bridge`

The module emits a single context that gets reloaded on every `fwconsole reload`:

```
[app-cs-bridge]
exten => s,1,NoOp(Conference Schedule bridge job=${CS_JOB_ID})
 same => n,Answer()
 same => n,Wait(1)
 same => n,ExecIf($["${CS_INTRO}" != ""]?Playback(${CS_INTRO}))
 same => n,Goto(from-internal,${CS_CONF_EXT},1)
exten => h,1,Hangup()
```

Customize behavior by adding to the auto-included `[app-cs-bridge-custom]` context.

## Database schema

| Table | Purpose |
| --- | --- |
| `conferenceschedules_jobs` | One row per schedule. Holds name, conference exten, tz, enabled flag, and `next_fire_utc`/`last_fire_utc`. (Table was named `_jobs` historically; `_schedules` is the term for the recurrence rules attached to each row.) |
| `conferenceschedules_schedules` | One or more recurrence-rule rows per schedule (`type` ∈ {recurring, oneoff, cron} + cron expression or our `@nth:` ordinal-weekday format). |
| `conferenceschedules_participants` | Flat list of participants per schedule. `kind` ∈ {extension, external} + `value` + sort order. |
| `conferenceschedules_options` | Per-schedule options (1:1): caller ID, wait time, intro recording, concurrency policy. |
| `conferenceschedules_history` | One row per fire. Status, JSON of per-leg AMI responses, error text. |

All times stored as UTC `DATETIME`; conversion happens at the edges (display in/out).

## Cron entry

Installed at the asterisk user's crontab:

```
* * * * * /usr/sbin/fwconsole conferenceschedules:tick > /dev/null 2>&1
```

You can run the tick manually (useful while debugging):

```bash
sudo fwconsole conferenceschedules:tick               # fire due schedules
sudo fwconsole conferenceschedules:tick --dry-run     # report due schedules without firing
```

## Development

```bash
composer install
composer test     # phpunit (validators + cron compile + tz handling)
composer lint     # phpcs PSR-12
composer lint-fix # phpcbf — auto-fix what's auto-fixable
```

The `tests/fixtures/bmo_smoke.php` script is a manual end-to-end test that bootstraps
FreePBX and exercises every BMO method against the live database. Run on the FreePBX
host:

```bash
sudo -u asterisk php tests/fixtures/bmo_smoke.php
```

It creates a temporary `meetme` row, runs through the full saveJob → recomputeNextFire
→ fireJob → history → deleteJob lifecycle, then cleans up. (Method names use "Job"
internally; the user-facing concept is "Schedule".) ~60 assertions.

### Repository layout

```
conferenceschedules/
├── Conferenceschedules.class.php   # BMO class — all data + dispatch logic
├── Console/
│   └── Conferenceschedules.class.php   # fwconsole conferenceschedules:tick
├── lib/
│   └── Validators.php              # pure validators (DB-free, framework-free)
├── views/
│   ├── jobs_list.php
│   ├── job_form.php
│   └── history.php
├── assets/
│   ├── js/conferenceschedules.js
│   └── css/conferenceschedules.css
├── tests/
│   ├── bootstrap.php               # PHPUnit bootstrap
│   ├── SmokeTest.php
│   ├── ValidatorsTest.php
│   └── fixtures/bmo_smoke.php      # manual end-to-end test
├── i18n/conferenceschedules.pot
├── install.php   # creates schema, registers cron
├── uninstall.php # drops schema, removes cron
├── functions.inc.php   # legacy hook surface (empty in Phase 1)
├── page.conferenceschedules.php    # GUI entry point / view router
├── module.xml
├── composer.json
├── phpcs.xml.dist
├── phpunit.xml.dist
├── README.md
└── LICENSE
```

## Troubleshooting

### Phones don't ring on Fire Now

1. Check `last_fire_utc` updated — confirms the BMO ran.
2. Open the History view; check the per-leg AMI responses. A leg shown as
   **Skipped** with "Already in conference" means our in-room filter spotted that
   participant in the room and intentionally didn't dial them — that's by design.
3. `Permission denied` → AMI `originate` write permission (see above).
4. `No such extension` → the participant value isn't dialable from `from-internal`.
5. Check `/var/log/asterisk/full` for the actual leg attempt.

### `next_fire_utc` is wrong

Recurrence rules are evaluated in the **schedule's** timezone, not the system tz.
Confirm `SELECT timezone, next_fire_utc FROM conferenceschedules_jobs;` and convert
to your local tz to verify.

### Schedule preview shows "Preview request failed"

The browser's request to `ajax.php` failed. Check:
1. The module is enabled (`fwconsole ma list | grep conferenceschedules`).
2. `ajaxRequest()` returns `true` for `preview-quick-recurring` (it does in Phase 1).
3. The browser's network tab — the response body usually carries the real error.

## License

Apache-2.0. See [LICENSE](LICENSE).

> **Note on combined works**: FreePBX framework is GPL-3.0. While this module is
> licensed Apache-2.0, runtime distributions that link with FreePBX form a combined
> work whose effective terms are GPL-3.0 (Apache-2.0 → GPL-3.0 is one-way
> compatible). Source distribution of just this module remains Apache-2.0.
