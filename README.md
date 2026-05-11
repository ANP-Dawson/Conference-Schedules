# Conference Schedules

A FreePBX module that schedules outbound dial-out conferences. At a configured
time the PBX originates calls to a participant list and bridges answered legs
into an existing conference room — useful for recurring standups, on-call
bridges, after-hours pages, and other meetings where the system should ring
the participants rather than have them dial in.

Both administrators and end users can manage schedules: admins via the
standard FreePBX admin GUI, end users via a UCP widget scoped to their own
schedules.

## Features

- **Flexible recurrence** — one-off, daily, weekly, monthly (specific day),
  monthly (Nth weekday, e.g. "first Tuesday"), quarterly, or a raw cron
  expression for arbitrary patterns.
- **Per-participant in-room filter** — when a schedule fires, participants
  already in the conference room are skipped (configurable per schedule).
- **Live fire preview** — the form shows the next five fire times in the
  schedule's timezone, with the correct daylight-saving abbreviation
  (e.g. `CDT` in summer, `CST` in winter) for each upcoming date.
- **Fire Now** — dispatches a schedule immediately, bypassing the cron.
  Useful for testing or ad-hoc all-hands calls.
- **Per-fire history** — every firing records per-leg AMI responses and any
  errors. Drill-down view available in both admin and UCP.
- **UCP widget** — end users create and edit their own schedules with the
  same form the admin uses, restricted to schedules they own.
- **Pre-signed** — the repo ships `module.sig` so FreePBX accepts the module
  as signed out of the box; no "Unsigned Module(s)" dashboard banner.

## Compatibility

| | |
| --- | --- |
| FreePBX | 16 LTS, 17 |
| PHP | 7.4+ |
| Asterisk | 18+ (`chan_pjsip` + `app_confbridge`) |
| MariaDB / MySQL | 10.x / 5.7+ (InnoDB, utf8mb4) |
| Conferences module | required |

## Install

### Debian / Ubuntu (vanilla FreePBX)

```bash
cd /var/www/html/admin/modules
sudo git clone https://github.com/ANP-Dawson/Conference-Schedules.git conferenceschedules
sudo chown -R asterisk:asterisk conferenceschedules

cd conferenceschedules
sudo -u asterisk composer install --no-dev

sudo fwconsole ma install conferenceschedules
sudo fwconsole reload
```

### PBXact / Sangoma Linux / CentOS

PBXact ships on a CentOS-derived Sangoma Linux base with SELinux usually
enforcing and without Composer pre-installed. The flow is otherwise the
same as upstream FreePBX:

```bash
# 1. Prerequisites — git only; PHP and unzip are already part of any
#    FreePBX install. (Do NOT install `php-cli` here — Sangoma's php56w /
#    php74-sng packages will conflict with stock php-cli and fail the
#    entire transaction.)
sudo yum install -y git

# 2. Composer (skip this step if /usr/local/bin/composer already exists)
curl -sS https://getcomposer.org/installer | sudo php -- \
    --install-dir=/usr/local/bin --filename=composer

# 3. Clone the module into FreePBX's modules directory
cd /var/www/html/admin/modules
sudo git clone https://github.com/ANP-Dawson/Conference-Schedules.git conferenceschedules
sudo chown -R asterisk:asterisk conferenceschedules

# 4. Install runtime dependencies (use the explicit path — `sudo -u asterisk`
#    strips PATH and won't find /usr/local/bin/composer otherwise)
cd conferenceschedules
sudo -u asterisk /usr/local/bin/composer install --no-dev

# 5. Restore SELinux contexts so Apache can serve the new files
sudo restorecon -Rv /var/www/html/admin/modules/conferenceschedules

# 6. Register with FreePBX, fix any ownership drift, and reload
sudo fwconsole ma install conferenceschedules
sudo fwconsole chown
sudo fwconsole reload
```

The extra `fwconsole chown` step cleans up any file ownership the `git clone`
may have set incorrectly — PBXact is strict about permissions under
`/var/www/html`. If `restorecon` is unavailable (SELinux disabled), skip
step 5.

> **PHP version note**: this module requires **PHP 7.4 or newer**. Older
> PBXact releases (PBXact 14 and earlier) ship PHP 5.6 (`php56w-*`
> packages) and won't get past `composer install` — you'll see a message
> like "*Your PHP version (5.6.x) does not satisfy that requirement*".
> Upgrade to PBXact 16 / FreePBX 16+ first.

### After install

The admin GUI lives under **Applications → Conference Schedules**. The UCP
widget shows up under **Add Widget → Conference Schedules** for any user
with UCP access.

