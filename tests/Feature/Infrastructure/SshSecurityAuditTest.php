<?php

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
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

    public function test_portable_awk_extracts_only_fingerprint_tokens_from_synthetic_input(): void
    {
        $output = $this->runBash(
            <<<'BASH'
awk '{ for (field_no = 1; field_no <= NF; field_no++) if ($field_no ~ /^SHA256:/) print $field_no }'
BASH,
            "2048 SHA256:synthetic-fingerprint example\n",
        );

        $this->assertSame('SHA256:synthetic-fingerprint', trim($output));
    }

    public function test_portable_awk_counts_unique_failed_sources_without_emitting_addresses(): void
    {
        $output = $this->runBash(
            <<<'BASH'
awk '/Failed password|Invalid user/ { for (field_no = 1; field_no <= NF; field_no++) if ($field_no == "from") print $(field_no + 1) }' | sort -u | awk 'NF { count += 1 } END { print count + 0 }'
BASH,
            implode("\n", [
                'Failed password for invalid user test from 198.51.100.10 port 22 ssh2',
                'Invalid user test from 198.51.100.11 port 22',
                'Failed password for root from 198.51.100.10 port 22 ssh2',
                '',
            ]),
        );

        $this->assertSame('2', trim($output));
        $this->assertDoesNotMatchRegularExpression('/198\.51\.100\./', trim($output));
    }

    public function test_failed_source_parser_has_an_explicit_non_numeric_failure_contract(): void
    {
        $script = $this->auditScript();

        $this->assertStringContainsString("unique_failed_source_count='not_available'", $script);
        $this->assertStringContainsString("unique_failed_source_status='failed'", $script);
        $this->assertStringContainsString('"unique_failed_source_count":', $script);
        $this->assertStringContainsString("printf 'null'", $script);
    }

    public function test_forced_failed_source_parser_reports_failed_status_and_null_json_count(): void
    {
        $result = $this->runAuditWithFakeCommands([
            'journalctl' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' 'Failed password for invalid user test from 198.51.100.10 port 22 ssh2'
BASH,
            'awk' => <<<'BASH'
#!/usr/bin/env bash
for argument in "$@"; do
    case "$argument" in
        *'Failed password|Invalid user'*) exit 75 ;;
    esac
done
exec /usr/bin/awk "$@"
BASH,
        ]);

        $this->assertSame('failed', $result['report']['authentication_activity_24h']['unique_failed_source_status']);
        $this->assertNull($result['report']['authentication_activity_24h']['unique_failed_source_count']);
        $this->assertStringNotContainsString('198.51.100.10', $result['output']);
    }

    public function test_verdict_uses_the_highest_finding_severity_regardless_of_finding_order(): void
    {
        $results = $this->runVerdictCases([
            'informational' => [],
            'review_required' => ['review_required|review|Review this.'],
            'high_risk' => ['high_risk|risk|Address this.'],
            'high_before_reviews' => [
                'high_risk|host_key_permissions|Unsafe host key permissions.',
                'high_risk|root_password_login_enabled|Root password login.',
                'review_required|password_authentication_enabled|Password authentication.',
                'review_required|firewall_inactive|Firewall review.',
            ],
        ]);

        $this->assertSame('informational', $results['informational']);
        $this->assertSame('review_required', $results['review_required']);
        $this->assertSame('high_risk', $results['high_risk']);
        $this->assertSame('high_risk', $results['high_before_reviews']);
    }

    public function test_host_key_diagnostics_report_safe_metadata_without_private_content(): void
    {
        $script = $this->auditScript();

        foreach ([
            'host_key_diagnostics',
            'owner_unexpected',
            'group_unexpected',
            'group_writable',
            'world_readable',
            'world_writable',
            'executable',
            'host_key_group_review_required',
            'host_key_fingerprint_status',
        ] as $requiredDiagnostic) {
            $this->assertStringContainsString($requiredDiagnostic, $script);
        }

        $this->assertStringNotContainsString("if [[ \"\$host_owner\" != 'root' || \"\$host_group\" != 'root' ]] || mode_is_group_or_world_writable", $script);
    }

    public function test_firewall_state_contract_distinguishes_installation_command_and_query_states(): void
    {
        $script = $this->auditScript();

        foreach ([
            'not_installed',
            "'active'",
            "'inactive'",
            "'failed'",
            "'not_available'",
            'firewalld_service_state',
            'firewalld_command_state',
            'firewalld_query_status',
        ] as $requiredState) {
            $this->assertStringContainsString($requiredState, $script);
        }
    }

    public function test_firewall_states_do_not_conflate_an_inactive_daemon_with_a_missing_command(): void
    {
        foreach ([
            'active' => ['service_state' => 'active', 'command_state' => 'active', 'query_status' => 'available'],
            'inactive' => ['service_state' => 'inactive', 'command_state' => 'inactive', 'query_status' => 'not_available'],
            'failed' => ['service_state' => 'failed', 'command_state' => 'failed', 'query_status' => 'failed'],
        ] as $state => $expected) {
            $result = $this->runAuditWithFakeCommands([
                'systemctl' => $this->firewallSystemctlStub($state),
                'firewall-cmd' => $this->firewallCommandStub($state),
            ]);

            $firewall = $result['report']['firewall'];
            $this->assertTrue($firewall['installed']);
            $this->assertSame($expected['service_state'], $firewall['service_state']);
            $this->assertSame($expected['command_state'], $firewall['command_state']);
            $this->assertSame($expected['query_status'], $firewall['query_status']);
        }
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

    private function runBash(string $script, string $input = ''): string
    {
        $process = new Process([$this->bashBinary(), '-lc', $script], base_path());
        $process->setInput($input);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process->getOutput();
    }

    /**
     * @param  array<string, string>  $commands
     * @return array{output: string, report: array<string, mixed>}
     */
    private function runAuditWithFakeCommands(array $commands): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ssh-audit-command-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);

        try {
            foreach ($commands as $name => $contents) {
                $path = $directory.DIRECTORY_SEPARATOR.$name;
                file_put_contents($path, $contents);
                chmod($path, 0700);
            }

            $runner = <<<'BASH'
fake_directory=$1
audit_script=$2
if command -v cygpath >/dev/null 2>&1; then
    fake_directory=$(cygpath -u "$fake_directory")
    audit_script=$(cygpath -u "$audit_script")
fi
PATH="$fake_directory:$PATH"
export PATH
bash "$audit_script" --format=json
BASH;
            $process = new Process([
                $this->bashBinary(),
                '-lc',
                $runner,
                'ssh-audit-test',
                $directory,
                base_path('deploy/security/ssh-security-audit.sh'),
            ], base_path());
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return [
                'output' => $process->getOutput(),
                'report' => json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR),
            ];
        } finally {
            foreach (array_keys($commands) as $name) {
                @unlink($directory.DIRECTORY_SEPARATOR.$name);
            }
            @rmdir($directory);
        }
    }

    private function firewallSystemctlStub(string $firewalldState): string
    {
        return str_replace('{{STATE}}', $firewalldState, <<<'BASH'
#!/usr/bin/env bash
case "$1:$2" in
    is-active:firewalld)
        printf '%s\n' '{{STATE}}'
        [[ '{{STATE}}' == active ]] && exit 0
        exit 3
        ;;
    is-active:*)
        printf '%s\n' 'inactive'
        exit 3
        ;;
    is-enabled:*)
        printf '%s\n' 'disabled'
        exit 1
        ;;
    list-unit-files:*) exit 0 ;;
    cat:*) exit 1 ;;
    *) exit 1 ;;
