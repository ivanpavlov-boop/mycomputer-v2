#!/usr/bin/env bash

# MyComputer VPS SSH baseline audit. The script is intentionally read-only and
# emits a redacted posture report to stdout only.
set -uo pipefail

readonly AUDIT_SCHEMA='mycomputer-ssh-security-audit-v1'
readonly AUDIT_MODE='read_only'

format='text'

usage() {
    printf 'Usage: %s [--format=text|--format=json]\n' "${0##*/}"
}

while (($# > 0)); do
    case "$1" in
        --format=text) format='text' ;;
        --format=json) format='json' ;;
        --help|-h) usage; exit 0 ;;
        *) usage; exit 2 ;;
    esac
    shift
done

command_available() {
    command -v "$1" >/dev/null 2>&1
}

trim() {
    local value=${1:-}
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

json_escape() {
    local value=${1:-}
    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//$'\n'/\\n}
    value=${value//$'\r'/\\r}
    value=${value//$'\t'/\\t}
    printf '%s' "$value"
}

json_string() {
    printf '"%s"' "$(json_escape "$1")"
}

json_nullable() {
    if [[ -z "$1" || "$1" == 'not_available' || "$1" == 'failed' ]]; then
        printf 'null'
    else
        json_string "$1"
    fi
}

json_boolean() {
    [[ "$1" == 'true' ]] && printf 'true' || printf 'false'
}

safe_hostname() {
    local value=${1:-not_available}

    if [[ "$value" =~ ([0-9]{1,3}\.){3}[0-9]{1,3} || "$value" == *:*:* ]]; then
        printf 'redacted'
    else
        printf '%s' "$value"
    fi
}

safe_sshd_version() {
    local value=${1:-}

    if [[ "$value" =~ (OpenSSH_[[:alnum:].p]+) ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
    else
        printf 'not_available'
    fi
}

service_state() {
    if ! command_available systemctl; then
        printf 'not_available'
        return
    fi

    local value
    value=$(systemctl is-active "$1" 2>/dev/null || true)
    printf '%s' "$(trim "${value:-not_available}")"
}

service_enabled() {
    if ! command_available systemctl; then
        printf 'not_available'
        return
    fi

    local value
    value=$(systemctl is-enabled "$1" 2>/dev/null || true)
    printf '%s' "$(trim "${value:-not_available}")"
}

file_metadata() {
    local file=$1

    if [[ ! -e "$file" || ! -r "$file" ]] || ! command_available stat; then
        printf 'not_available|not_available|not_available'
        return
    fi

    stat -c '%U|%G|%a' "$file" 2>/dev/null || printf 'not_available|not_available|not_available'
}

mode_is_group_or_world_writable() {
    local mode=${1:-}
    local group_digit other_digit

    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
    group_digit=${mode: -2:1}
    other_digit=${mode: -1}
    (( (10#$group_digit & 2) != 0 || (10#$other_digit & 2) != 0 ))
}

mode_has_group_writable() {
    local mode=${1:-}
    local group_digit

    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
    group_digit=${mode: -2:1}
    (( (10#$group_digit & 2) != 0 ))
}

mode_has_world_readable() {
    local mode=${1:-}
    local other_digit

    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
    other_digit=${mode: -1}
    (( (10#$other_digit & 4) != 0 ))
}

mode_has_world_writable() {
    local mode=${1:-}
    local other_digit

    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
    other_digit=${mode: -1}
    (( (10#$other_digit & 2) != 0 ))
}

mode_has_executable_bits() {
    local mode=${1:-}
    local digits digit

    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
    digits=${mode: -3}
    for ((digit = 0; digit < ${#digits}; digit++)); do
        (( (10#${digits:digit:1} & 1) != 0 )) && return 0
    done

    return 1
}

effective_value() {
    local key=$1
    local fallback=${2:-not_available}
    local value

    if [[ "$effective_config_available" != 'true' ]]; then
        printf '%s' "$fallback"
        return
    fi

    value=$(printf '%s\n' "$effective_config" | awk -v key="$key" '$1 == key { $1=""; sub(/^ /, ""); print; exit }')
    printf '%s' "$(trim "${value:-$fallback}")"
}

restriction_configured() {
    local value
    value=$(effective_value "$1" 'none')
    [[ "$value" != 'none' && "$value" != 'not_available' && -n "$value" ]]
}

listening_ports() {
    if ! command_available ss; then
        printf 'not_available'
        return
    fi

    local ports
    ports=$(ss -ltnH 2>/dev/null | awk '{ address = $4; sub(/^.*:/, "", address); if (address ~ /^[0-9]+$/) print address }' | sort -nu | paste -sd ',' -)
    printf '%s' "${ports:-not_available}"
}

port_is_listening() {
    local port=$1
    local ports=$2
    [[ ",${ports}," == *",${port},"* ]]
}

fingerprints_for_key_file() {
    local file=$1

    ssh-keygen -lf "$file" 2>/dev/null | awk '{ for (field_no = 1; field_no <= NF; field_no++) if ($field_no ~ /^SHA256:/) print $field_no }'
}

authorized_key_count() {
    local file=$1

    if [[ ! -r "$file" ]]; then
        printf '0'
        return
    fi

    awk 'NF && $1 !~ /^#/' "$file" 2>/dev/null | wc -l | tr -d '[:space:]'
}

append_finding() {
    findings+=("$1|$2|$3")
}

severity_rank() {
    case "$1" in
        informational) printf '0' ;;
        review_required) printf '1' ;;
        high_risk) printf '2' ;;
        *) printf '0' ;;
    esac
}

calculate_verdict() {
    local finding classification rank highest_rank=0 result='informational'

    for finding in "${findings[@]}"; do
        IFS='|' read -r classification _ _ <<<"$finding"
        rank=$(severity_rank "$classification")
        if (( rank > highest_rank )); then
            highest_rank=$rank
            result=$classification
        fi
    done

    printf '%s' "$result"
}

json_string_array() {
    local -n values=$1
    local value first='true'

    printf '['
    for value in "${values[@]}"; do
        [[ -n "$value" ]] || continue
        [[ "$first" == 'true' ]] || printf ','
        json_string "$value"
        first='false'
    done
    printf ']'
}

generated_at_utc=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || printf 'not_available')
running_as_root='false'
[[ ${EUID:-65534} -eq 0 ]] && running_as_root='true'

hostname_value='not_available'
if command_available hostname; then
    hostname_value=$(safe_hostname "$(hostname 2>/dev/null || printf 'not_available')")
fi

kernel_version='not_available'
if command_available uname; then
    kernel_version=$(uname -r 2>/dev/null || printf 'not_available')
fi

os_id='not_available'
os_version='not_available'
if [[ -r /etc/os-release ]]; then
    os_id=$(awk -F '=' '$1 == "ID" { gsub(/^"|"$/, "", $2); print $2; exit }' /etc/os-release 2>/dev/null)
    os_version=$(awk -F '=' '$1 == "VERSION_ID" { gsub(/^"|"$/, "", $2); print $2; exit }' /etc/os-release 2>/dev/null)
    os_id=${os_id:-not_available}
    os_version=${os_version:-not_available}
fi

sshd_present='false'
sshd_version='not_available'
effective_config=''
effective_config_available='false'
effective_config_status='not_available'
if command_available sshd; then
    sshd_present='true'
    sshd_version=$(safe_sshd_version "$(sshd -V 2>&1 || true)")
    effective_config=$(sshd -T 2>/dev/null || true)
    if [[ -n "$effective_config" ]]; then
        effective_config_available='true'
        effective_config_status='available'
    else
        effective_config_status='failed'
    fi
fi

sshd_active=$(service_state sshd)
sshd_enabled=$(service_enabled sshd)
all_listening_ports=$(listening_ports)
sshd_ports=$(effective_value port '22')
sshd_listening='not_available'
if [[ "$all_listening_ports" != 'not_available' ]]; then
    sshd_listening='false'
    for port in $sshd_ports; do
        port_is_listening "$port" "$all_listening_ports" && sshd_listening='true'
    done
fi

permit_root_login=$(effective_value permitrootlogin)
password_authentication=$(effective_value passwordauthentication)
public_key_authentication=$(effective_value pubkeyauthentication)
kbd_interactive_authentication=$(effective_value kbdinteractiveauthentication)
challenge_response_authentication=$(effective_value challengeresponseauthentication)
use_pam=$(effective_value usepam)
authentication_methods=$(effective_value authenticationmethods)
max_auth_tries=$(effective_value maxauthtries)
max_sessions=$(effective_value maxsessions)
login_grace_time=$(effective_value logingracetime)
client_alive_interval=$(effective_value clientaliveinterval)
client_alive_count_max=$(effective_value clientalivecountmax)
x11_forwarding=$(effective_value x11forwarding)
allow_tcp_forwarding=$(effective_value allowtcpforwarding)
permit_user_environment=$(effective_value permituserenvironment)
permit_user_rc=$(effective_value permituserrc)
gssapi_authentication=$(effective_value gssapiauthentication)
authorized_keys_file=$(effective_value authorizedkeysfile)
strict_modes=$(effective_value strictmodes)
permit_empty_passwords=$(effective_value permitemptypasswords)

allow_users_configured='false'
allow_groups_configured='false'
deny_users_configured='false'
deny_groups_configured='false'
restriction_configured allowusers && allow_users_configured='true'
restriction_configured allowgroups && allow_groups_configured='true'
restriction_configured denyusers && deny_users_configured='true'
restriction_configured denygroups && deny_groups_configured='true'

config_files=()
[[ -e /etc/ssh/sshd_config ]] && config_files+=(/etc/ssh/sshd_config)
if [[ -d /etc/ssh/sshd_config.d ]]; then
    while IFS= read -r config_file; do
        config_files+=("$config_file")
    done < <(find /etc/ssh/sshd_config.d -maxdepth 1 -type f -print 2>/dev/null | sort)
fi

config_file_count=${#config_files[@]}
config_drop_in_count=$config_file_count
[[ -e /etc/ssh/sshd_config ]] && config_drop_in_count=$((config_drop_in_count - 1))
configured_include_directive_count=0
config_group_or_world_writable='false'
config_unexpected_owner='false'
primary_config_exists='false'
primary_config_owner='not_available'
primary_config_group='not_available'
primary_config_mode='not_available'
for config_file in "${config_files[@]}"; do
    IFS='|' read -r owner group mode <<<"$(file_metadata "$config_file")"
    if [[ "$config_file" == '/etc/ssh/sshd_config' ]]; then
        primary_config_exists='true'
        primary_config_owner=$owner
        primary_config_group=$group
        primary_config_mode=$mode
    fi
    mode_is_group_or_world_writable "$mode" && config_group_or_world_writable='true'
    [[ "$owner" != 'root' && "$owner" != 'not_available' ]] && config_unexpected_owner='true'
    configured_include_directive_count=$((configured_include_directive_count + $(grep -Ec '^[[:space:]]*Include[[:space:]]+' "$config_file" 2>/dev/null || true)))
done

host_key_types=()
host_key_fingerprints=()
host_key_metadata=()
host_key_diagnostics=()
host_key_unsafe_permissions='false'
host_key_group_review_required='false'
host_key_fingerprint_status='not_available'
for host_key in /etc/ssh/ssh_host_*_key; do
    [[ -e "$host_key" ]] || continue
    host_key_type=${host_key##*/ssh_host_}
    host_key_type=${host_key_type%_key}
    host_key_types+=("$host_key_type")
    IFS='|' read -r host_owner host_group host_mode <<<"$(file_metadata "$host_key")"
    host_key_metadata+=("${host_key_type}:owner=${host_owner},group=${host_group},mode=${host_mode}")
    host_owner_unexpected='false'
    host_group_unexpected='false'
    host_group_writable='false'
    host_world_readable='false'
    host_world_writable='false'
    host_executable='false'
    [[ "$host_owner" != 'root' && "$host_owner" != 'not_available' ]] && host_owner_unexpected='true'
    [[ "$host_group" != 'root' && "$host_group" != 'not_available' ]] && host_group_unexpected='true'
    mode_has_group_writable "$host_mode" && host_group_writable='true'
    mode_has_world_readable "$host_mode" && host_world_readable='true'
    mode_has_world_writable "$host_mode" && host_world_writable='true'
    mode_has_executable_bits "$host_mode" && host_executable='true'
    host_key_diagnostics+=("${host_key_type}:owner=${host_owner},group=${host_group},mode=${host_mode},owner_unexpected=${host_owner_unexpected},group_unexpected=${host_group_unexpected},group_writable=${host_group_writable},world_readable=${host_world_readable},world_writable=${host_world_writable},executable=${host_executable}")
    if [[ "$host_owner_unexpected" == 'true' || "$host_group_writable" == 'true' || "$host_world_readable" == 'true' || "$host_world_writable" == 'true' || "$host_executable" == 'true' ]]; then
        host_key_unsafe_permissions='true'
    fi
    [[ "$host_group_unexpected" == 'true' ]] && host_key_group_review_required='true'
    if ! command_available ssh-keygen || [[ ! -r "$host_key" ]]; then
        continue
    fi
    if fingerprint_output=$(fingerprints_for_key_file "$host_key"); then
        [[ "$host_key_fingerprint_status" != 'failed' ]] && host_key_fingerprint_status='available'
        while IFS= read -r fingerprint; do
            [[ -n "$fingerprint" ]] && host_key_fingerprints+=("$fingerprint")
        done <<<"$fingerprint_output"
    else
        host_key_fingerprint_status='failed'
    fi
done

interactive_account_count=0
if [[ -r /etc/passwd ]]; then
    interactive_account_count=$(awk -F ':' '$7 !~ /(nologin|false)$/ { count += 1 } END { print count + 0 }' /etc/passwd 2>/dev/null)
fi

root_authorized_keys='/root/.ssh/authorized_keys'
root_authorized_keys_present='false'
root_authorized_key_count=0
root_authorized_key_fingerprints=()
root_authorized_key_fingerprint_status='not_available'
if [[ -r "$root_authorized_keys" ]]; then
    root_authorized_keys_present='true'
    root_authorized_key_count=$(authorized_key_count "$root_authorized_keys")
    if command_available ssh-keygen; then
        if fingerprint_output=$(fingerprints_for_key_file "$root_authorized_keys"); then
            root_authorized_key_fingerprint_status='available'
            while IFS= read -r fingerprint; do
                [[ -n "$fingerprint" ]] && root_authorized_key_fingerprints+=("$fingerprint")
            done <<<"$fingerprint_output"
        else
            root_authorized_key_fingerprint_status='failed'
        fi
    fi
fi

non_root_authorized_key_accounts=0
if [[ -r /etc/passwd ]]; then
    while IFS=':' read -r account_name _ account_uid _ _ account_home account_shell; do
        [[ "$account_uid" == '0' || "$account_shell" =~ (nologin|false)$ ]] && continue
        if [[ -r "$account_home/.ssh/authorized_keys" ]] && [[ $(authorized_key_count "$account_home/.ssh/authorized_keys") -gt 0 ]]; then
            non_root_authorized_key_accounts=$((non_root_authorized_key_accounts + 1))
        fi
    done < /etc/passwd
fi

non_root_sudo_capable='false'
if command_available getent; then
    for admin_group in sudo wheel; do
        group_record=$(getent group "$admin_group" 2>/dev/null || true)
        IFS=':' read -r _ _ _ group_members <<<"$group_record"
        [[ -n "${group_members:-}" ]] && non_root_sudo_capable='true'
    done
fi

journal_available='false'
authentication_journal_status='not_available'
failed_password_attempts=0
invalid_user_attempts=0
accepted_public_key_logins=0
accepted_password_logins=0
root_login_successes=0
unique_failed_source_count='not_available'
unique_failed_source_status='not_available'
if command_available journalctl; then
    journal_available='true'
    if authentication_journal=$(journalctl --since '24 hours ago' -u sshd --no-pager 2>/dev/null); then
        authentication_journal_status='available'
    elif authentication_journal=$(journalctl --since '24 hours ago' -u ssh --no-pager 2>/dev/null); then
        authentication_journal_status='available'
    else
        authentication_journal=''
        authentication_journal_status='failed'
    fi
    if [[ "$authentication_journal_status" == 'available' ]]; then
        failed_password_attempts=$(printf '%s\n' "$authentication_journal" | grep -Eic 'Failed password' || true)
        invalid_user_attempts=$(printf '%s\n' "$authentication_journal" | grep -Eic 'Invalid user' || true)
        accepted_public_key_logins=$(printf '%s\n' "$authentication_journal" | grep -Eic 'Accepted publickey' || true)
        accepted_password_logins=$(printf '%s\n' "$authentication_journal" | grep -Eic 'Accepted password' || true)
        root_login_successes=$(printf '%s\n' "$authentication_journal" | grep -Eic 'Accepted (publickey|password).* for root' || true)
        if unique_failed_source_count=$(printf '%s\n' "$authentication_journal" | awk '/Failed password|Invalid user/ { for (field_no = 1; field_no <= NF; field_no++) if ($field_no == "from") print $(field_no + 1) }' | sort -u | awk 'NF { count += 1 } END { print count + 0 }'); then
            unique_failed_source_status='available'
        else
            unique_failed_source_count='not_available'
            unique_failed_source_status='failed'
        fi
    fi
fi

current_ssh_session_count=0
if command_available who; then
    current_ssh_session_count=$(who 2>/dev/null | wc -l | tr -d '[:space:]')
fi

firewalld_installed='false'
firewalld_active='not_installed'
firewalld_service_state='not_installed'
firewalld_command_state='not_installed'
firewalld_query_status='not_available'
firewalld_enabled='not_available'
firewall_active_zones='not_available'
firewall_ssh_allowed='false'
firewall_cockpit_allowed='false'
firewall_open_port_count=0
if command_available firewall-cmd; then
    firewalld_installed='true'
    firewalld_service_state=$(service_state firewalld)
    case "$firewalld_service_state" in
        active|inactive|failed) ;;
        *) firewalld_service_state='not_available' ;;
    esac
    firewalld_active=$firewalld_service_state
    firewalld_enabled=$(service_enabled firewalld)
    if firewall_command_output=$(firewall-cmd --state 2>/dev/null); then
        case "$(trim "$firewall_command_output")" in
            running|active) firewalld_command_state='active' ;;
            inactive) firewalld_command_state='inactive' ;;
            failed) firewalld_command_state='failed' ;;
            *) firewalld_command_state='not_available' ;;
        esac
    else
        case "$firewalld_service_state" in
            inactive) firewalld_command_state='inactive' ;;
            failed) firewalld_command_state='failed' ;;
            *) firewalld_command_state='failed' ;;
        esac
    fi
    if [[ "$firewalld_command_state" == 'active' ]]; then
        if firewall_zones_output=$(firewall-cmd --get-active-zones 2>/dev/null) && firewall_services=$(firewall-cmd --list-services 2>/dev/null) && firewall_ports=$(firewall-cmd --list-ports 2>/dev/null); then
            firewalld_query_status='available'
            firewall_active_zones=$(printf '%s\n' "$firewall_zones_output" | awk 'NR % 2 == 1 { print }' | paste -sd ',' -)
            firewall_active_zones=${firewall_active_zones:-not_available}
            [[ " $firewall_services " == *' ssh '* ]] && firewall_ssh_allowed='true'
            [[ " $firewall_services " == *' cockpit '* ]] && firewall_cockpit_allowed='true'
            [[ " $firewall_ports " == *'9090/'* ]] && firewall_cockpit_allowed='true'
            firewall_open_port_count=$(printf '%s\n' "$firewall_ports" | awk '{ print NF + 0 }')
        else
            firewalld_query_status='failed'
        fi
    elif [[ "$firewalld_command_state" == 'failed' ]]; then
        firewalld_query_status='failed'
    fi
fi

fail2ban_installed='false'
fail2ban_active='not_available'
fail2ban_enabled='not_available'
fail2ban_sshd_jail='false'
other_rate_limiting_detected='false'
if command_available fail2ban-client; then
    fail2ban_installed='true'
    fail2ban_active=$(service_state fail2ban)
    fail2ban_enabled=$(service_enabled fail2ban)
    fail2ban-client status sshd >/dev/null 2>&1 && fail2ban_sshd_jail='true'
fi
if command_available systemctl && systemctl list-unit-files --type=service --no-legend 2>/dev/null | grep -Eq '^(sshguard|denyhosts)\.service'; then
    other_rate_limiting_detected='true'
fi

selinux_installed='false'
selinux_mode='not_available'
selinux_policy='not_available'
if command_available getenforce; then
    selinux_installed='true'
    selinux_mode=$(trim "$(getenforce 2>/dev/null || printf 'not_available')")
fi
if command_available sestatus; then
    selinux_policy=$(sestatus 2>/dev/null | awk -F ':' '/Loaded policy name/ { gsub(/^[[:space:]]+/, "", $2); print $2; exit }')
    selinux_policy=${selinux_policy:-not_available}
fi

cockpit_socket_exists='false'
if command_available systemctl && systemctl cat cockpit.socket >/dev/null 2>&1; then
    cockpit_socket_exists='true'
fi
cockpit_active=$(service_state cockpit.socket)
cockpit_enabled=$(service_enabled cockpit.socket)
cockpit_listening='not_available'
if [[ "$all_listening_ports" != 'not_available' ]]; then
    cockpit_listening='false'
    port_is_listening '9090' "$all_listening_ports" && cockpit_listening='true'
fi

findings=()
if [[ "$permit_root_login" == 'yes' && "$password_authentication" == 'yes' ]]; then
    append_finding 'high_risk' 'root_password_login_enabled' 'Root login and password authentication are both enabled.'
fi
[[ "$permit_empty_passwords" == 'yes' ]] && append_finding 'high_risk' 'empty_passwords_enabled' 'Empty passwords are permitted.'
[[ "$config_group_or_world_writable" == 'true' ]] && append_finding 'high_risk' 'sshd_config_writable' 'An SSH configuration file is writable by group or others.'
[[ "$host_key_unsafe_permissions" == 'true' ]] && append_finding 'high_risk' 'host_key_permissions' 'An SSH host key has unsafe owner, readable, writable, or executable permission bits.'
[[ "$effective_config_status" == 'failed' ]] && append_finding 'high_risk' 'sshd_effective_config_failed' 'The effective SSH daemon configuration could not be read.'
[[ "$password_authentication" == 'yes' ]] && append_finding 'review_required' 'password_authentication_enabled' 'Password authentication remains enabled.'
[[ "$host_key_group_review_required" == 'true' ]] && append_finding 'review_required' 'host_key_group_review' 'An SSH host key has a non-root group and requires an ownership review.'
[[ "$host_key_fingerprint_status" == 'failed' ]] && append_finding 'review_required' 'host_key_fingerprint_collection_failed' 'SSH host key fingerprint collection failed; no private key content was emitted.'
[[ "$unique_failed_source_status" == 'failed' ]] && append_finding 'review_required' 'authentication_source_parser_failed' 'Failed-source aggregation could not be completed; no source addresses were emitted.'
if [[ "$non_root_authorized_key_accounts" -eq 0 || "$non_root_sudo_capable" != 'true' ]]; then
    append_finding 'review_required' 'non_root_recovery_access_unverified' 'A tested non-root key-based administrator is not evidenced by this local audit.'
fi
if [[ "$fail2ban_installed" != 'true' && "$other_rate_limiting_detected" != 'true' ]]; then
    append_finding 'review_required' 'brute_force_mitigation_unverified' 'No recognized SSH brute-force mitigation service is detected.'
fi
if [[ "$firewalld_installed" == 'true' && "$firewalld_command_state" != 'active' ]]; then
    append_finding 'review_required' 'firewall_inactive' 'Firewalld is installed but does not report a running state.'
fi
if [[ "$cockpit_active" == 'active' && "$cockpit_listening" == 'true' ]]; then
    append_finding 'review_required' 'cockpit_exposure_review' 'Cockpit is active and listening; its intended exposure requires review.'
fi
if [[ "$max_auth_tries" =~ ^[0-9]+$ && "$max_auth_tries" -gt 6 ]]; then
    append_finding 'review_required' 'weak_max_auth_tries' 'MaxAuthTries is higher than the audit review threshold.'
fi

verdict=$(calculate_verdict)

text_report() {
    printf 'schema: %s\nmode: %s\ngenerated_at_utc: %s\n' "$AUDIT_SCHEMA" "$AUDIT_MODE" "$generated_at_utc"
    printf 'system.running_as_root: %s\nsystem.hostname: %s\nsystem.kernel_version: %s\nsystem.os_id: %s\nsystem.os_version: %s\n' "$running_as_root" "$hostname_value" "$kernel_version" "$os_id" "$os_version"
    printf 'sshd_service.present: %s\nsshd_service.version: %s\nsshd_service.active: %s\nsshd_service.enabled: %s\nsshd_service.listening: %s\nsshd_service.ports: %s\n' "$sshd_present" "$sshd_version" "$sshd_active" "$sshd_enabled" "$sshd_listening" "$sshd_ports"
    printf 'effective_configuration.status: %s\neffective_configuration.permitrootlogin: %s\neffective_configuration.passwordauthentication: %s\neffective_configuration.pubkeyauthentication: %s\n' "$effective_config_status" "$permit_root_login" "$password_authentication" "$public_key_authentication"
    printf 'configuration_files.primary_exists: %s\nconfiguration_files.primary_owner: %s\nconfiguration_files.primary_group: %s\nconfiguration_files.primary_mode: %s\nconfiguration_files.drop_in_count: %s\nconfiguration_files.include_directive_count: %s\nconfiguration_files.group_or_world_writable: %s\n' "$primary_config_exists" "$primary_config_owner" "$primary_config_group" "$primary_config_mode" "$config_drop_in_count" "$configured_include_directive_count" "$config_group_or_world_writable"
    printf 'host_keys.types: %s\nhost_keys.fingerprint_collection_status: %s\nhost_keys.unsafe_permissions: %s\n' "$(IFS=,; printf '%s' "${host_key_types[*]:-not_available}")" "$host_key_fingerprint_status" "$host_key_unsafe_permissions"
    printf 'authorized_access.interactive_account_count: %s\nauthorized_access.root_authorized_key_count: %s\nauthorized_access.non_root_authorized_key_accounts: %s\nauthorized_access.non_root_sudo_capable: %s\n' "$interactive_account_count" "$root_authorized_key_count" "$non_root_authorized_key_accounts" "$non_root_sudo_capable"
    printf 'authentication_activity_24h.failed_password_attempts: %s\nauthentication_activity_24h.invalid_user_attempts: %s\nauthentication_activity_24h.unique_failed_source_count: %s\nauthentication_activity_24h.unique_failed_source_status: %s\nauthentication_activity_24h.current_ssh_session_count: %s\n' "$failed_password_attempts" "$invalid_user_attempts" "$unique_failed_source_count" "$unique_failed_source_status" "$current_ssh_session_count"
    printf 'firewall.installed: %s\nfirewall.active: %s\nfirewall.service_state: %s\nfirewall.command_state: %s\nfirewall.query_status: %s\nbrute_force_protection.fail2ban_installed: %s\nselinux.installed: %s\ncockpit.socket_exists: %s\n' "$firewalld_installed" "$firewalld_active" "$firewalld_service_state" "$firewalld_command_state" "$firewalld_query_status" "$fail2ban_installed" "$selinux_installed" "$cockpit_socket_exists"
    for finding in "${findings[@]}"; do
        IFS='|' read -r classification code message <<<"$finding"
        printf 'finding.%s: %s (%s)\n' "$classification" "$code" "$message"
    done
    printf 'verdict: %s\n' "$verdict"
}

json_report() {
    printf '{"schema":'; json_string "$AUDIT_SCHEMA"
    printf ',"mode":'; json_string "$AUDIT_MODE"
    printf ',"generated_at_utc":'; json_string "$generated_at_utc"
    printf ',"system":{"running_as_root":'; json_boolean "$running_as_root"
    printf ',"hostname":'; json_nullable "$hostname_value"
    printf ',"kernel_version":'; json_nullable "$kernel_version"
    printf ',"os_id":'; json_nullable "$os_id"
    printf ',"os_version":'; json_nullable "$os_version"; printf '}'
    printf ',"sshd_service":{"present":'; json_boolean "$sshd_present"
    printf ',"version":'; json_nullable "$sshd_version"
    printf ',"active":'; json_nullable "$sshd_active"
    printf ',"enabled":'; json_nullable "$sshd_enabled"
    printf ',"listening":'; json_nullable "$sshd_listening"
    printf ',"ports":'; json_string "$sshd_ports"; printf '}'
    printf ',"effective_configuration":{"status":'; json_string "$effective_config_status"
    printf ',"port":'; json_string "$sshd_ports"
    printf ',"permitrootlogin":'; json_nullable "$permit_root_login"
    printf ',"passwordauthentication":'; json_nullable "$password_authentication"
    printf ',"pubkeyauthentication":'; json_nullable "$public_key_authentication"
    printf ',"kbdinteractiveauthentication":'; json_nullable "$kbd_interactive_authentication"
    printf ',"challengeresponseauthentication":'; json_nullable "$challenge_response_authentication"
    printf ',"usepam":'; json_nullable "$use_pam"
    printf ',"authenticationmethods":'; json_nullable "$authentication_methods"
    printf ',"maxauthtries":'; json_nullable "$max_auth_tries"
    printf ',"maxsessions":'; json_nullable "$max_sessions"
    printf ',"logingracetime":'; json_nullable "$login_grace_time"
    printf ',"clientaliveinterval":'; json_nullable "$client_alive_interval"
    printf ',"clientalivecountmax":'; json_nullable "$client_alive_count_max"
    printf ',"x11forwarding":'; json_nullable "$x11_forwarding"
    printf ',"allowtcpforwarding":'; json_nullable "$allow_tcp_forwarding"
    printf ',"permituserenvironment":'; json_nullable "$permit_user_environment"
    printf ',"permituserrc":'; json_nullable "$permit_user_rc"
    printf ',"gssapiauthentication":'; json_nullable "$gssapi_authentication"
    printf ',"authorizedkeysfile":'; json_nullable "$authorized_keys_file"
    printf ',"strictmodes":'; json_nullable "$strict_modes"
    printf ',"permitemptypasswords":'; json_nullable "$permit_empty_passwords"
    printf ',"allow_users_configured":'; json_boolean "$allow_users_configured"
    printf ',"allow_groups_configured":'; json_boolean "$allow_groups_configured"
    printf ',"deny_users_configured":'; json_boolean "$deny_users_configured"
    printf ',"deny_groups_configured":'; json_boolean "$deny_groups_configured"; printf '}'
    printf ',"configuration_files":{"primary_exists":'; json_boolean "$primary_config_exists"
    printf ',"primary_owner":'; json_nullable "$primary_config_owner"
    printf ',"primary_group":'; json_nullable "$primary_config_group"
    printf ',"primary_mode":'; json_nullable "$primary_config_mode"
    printf ',"file_count":%s,"drop_in_count":%s,"include_directive_count":%s,"group_or_world_writable":' "$config_file_count" "$config_drop_in_count" "$configured_include_directive_count"; json_boolean "$config_group_or_world_writable"
    printf ',"unexpected_owner":'; json_boolean "$config_unexpected_owner"; printf '}'
    printf ',"host_keys":{"types":'; json_string_array host_key_types
    printf ',"metadata":'; json_string_array host_key_metadata
    printf ',"diagnostics":'; json_string_array host_key_diagnostics
    printf ',"fingerprints":'; json_string_array host_key_fingerprints
    printf ',"fingerprint_collection_status":'; json_string "$host_key_fingerprint_status"
    printf ',"unsafe_permissions":'; json_boolean "$host_key_unsafe_permissions"; printf '}'
    printf ',"authorized_access":{"interactive_account_count":%s,"root_authorized_keys_present":' "$interactive_account_count"; json_boolean "$root_authorized_keys_present"
    printf ',"root_authorized_key_count":%s,"root_authorized_key_fingerprints":' "$root_authorized_key_count"; json_string_array root_authorized_key_fingerprints
    printf ',"root_authorized_key_fingerprint_status":'; json_string "$root_authorized_key_fingerprint_status"
    printf ',"non_root_authorized_key_accounts":%s,"non_root_sudo_capable":' "$non_root_authorized_key_accounts"; json_boolean "$non_root_sudo_capable"; printf '}'
    printf ',"authentication_activity_24h":{"journal_available":'; json_boolean "$journal_available"
    printf ',"journal_status":'; json_string "$authentication_journal_status"
    printf ',"failed_password_attempts":%s,"invalid_user_attempts":%s,"accepted_public_key_logins":%s,"accepted_password_logins":%s,"root_login_successes":%s,"unique_failed_source_count":' "$failed_password_attempts" "$invalid_user_attempts" "$accepted_public_key_logins" "$accepted_password_logins" "$root_login_successes"
    if [[ "$unique_failed_source_status" == 'available' ]]; then
        printf '%s' "$unique_failed_source_count"
    else
        printf 'null'
    fi
    printf ',"unique_failed_source_status":'; json_string "$unique_failed_source_status"
    printf ',"current_ssh_session_count":%s}' "$current_ssh_session_count"
    printf ',"firewall":{"installed":'; json_boolean "$firewalld_installed"
    printf ',"active":'; json_nullable "$firewalld_active"
    printf ',"service_state":'; json_string "$firewalld_service_state"
    printf ',"command_state":'; json_string "$firewalld_command_state"
    printf ',"query_status":'; json_string "$firewalld_query_status"
    printf ',"enabled":'; json_nullable "$firewalld_enabled"
    printf ',"active_zones":'; json_nullable "$firewall_active_zones"
    printf ',"ssh_allowed":'; json_boolean "$firewall_ssh_allowed"
    printf ',"cockpit_allowed":'; json_boolean "$firewall_cockpit_allowed"
    printf ',"open_port_count":%s}' "$firewall_open_port_count"
    printf ',"brute_force_protection":{"fail2ban_installed":'; json_boolean "$fail2ban_installed"
    printf ',"active":'; json_nullable "$fail2ban_active"
    printf ',"enabled":'; json_nullable "$fail2ban_enabled"
    printf ',"sshd_jail_configured":'; json_boolean "$fail2ban_sshd_jail"
    printf ',"other_rate_limiting_detected":'; json_boolean "$other_rate_limiting_detected"; printf '}'
    printf ',"selinux":{"installed":'; json_boolean "$selinux_installed"
    printf ',"mode":'; json_nullable "$selinux_mode"
    printf ',"policy":'; json_nullable "$selinux_policy"; printf '}'
    printf ',"cockpit":{"socket_exists":'; json_boolean "$cockpit_socket_exists"
    printf ',"active":'; json_nullable "$cockpit_active"
    printf ',"enabled":'; json_nullable "$cockpit_enabled"
    printf ',"listening":'; json_nullable "$cockpit_listening"; printf '}'
    printf ',"findings":['
    finding_first='true'
    for finding in "${findings[@]}"; do
        IFS='|' read -r classification code message <<<"$finding"
        [[ "$finding_first" == 'true' ]] || printf ','
        printf '{"classification":'; json_string "$classification"
        printf ',"code":'; json_string "$code"
        printf ',"message":'; json_string "$message"; printf '}'
        finding_first='false'
    done
    printf '],"verdict":'; json_string "$verdict"
    printf '}\n'
}

if [[ "$format" == 'json' ]]; then
    json_report
else
    text_report
fi
