# SSH Security Baseline Audit And Safe Hardening

## Purpose

Define the read-only SSH baseline audit and the staged, lockout-safe procedure
for future MyComputer VPS hardening. This document does not authorize or apply
server changes.

## Incident Context

The VPS reported many failed SSH login attempts since the prior successful
login. The server remained accessible and operational. Docker restart recovery
was handled separately; SSH security is a distinct infrastructure phase.

## Scope

Infrastructure Security 1 adds a local audit script and this runbook only. It
does not connect to the VPS, alter SSH, create users, install keys, change the
firewall, install packages, control Cockpit, or deploy software.

## Safety Principles

Never restrict the current root/password access until a named non-root
administrator has a tested SSH public key, a second independent key-authenticated
session, working `sudo -v`, provider console or rescue access, a verified
rollback path, a passing `sshd -t`, an existing root session kept open, and
explicit human approval for the restrictive step.

No password, public key, private key, username, token, audit output, or raw
authentication log may be committed to Git.

## Audit Phase

Run `deploy/security/ssh-security-audit.sh` manually on the VPS only after
explicit approval. It writes to stdout and supports `--format=text` and
`--format=json`; retain any report outside Git and redact it before sharing.
The script is read-only and makes no operational change.

The audit reports collection status separately from a numeric result. A valid
zero is not interchangeable with `not_available` or `failed`: for example,
the unique failed-source count is JSON `null` unless its parser completed
successfully, and no source address is emitted. SSH host-key output is limited
to key type, standard fingerprint, and permission metadata; it never emits
private key content. A non-root host-key group is a review signal, not by
itself a safe or unsafe classification. Owner, writable, world-readable, and
executable permission diagnostics provide the evidence for review.

Firewalld evidence distinguishes an absent binary, service state, command
state, and query status. An inactive daemon can make `firewall-cmd --state`
fail; that does not mean the command is unavailable and does not authorize an
operational firewall change.

The first VPS audit taken before the Infrastructure Security 2A portability
fix must be rerun before any hardening decision. Its failed-source aggregation
and host-key fingerprint fields are not decision-quality evidence because the
AlmaLinux AWK parser failure could be reported as zero.

## Target End State

The future target is a named non-root administrator using a tested modern SSH
public key and sudo, with root password SSH access retired only after recovery
paths are proven. Final SSH settings are deliberately not fixed in advance:
they require audit evidence and a reviewed operating decision.

## Prerequisites

Before any future restrictive change, confirm current backups, name a rollback
owner, preserve an existing root SSH session, and open a separate session for
independent validation. Every stage requires explicit human approval.

## Provider Console And Rescue Verification

Verify hosting-provider web console or rescue access before editing SSH. Record
how to recover from an invalid SSH configuration without storing credentials or
provider-specific sensitive details in this repository.

## Named Administrator Account Strategy

In a future operational phase only, create one dedicated non-root Linux
administrator and grant sudo through the OS-standard administration group. Do
not share the root account for daily work. Do not commit the administrator name,
password, public key, private key, or credentials, and do not email credentials.

## SSH Key Strategy

Use a modern supported key type and install only the public key in a future
approved operation. Verify home-directory, `.ssh`, and `authorized_keys`
permissions; then test an independent SSH login and `sudo -v` while retaining
the original root session.

## Stage 0: Read-Only Audit

1. Execute the committed audit script manually.
2. Retain its output outside Git and redact it before sharing.
3. Make no SSH, user, package, firewall, Cockpit, Docker, or application
   changes.

## Stage 1: Recovery Access

Before SSH restrictions, verify provider console/rescue access, confirm the
invalid-configuration recovery method, keep the existing root session open,
open a second session, confirm backups, and identify the rollback owner.

## Stage 2: Named Administrator

Create the dedicated non-root administrator only in a separately approved
operational phase. Grant sudo through the OS-standard group without placing
plain-text passwords, usernames, or keys in scripts or Git.

## Stage 3: SSH Public Key