esac
BASH);
    }

    private function firewallCommandStub(string $state): string
    {
        return str_replace('{{STATE}}', $state, <<<'BASH'
#!/usr/bin/env bash
case "$1" in
    --state)
        [[ '{{STATE}}' == active ]] && printf '%s\n' 'running' && exit 0
        printf '%s\n' 'not running'
        exit 1
        ;;
    --get-active-zones)
        printf '%s\n' 'public' 'eth0'
        ;;
    --list-services)
        printf '%s\n' 'ssh'
        ;;
    --list-ports) exit 0 ;;
    *) exit 1 ;;
esac
BASH);
    }

    /**
     * @param  array<string, array<int, string>>  $cases
     * @return array<string, string>
     */
    private function runVerdictCases(array $cases): array
    {
        $script = $this->auditScript();
        $marker = 'generated_at_utc=';
        $functionDefinitions = strstr($script, $marker, true);

        $this->assertIsString($functionDefinitions);
        $this->assertNotSame('', $functionDefinitions);

        $path = tempnam(sys_get_temp_dir(), 'ssh-audit-functions-');
        $this->assertNotFalse($path);
        file_put_contents($path, $functionDefinitions);

        try {
            $runner = <<<'BASH'
function_file=$1
shift
audit_findings=("$@")
set --
source "$function_file"
findings=("${audit_findings[@]}")
calculate_verdict
BASH;
            $results = [];
            foreach ($cases as $name => $findings) {
                $process = new Process([
                    $this->bashBinary(),
                    '-lc',
                    $runner,
                    'ssh-audit-test',
                    $path,
                    ...$findings,
                ], base_path());
                $process->run();

                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[$name] = trim($process->getOutput());
            }

            return $results;
        } finally {
            @unlink($path);
        }
    }

    private function bashBinary(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return 'C:\\Program Files\\Git\\bin\\bash.exe';
        }

        return 'bash';
    }
}
