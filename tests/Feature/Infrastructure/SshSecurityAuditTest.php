<?php

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

class SshSecurityAuditTest extends TestCase
{
    public function test_audit_script_has_the_required_read_only_shell_contract(): void
    {
        $script = $this->auditScript();

        $this->assertStringStartsWith('#!/usr/bin/env bash', $script);
        $this->assertStringContainsString('set -uo pipefail', $script);
        $this->assertStringContainsString('mycomputer-ssh-security-audit-v1', $script);
        $this->assertStringContainsString("AUDIT_MODE='read_only'", $script);
        $this->assertStringContainsString('--format=text', $script);
        $this->assertStringContainsString('--format=json', $script);
        $this->assertStringContainsString('Usage:', $script);
        $this->assertGreaterThan(1000, strlen($script));
    }

    public function test_audit_script_has_no_mutating_or_remote_operations(): void
    {
        $script = $this->auditScript();

        foreach ([
            '/\b(apt|apt-get|dnf|yum|rpm|apk|pacman)\s+(install|remove|erase|upgrade)\b/i',
            '/^\s*(sudo\s+)?(useradd|usermod|userdel|adduser|deluser|passwd|chpasswd)\b/im',
            '/\b(chmod|chown|chgrp|visudo)\b/i',
            '/\b(systemctl|service)\s+(start|stop|restart|reload|enable|disable)\b/i',
            '/\bfirewall-cmd\s+--(add|remove|reload|runtime-to-permanent|permanent)/i',
            '/\b(setenforce|semanage)\b/i',
            '/\b(docker|docker-compose)\b/i',
            '/\b(php\s+artisan|composer\s+run|npm\s+run)\b/i',
            '/^\s*(sudo\s+)?(curl|wget|scp|sftp|rsync|ssh)\s+/im',
            '/\b(rm|shred|truncate)\b/i',
            '/(^|\s)(>|>>)\s*\/etc\//m',
            '/\btee\s+.*\/etc\//i',
        ] as $prohibitedPattern) {
            $this->assertDoesNotMatchRegularExpression($prohibitedPattern, $script);
        }
    }

    public function test_audit_script_preserves_the_redaction_contract(): void
    {
        $script = $this->auditScript();

        foreach ([
            '/cat\s+\/etc\/shadow/i',
            '/printenv\b/i',
            '/\benv\s*$/m',
            '/cat\s+.*authorized_keys/i',
            '/\bhistory\b/i',
            '/echo\s+.*authentication_journal/i',
            '/journalctl[^\n]*\|\s*(cat|tee)/i',
            '/BEGIN [A-Z ]*PRIVATE KEY/',
        ] as $prohibitedPattern) {
            $this->assertDoesNotMatchRegularExpression($prohibitedPattern, $script);
        }

        $this->assertStringContainsString('unique_failed_source_count', $script);
        $this->assertStringContainsString('fingerprints_for_key_file', $script);
        $this->assertStringContainsString('safe_hostname', $script);
    }

    public function test_audit_script_covers_all_required_read_only_sections(): void
    {
        $script = $this->auditScript();

        foreach ([
            'generated_at_utc',
            'sshd_service',
            'effective_configuration',
            'configuration_files',
            'host_keys',
            'authorized_access',
            'authentication_activity_24h',
            'firewall',
            'brute_force_protection',
            'selinux',
            'cockpit',
            'findings',
            'verdict',
            'permitrootlogin',
            'passwordauthentication',
            'authorizedkeysfile',
        ] as $requiredSection) {
            $this->assertStringContainsString($requiredSection, $script);
        }
    }

    public function test_hardening_runbook_requires_lockout_safe_staged_operations(): void
    {
        $runbook = file_get_contents(base_path('docs/SSH_SECURITY_HARDENING.md'));

        $this->assertNotFalse($runbook);
        $normalizedRunbook = preg_replace('/\s+/', ' ', $runbook);
        $this->assertIsString($normalizedRunbook);

        foreach ([
            'named non-root',
            'existing root session open',
            'provider console or rescue access',
            'sshd -t',
            'systemctl reload sshd',
            'Do not commit the administrator name',
            'No password, public key, private key',
            'No automatic rollback timer',
            'Catalog Sync is untouched',
            'Stage 0: Read-Only Audit',
            'Stage 8',
        ] as $requiredStatement) {
            $this->assertStringContainsString($requiredStatement, $normalizedRunbook);
        }
    }

    public function test_deployment_documentation_links_to_ssh_hardening_runbook(): void
    {
        $deploymentDocumentation = file_get_contents(base_path('docs/DEPLOYMENT.md'));

        $this->assertNotFalse($deploymentDocumentation);
        $this->assertStringContainsString('SSH Security Baseline Audit And Safe Hardening', $deploymentDocumentation);
        $this->assertStringContainsString('SSH_SECURITY_HARDENING.md', $deploymentDocumentation);
        $this->assertStringContainsString('read-only', $deploymentDocumentation);
    }

    private function auditScript(): string
    {
        $path = base_path('deploy/security/ssh-security-audit.sh');

        $this->assertFileExists($path);

        $script = file_get_contents($path);
        $this->assertNotFalse($script);

        return $script;
    }
}
