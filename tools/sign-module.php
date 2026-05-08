<?php

// SPDX-License-Identifier: Apache-2.0
//
// Sign this module with the asterisk user's local GPG key so FreePBX accepts
// it as "Locally Signed" instead of flagging "Unsigned Module(s)" on the
// dashboard. Run after install or whenever module files change:
//
//   sudo -u asterisk php tools/sign-module.php
//
// One-time setup if the asterisk user doesn't have a signing key yet:
//
//   cat > /tmp/keygen.txt <<'EOF'
//   %no-protection
//   Key-Type: RSA
//   Key-Length: 4096
//   Key-Usage: sign
//   Name-Real: <your name or org>
//   Name-Email: <your email>
//   Expire-Date: 0
//   %commit
//   EOF
//   sudo -u asterisk gpg --batch --gen-key /tmp/keygen.txt
//   rm /tmp/keygen.txt
//
// The asterisk user's keyring lives at /home/asterisk/.gnupg/. FreePBX's
// signature verifier reads from that keyring, so any key it holds is trusted
// for the purposes of marking a module signed.

declare(strict_types=1);

const EXCLUDED_PREFIXES = [
    '.git/',
    'tools/',
    'tests/',
    '.phpunit.result.cache',
];
const EXCLUDED_NAMES = [
    'module.sig',
    '.gitignore',
    '.DS_Store',
];

function discoverKey(): array
{
    $out = [];
    $code = 0;
    exec(
        'gpg --list-secret-keys --keyid-format=long --with-colons 2>/dev/null',
        $out,
        $code
    );
    if ($code !== 0 || !$out) {
        fwrite(STDERR, "No GPG signing key in this user's keyring. See header comments.\n");
        exit(1);
    }
    $keyId = null;
    $uid   = null;
    foreach ($out as $line) {
        $cols = explode(':', $line);
        if (!$keyId && $cols[0] === 'sec') {
            // Use the 16-char short form (last 16 chars of the fingerprint).
            $keyId = substr($cols[4], -16);
        }
        if ($keyId && !$uid && $cols[0] === 'uid') {
            $uid = $cols[9];
            break;
        }
    }
    if (!$keyId) {
        fwrite(STDERR, "Could not parse GPG key id from `gpg --list-secret-keys` output.\n");
        exit(1);
    }
    return ['keyid' => $keyId, 'uid' => $uid ?: 'Unknown'];
}

function shouldExclude(string $rel): bool
{
    foreach (EXCLUDED_PREFIXES as $p) {
        if (strncmp($rel, $p, strlen($p)) === 0) {
            return true;
        }
    }
    foreach (EXCLUDED_NAMES as $n) {
        if (basename($rel) === $n) {
            return true;
        }
    }
    return false;
}

$moduleDir = realpath(__DIR__ . '/..');
if ($moduleDir === false) {
    fwrite(STDERR, "Could not resolve module dir.\n");
    exit(1);
}
chdir($moduleDir);

$key = discoverKey();
echo "Signing as: {$key['uid']} (key {$key['keyid']})\n";

$entries = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($moduleDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = ltrim(
        str_replace('\\', '/', substr($file->getPathname(), strlen($moduleDir))),
        '/'
    );
    if (shouldExclude($rel)) {
        continue;
    }
    $entries[$rel] = hash_file('sha256', $file->getPathname());
}
ksort($entries, SORT_STRING);

$timestamp = sprintf('%.4f', microtime(true));

$payload  = ";################################################\n";
$payload .= ";#        FreePBX Module Signature File         #\n";
$payload .= ";################################################\n";
$payload .= ";# Do not alter the contents of this file!  If  #\n";
$payload .= ";# this file is tampered with, the module will  #\n";
$payload .= ";# fail validation and be marked as invalid!    #\n";
$payload .= ";################################################\n";
$payload .= "\n";
$payload .= "[config]\n";
$payload .= "version=1\n";
$payload .= "hash=sha256\n";
$payload .= "signedwith={$key['keyid']}\n";
$payload .= "signedby='{$key['uid']}'\n";
$payload .= "repo=local\n";
$payload .= "timestamp={$timestamp}\n";
$payload .= "[hashes]\n";
foreach ($entries as $f => $h) {
    $payload .= "$f = $h\n";
}

$payloadPath = tempnam(sys_get_temp_dir(), 'cs-sig-');
file_put_contents($payloadPath, $payload);

$sigPath = $moduleDir . '/module.sig';
@unlink($sigPath);  // gpg --output won't overwrite without --yes; be explicit.

$cmd = sprintf(
    'gpg --batch --yes --pinentry-mode loopback --clearsign '
    . '--digest-algo SHA256 --output %s %s 2>&1',
    escapeshellarg($sigPath),
    escapeshellarg($payloadPath)
);
$out  = [];
$ret  = 0;
exec($cmd, $out, $ret);
@unlink($payloadPath);

if ($ret !== 0) {
    fwrite(STDERR, "gpg --clearsign failed:\n" . implode("\n", $out) . "\n");
    exit(1);
}

echo "Wrote $sigPath (" . count($entries) . " files signed)\n";
echo "\nNext: trigger a FreePBX signature recheck so the dashboard notice clears:\n";
echo "  sudo fwconsole notifications --delete freepbx FW_UNSIGNED\n";
echo "  sudo fwconsole reload\n";