To uninstall:

```bash
sudo fwconsole ma uninstall conferenceschedules
```

This drops the module's tables and removes its cron entry.

## How it works

1. A schedule has a name, a target conference room (from the standard
   Conferences module), a timezone, a recurrence pattern, and a list of
   participants. Participants are either FreePBX extensions or external
   phone numbers.
2. A per-minute cron entry (`fwconsole conferenceschedules:tick`) selects
   every enabled schedule whose next fire time has elapsed.
3. For each due schedule, the module checks who is currently in the
   conference room (via `confbridge list`) and skips any participant
   already present — or rings everyone, depending on the schedule's
   concurrency policy.
4. Remaining participants are dialed via AMI `Originate` against
   `Local/<value>@from-internal/n`, so outbound routes handle trunk
   selection for external numbers.
5. Answered legs are dropped into the conference room via the generated
   `app-cs-bridge` dialplan context, optionally playing an intro recording
   first.
6. A history row is written summarising the outcome of each leg
   (`success`, `partial`, `failed`, or `skipped`).

## Required AMI permissions

The FreePBX manager user needs the `originate` write permission in
`/etc/asterisk/manager.conf` — default FreePBX installs already grant this.
If you see `Permission denied` errors in the history view, confirm:

```ini
[admin]
read  = system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan,originate
write = system,call,agent,user,config,command,reporting,originate
```

## Dialplan customization

The module emits one context, `app-cs-bridge`:

```
[app-cs-bridge]
exten => s,1,NoOp(Conference Schedule bridge job=${CS_JOB_ID})
 same => n,Answer()
 same => n,Wait(1)
 same => n,ExecIf($["${CS_INTRO}" != ""]?Playback(${CS_INTRO}))
 same => n,Goto(from-internal,${CS_CONF_EXT},1)
exten => h,1,Hangup()
```

To inject custom behaviour (e.g. record a greeting before the conference,
play an announcement, log to CDR with extra fields), add to the
auto-included `[app-cs-bridge-custom]` context.

## Module signing

The repo ships a clearsigned `module.sig` (SHA-256 manifest of every shipped
file) and a public GPG key at `tools/signing-key.pub`. On install the
public key is imported into the `asterisk` user's GPG keyring and FreePBX's
verifier marks the module as signed — so the dashboard's
*"Unsigned Module(s)"* warning does not fire. End users have nothing
extra to do.

`.gitattributes` pins `* -text` so file bytes survive checkout on every
platform; without that a Windows clone could mangle line endings and
invalidate the manifest.

After editing module files, regenerate the signature on a host that has the
private key:

```bash
cd /var/www/html/admin/modules/conferenceschedules
sudo -u asterisk php tools/sign-module.php
```

## Troubleshooting

**A schedule didn't fire.** Open the History tab and check the latest
firing's per-leg responses. Legs marked `Skipped` with the message
"Already in conference" were intentionally not dialed by the in-room
filter — change the schedule's concurrency policy to *Ring everyone* if
that's not what you want. Otherwise check `/var/log/asterisk/full` for the
actual leg attempt.

**Next fire time looks wrong.** Recurrence rules are evaluated in the
*schedule's* timezone, not the system timezone. Inspect the row:

```sql
SELECT name, timezone, next_fire_utc FROM conferenceschedules_jobs;
```

`next_fire_utc` is always UTC. The fire-time preview in the form shows
the same time converted to the schedule's timezone with the correct DST
abbreviation.

**UCP changes don't show up.** UCP bundles every module's JS into a single
compiled file. Trigger a rebuild:

```bash
sudo -u asterisk php -r 'require "/etc/freepbx.conf"; FreePBX::Ucp()->refreshAssets();'
```

This runs automatically on `fwconsole ma install`, but is handy after a
manual file edit.

## Development

```bash
composer install
composer test       # phpunit
composer lint       # phpcs (PSR-12)
composer lint-fix   # phpcbf — auto-fix what's auto-fixable
```

An end-to-end test that bootstraps FreePBX and exercises every BMO method
against the live database lives at `tests/fixtures/bmo_smoke.php`:

```bash
sudo -u asterisk php tests/fixtures/bmo_smoke.php
```

## License

Apache-2.0. See [LICENSE](LICENSE).

> FreePBX framework is GPL-3.0. While this module is licensed Apache-2.0,
> any distribution that links it together with FreePBX produces a combined
> work whose effective terms are GPL-3.0 (Apache-2.0 → GPL-3.0 is one-way
> compatible). Source distribution of this module alone remains Apache-2.0.