Install the approved public key only, validate the file permissions, open a new
independent SSH session as the named account, and run `sudo -v`. Do not close
the original root session until all checks succeed.

## Stage 4: Root Access Transition

Only after Stage 3 succeeds, prefer `PermitRootLogin prohibit-password`.
Do not use `PermitRootLogin no` until console recovery and named-admin access
are proven. Remove root password SSH access before considering removal of root
SSH access, and do not terminate existing sessions during the transition.

## Stage 5: Password Authentication Transition

Only after independently verified key login, later set `PasswordAuthentication
no`, review `KbdInteractiveAuthentication`, preserve required PAM behavior,
and inspect the actual effective configuration with `sshd -T`.

## sshd Drop-In Strategy

Future changes must use a dedicated drop-in such as
`/etc/ssh/sshd_config.d/90-mycomputer-hardening.conf`; do not overwrite the
vendor configuration without a reviewed reason.

## Stage 6: Authentication Limits

Future reviewed settings may lower `MaxAuthTries`, use a finite
`LoginGraceTime`, set sensible `ClientAliveInterval` and `ClientAliveCountMax`,
disable empty passwords, disable unused X11 forwarding, and review TCP
forwarding rather than disabling it blindly. Do not invent final values without
audit evidence.

## Stage 7: Brute-Force Protection Options

Review provider firewall controls, firewalld restrictions, Fail2ban, or another
approved rate-limiting mechanism. Fail2ban remains optional until the OS,
repositories, package source, configuration, and rollback are reviewed. Do not
install EPEL or any repository in this phase.

## Firewall Considerations

Do not modify firewall rules during the audit. A later operational change must
preserve verified recovery access and restrict only reviewed, necessary
exposure.

## Stage 8: Cockpit Review

Determine whether Cockpit is actively required. Do not disable it blindly; when
unused, a future phase may disable or firewall-restrict it. When required,
restrict its exposure appropriately after review.

## Validation Procedure

For every future SSH change:

1. Keep the existing root session open.
2. Verify independent console or rescue access.
3. Back up SSH configuration and drop-ins with a timestamp.
4. Create or update only the dedicated hardening drop-in.
5. Run `sshd -t`; a failure blocks reload.
6. Inspect `sshd -T`.
7. Use `systemctl reload sshd`, not a blind restart, when supported.
8. Open a new independent SSH session.
9. Validate the named account, public-key authentication, and `sudo -v`.
10. Confirm the existing session remains usable and review authentication logs.
11. Proceed to the next restrictive stage only after those checks succeed.

## Rollback Procedure

Keep a timestamped known-good configuration backup, provider console access,
and an existing SSH session. On failure, restore the prior drop-in, run
`sshd -t`, reload sshd, and verify a fresh login before closing sessions. No automatic
rollback timer or server-side rollback script is created in this phase.

## Emergency Recovery

Use the provider console or rescue process to restore the known-good drop-in.
Do not make unreviewed changes through an unstable remote SSH session.

## Evidence And Audit Retention

Keep redacted audit evidence outside Git. Retain only approved operational
records in the designated secure incident or change-management location.

## Explicit Non-Goals

This phase does not modify SSH configuration, users, keys, password
authentication, root login, firewalls, packages, Fail2ban, SELinux, Cockpit,
Docker, Laravel, Filament, Nuxt, or deployment state.

## Catalog Sync Safety

Catalog Sync is untouched: CREATE remains enabled, UPDATE remains disabled,
Sync All remains disabled, automatic sync remains disabled, and APCOM
scheduling remains disabled. No supplier, product, offer, category, attribute,
or image data is changed.

## Phase Status

- Audit tooling: implemented locally.
- VPS audit: first run requires a post-fix rerun; do not use its source-count
  or fingerprint fields for hardening decisions.
- SSH configuration change: not performed.
- User creation: not performed.
- Key installation: not performed.
- Password authentication change: not performed.
- Root login change: not performed.
- Firewall change: not performed.
- Brute-force protection installation: not performed.
- Cockpit change: not performed.
- Deployment: not performed.
- Catalog Sync impact: none.
