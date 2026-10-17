#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly PROGRAM=${0##*/}
readonly SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
readonly CONF_DIR="$SCRIPT_DIR/conf"
readonly REPOSITORY_URL=https://github.com/entropy39/btc-rpc-explorer.git
readonly KEY_URL=https://github.com/dgnelsonoz.gpg
readonly TRUSTED_FINGERPRINT=FF1D7E574592ACB0CE68FED62536465B3430EEB0
readonly TAG_PATTERN='^v[0-9]+\.[0-9]+\.[0-9]+-entropy39\.[0-9]+$'

readonly INSTALL_DIR=/usr/local/lib/btc-rpc-explorer
readonly ENV_FILE=/etc/btc-rpc-explorer.env
readonly SERVICE_FILE=/etc/systemd/system/explorer.service
readonly APACHE_SITE=/etc/apache2/sites-available/explorer.conf
readonly APACHE_PORTS=/etc/apache2/ports.conf
readonly BITCOIN_CONF=/etc/bitcoin.conf
readonly DASHBOARD_DIR=/srv/www/dashboard
readonly STATE_DIR=/var/lib/entropy39-node
readonly WORK_DIR=$STATE_DIR/explorer-install-work
readonly MARKER=$STATE_DIR/explorer-install-incomplete
readonly TAG_RECORD=$STATE_DIR/explorer-installed-tag
readonly COMMIT_RECORD=$STATE_DIR/explorer-installed-commit
readonly UPDATE_BACKUP=/usr/local/lib/.btc-rpc-explorer-update-backup

MODE=
PASSWORD_FILE=
SELECTED_TAG=
SELECTED_COMMIT=
OLD_TAG=
OLD_COMMIT=
WEB_PASSWORD=
RPCAUTH_LINE=
FAILURE_ARMED=no

say() { printf '==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<EOF
Usage:
  $PROGRAM --install [--password-file FILE]
  $PROGRAM --update

Install or update BTC RPC Explorer for Entropy39 Node.

Options:
  --install              Perform a fresh installation
  --update               Update an installation made by this installer
  --password-file FILE   Read the Explorer web password from a root-owned
                         mode-600 file (install mode only)
  --help                 Show this help
EOF
}

parse_args() {
    while (($#)); do
        case $1 in
            --install|--update)
                [[ -z $MODE ]] || die "Choose exactly one of --install and --update."
                MODE=${1#--}
                shift
                ;;
            --password-file)
                (($# >= 2)) || die "--password-file requires a path."
                PASSWORD_FILE=$2
                shift 2
                ;;
            --help|-h) usage; exit 0 ;;
            *) die "Unknown option: $1 (see --help)." ;;
        esac
    done
    [[ -n $MODE ]] || die "Choose exactly one of --install and --update."
    [[ $MODE == install || -z $PASSWORD_FILE ]] || die "--password-file is valid only with --install."
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

require_root() {
    [[ $EUID -eq 0 ]] || die "This installer must be run as root."
}

acquire_lock() {
    exec 9>/run/lock/entropy39-explorer-install.lock
    flock -n 9 || die "Another Explorer install or update is already running."
}

check_platform() {
    [[ -r /etc/os-release ]] || die "Cannot identify the operating system."
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ ${ID:-} == debian && ${VERSION_ID:-} == 13 ]] || die "This installer supports Debian 13 only."
    case $(dpkg --print-architecture) in
        amd64|arm64) ;;
        *) die "Unsupported architecture: $(dpkg --print-architecture)" ;;
    esac
    [[ -d /run/systemd/system ]] || die "systemd is not running."
}

check_templates() {
    [[ -f $CONF_DIR/btc-rpc-explorer.env ]] || die "Missing Explorer environment template."
    [[ -f $CONF_DIR/explorer.service ]] || die "Missing Explorer service template."
    [[ -f $CONF_DIR/explorer.conf ]] || die "Missing Explorer Apache template."
    [[ $(grep -Foc 'RPC-PASSWORD-HERE' "$CONF_DIR/btc-rpc-explorer.env") -eq 1 ]] || \
        die "The environment template must contain exactly one RPC password placeholder."
    [[ $(grep -Foc 'EXPLORER-PASSWORD-HERE' "$CONF_DIR/btc-rpc-explorer.env") -eq 1 ]] || \
        die "The environment template must contain exactly one web password placeholder."
}

install_dependencies() {
    say "Installing Explorer dependencies"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-install-recommends ca-certificates curl git gnupg nodejs npm python3
}

check_commands() {
    local command_name
    for command_name in a2enmod a2ensite apache2ctl curl dpkg-query flock getent git gpg groupadd id npm node pgrep python3 readlink runuser sha256sum stat systemctl useradd; do
        require_command "$command_name"
    done
    local node_major
    node_major=$(node --version | sed -n 's/^v\([0-9][0-9]*\).*/\1/p')
    [[ $node_major =~ ^[0-9]+$ && $node_major -ge 18 ]] || die "BTC RPC Explorer requires Node.js 18 or newer."
}

check_prerequisites() {
    [[ -d $DASHBOARD_DIR ]] || die "The Entropy39 Dashboard must be installed first."
    [[ -f $BITCOIN_CONF && ! -L $BITCOIN_CONF ]] || die "Bitcoin Core must be installed first: $BITCOIN_CONF is missing or unsafe."
    [[ -x /usr/local/bin/bitcoind ]] || die "Bitcoin Core must be installed first: bitcoind is missing."
    systemctl is-active --quiet bitcoind.service || die "Bitcoin Core must be running before Explorer is installed."
    dpkg-query -W -f='${Status}\n' apache2 2>/dev/null | grep -qx 'install ok installed' || \
        die "Apache2 must be installed before Explorer."
    systemctl is-active --quiet apache2 || die "Apache2 must be running before Explorer."
}

latest_tag() {
    local tags
    tags=$(git ls-remote --tags --refs "$REPOSITORY_URL" | awk '{sub("refs/tags/", "", $2); print $2}' | grep -E "$TAG_PATTERN" || true)
    [[ -n $tags ]] || die "No stable Entropy39 Explorer tags were found."
    printf '%s\n' "$tags" | sort -V | tail -n 1
}

prepare_keyring() {
    local key_file=$WORK_DIR/entropy39-signing-key.asc
    local fingerprint_count
    rm -rf -- "$WORK_DIR/gnupg"
    install -d -o root -g root -m 0700 "$WORK_DIR/gnupg"
    if [[ ! -f $key_file ]]; then
        curl --fail --location --silent --show-error "$KEY_URL" -o "$key_file"
    fi

    mapfile -t key_fingerprints < <(
        GNUPGHOME=$WORK_DIR/gnupg gpg --batch --with-colons --import-options show-only --import "$key_file" 2>/dev/null |
            awk -F: '$1 == "pub" {want=1; next} want && $1 == "fpr" {print $10; want=0}'
    )
    fingerprint_count=${#key_fingerprints[@]}
    [[ $fingerprint_count -eq 1 && ${key_fingerprints[0]} == "$TRUSTED_FINGERPRINT" ]] || \
        die "The downloaded signing key does not match the trusted Entropy39 fingerprint."
    GNUPGHOME=$WORK_DIR/gnupg gpg --batch --import "$key_file" >/dev/null 2>&1
}

verify_tag() {
    local repo=$1 tag=$2 status_file=$WORK_DIR/tag-status
    [[ $tag =~ $TAG_PATTERN ]] || die "Unexpected Explorer tag format: $tag"
    GNUPGHOME=$WORK_DIR/gnupg git -C "$repo" verify-tag --raw "$tag" 2>"$status_file" || \
        die "Explorer tag $tag does not have an acceptable signature."
    grep -Eq "^\[GNUPG:\] VALIDSIG [0-9A-F]+ .* ${TRUSTED_FINGERPRINT}$" "$status_file" || \
        die "Explorer tag $tag was not signed by the trusted Entropy39 primary key."
    if grep -Eq '^\[GNUPG:\] (BADSIG|ERRSIG|EXPSIG|EXPKEYSIG|REVKEYSIG|KEYREVOKED|KEYEXPIRED|SIGEXPIRED)' "$status_file"; then
        die "Explorer tag $tag has an expired, revoked, invalid, or otherwise unacceptable signature."
    fi
}

prepare_release() {
    local release_dir=$WORK_DIR/release
    SELECTED_TAG=$(latest_tag)
    say "Downloading and verifying BTC RPC Explorer $SELECTED_TAG"
    rm -rf -- "$release_dir"
    git clone --quiet --filter=blob:none --no-checkout "$REPOSITORY_URL" "$release_dir"
    prepare_keyring
    verify_tag "$release_dir" "$SELECTED_TAG"
    SELECTED_COMMIT=$(git -C "$release_dir" rev-parse "$SELECTED_TAG^{commit}")
    [[ $SELECTED_COMMIT =~ ^[0-9a-f]{40}$ ]] || die "Git returned an invalid Explorer commit."
    git -C "$release_dir" checkout --quiet --detach "$SELECTED_COMMIT"
    git -C "$release_dir" fsck --full --no-dangling >/dev/null || die "The Explorer Git data failed its integrity check."
    git -C "$release_dir" diff --quiet "$SELECTED_COMMIT" -- . || die "The Explorer checkout differs from its signed commit."
    [[ -f $release_dir/package.json && -f $release_dir/npm-shrinkwrap.json ]] || \
        die "The signed Explorer release lacks its package metadata or npm shrinkwrap file."
    say "Installing the locked production dependencies in staging"
    npm --prefix "$release_dir" ci --omit=dev --no-audit --no-fund
    # The installer uses a restrictive umask for credentials. Restore normal
    # read/execute permissions on application code before the service uses it.
    chmod -R u=rwX,go=rX "$release_dir"
}

validate_web_password() {
    [[ -n $WEB_PASSWORD ]] || die "The Explorer web password cannot be empty."
}

read_web_password() {
    local confirmation password_mode
    if [[ -n $PASSWORD_FILE ]]; then
        [[ -f $PASSWORD_FILE && ! -L $PASSWORD_FILE ]] || die "The password file must be a regular file, not a symbolic link."
        password_mode=$(stat -c '%U:%G:%a' "$PASSWORD_FILE")
        [[ $password_mode == root:root:600 ]] || die "The password file must be owned by root:root with mode 600."
        [[ $(wc -l <"$PASSWORD_FILE") -le 1 ]] || die "The password file must contain one line."
        IFS= read -r WEB_PASSWORD <"$PASSWORD_FILE" || [[ -n $WEB_PASSWORD ]]
    else
        [[ -t 0 && -t 1 ]] || die "Interactive installation requires a terminal or --password-file."
        read -r -s -p "Explorer web password: " WEB_PASSWORD
        printf '\n'
        read -r -s -p "Confirm Explorer web password: " confirmation
        printf '\n'
        [[ $WEB_PASSWORD == "$confirmation" ]] || die "The Explorer web passwords do not match."
    fi
    validate_web_password
}

active_rpcauth_script() {
    local bitcoind_path version_dir script
    bitcoind_path=$(readlink -f /usr/local/bin/bitcoind)
    version_dir=$(dirname "$(dirname "$bitcoind_path")")
    script=$version_dir/share/rpcauth/rpcauth.py
    [[ -x $script ]] || die "The active Bitcoin Core installation does not contain rpcauth.py."
    printf '%s\n' "$script"
}

stage_install_configuration() {
    local rpc_output rpc_password rpcauth_script
    read_web_password
    grep -q '^rpcauth=explorer:' "$BITCOIN_CONF" && die "Bitcoin Core already has an Explorer rpcauth entry."

    rpcauth_script=$(active_rpcauth_script)
    rpc_output=$WORK_DIR/rpcauth-output
    python3 "$rpcauth_script" explorer >"$rpc_output"
    RPCAUTH_LINE=$(grep '^rpcauth=explorer:' "$rpc_output")
    rpc_password=$(awk '/^Your password:/{getline; print; exit}' "$rpc_output")
    [[ -n $RPCAUTH_LINE && -n $rpc_password ]] || die "rpcauth.py did not produce the expected credentials."

    cp "$CONF_DIR/btc-rpc-explorer.env" "$WORK_DIR/btc-rpc-explorer.env"
    RPC_PASSWORD=$rpc_password WEB_PASSWORD=$WEB_PASSWORD awk '
        { gsub(/RPC-PASSWORD-HERE/, ENVIRON["RPC_PASSWORD"]); gsub(/EXPLORER-PASSWORD-HERE/, ENVIRON["WEB_PASSWORD"]); print }
    ' "$WORK_DIR/btc-rpc-explorer.env" >"$WORK_DIR/btc-rpc-explorer.env.new"
    mv "$WORK_DIR/btc-rpc-explorer.env.new" "$WORK_DIR/btc-rpc-explorer.env"
    chmod 0600 "$WORK_DIR/btc-rpc-explorer.env"

    cp --preserve=mode,ownership,timestamps "$BITCOIN_CONF" "$WORK_DIR/bitcoin.conf.original"
    cp "$BITCOIN_CONF" "$WORK_DIR/bitcoin.conf"
    printf '\n%s\n' "$RPCAUTH_LINE" >>"$WORK_DIR/bitcoin.conf"
    chmod 0600 "$WORK_DIR/bitcoin.conf" "$WORK_DIR/bitcoin.conf.original"

    cp "$CONF_DIR/explorer.service" "$WORK_DIR/explorer.service"
    cp "$CONF_DIR/explorer.conf" "$WORK_DIR/explorer.conf"
    cp --preserve=mode,ownership,timestamps "$APACHE_PORTS" "$WORK_DIR/ports.conf.original"
    cp "$APACHE_PORTS" "$WORK_DIR/ports.conf"
    if ! grep -Eq '^[[:space:]]*Listen[[:space:]]+4082([[:space:]]|$)' "$WORK_DIR/ports.conf"; then
        printf '\nListen 4082\n' >>"$WORK_DIR/ports.conf"
    fi
    chmod 0600 "$WORK_DIR"/*.{conf,service,original} 2>/dev/null || true
}

sha256() { sha256sum "$1" | awk '{print $1}'; }

write_marker() {
    local mode=$1 phase=$2
    install -d -o root -g root -m 0700 "$STATE_DIR"
    {
        printf 'mode=%s\n' "$mode"
        printf 'phase=%s\n' "$phase"
        printf 'tag=%s\n' "$SELECTED_TAG"
        printf 'commit=%s\n' "$SELECTED_COMMIT"
        [[ -z $OLD_TAG ]] || printf 'old_tag=%s\n' "$OLD_TAG"
        [[ -z $OLD_COMMIT ]] || printf 'old_commit=%s\n' "$OLD_COMMIT"
        if [[ $mode == install ]]; then
            printf 'env_sha256=%s\n' "$(sha256 "$WORK_DIR/btc-rpc-explorer.env")"
            printf 'bitcoin_sha256=%s\n' "$(sha256 "$WORK_DIR/bitcoin.conf")"
            printf 'bitcoin_original_sha256=%s\n' "$(sha256 "$WORK_DIR/bitcoin.conf.original")"
            printf 'service_sha256=%s\n' "$(sha256 "$WORK_DIR/explorer.service")"
            printf 'apache_sha256=%s\n' "$(sha256 "$WORK_DIR/explorer.conf")"
            printf 'ports_sha256=%s\n' "$(sha256 "$WORK_DIR/ports.conf")"
            printf 'ports_original_sha256=%s\n' "$(sha256 "$WORK_DIR/ports.conf.original")"
        fi
    } >"$MARKER"
    chmod 0600 "$MARKER"
}

marker_value() {
    local key=$1
    [[ $(grep -c "^${key}=" "$MARKER") -eq 1 ]] || return 1
    sed -n "s/^${key}=//p" "$MARKER"
}

verify_staged_file() {
    local file=$1 key=$2 expected
    expected=$(marker_value "$key") || die "The recovery marker is missing $key."
    [[ $expected =~ ^[0-9a-f]{64}$ && -f $file && $(sha256 "$file") == "$expected" ]] || \
        die "Staged recovery file failed verification: $file"
}

verify_release_tree() {
    local repo=$1 expected=$2 status
    [[ -d $repo/.git ]] || die "The staged Explorer release has no Git metadata."
    [[ $(git -C "$repo" rev-parse HEAD) == "$expected" ]] || die "The staged Explorer release is at an unexpected commit."
    git -C "$repo" fsck --full --no-dangling >/dev/null || die "The staged Explorer Git data failed verification."
    git -C "$repo" diff --quiet "$expected" -- . || die "Tracked files in the staged Explorer release were modified."
    status=$(git -C "$repo" status --porcelain --untracked-files=normal)
    [[ -z $status || $status == '?? node_modules/' ]] || die "Unexpected untracked files exist in the Explorer release."
}

ensure_explorer_account() {
    local entry group_entry
    group_entry=$(getent group explorer || true)
    if [[ -z $group_entry ]]; then
        groupadd --system explorer
    fi
    entry=$(getent passwd explorer || true)
    if [[ -z $entry ]]; then
        useradd --system --gid explorer --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin explorer
    else
        [[ $(id -gn explorer) == explorer ]] || die "Existing explorer user does not have primary group explorer."
        [[ $(getent passwd explorer | cut -d: -f6) == /nonexistent ]] || die "Existing explorer user has an unexpected home directory."
        [[ $(getent passwd explorer | cut -d: -f7) == /usr/sbin/nologin ]] || die "Existing explorer user has an unexpected login shell."
    fi
}

install_or_verify() {
    local source=$1 target=$2 owner=$3 group=$4 mode=$5 description=$6
    if [[ -e $target ]]; then
        [[ -f $target && ! -L $target && $(sha256 "$source") == $(sha256 "$target") ]] || \
            die "$target differs from the staged $description; it was not overwritten."
        [[ $(stat -c '%U:%G:%a' "$target") == "$owner:$group:${mode#0}" ]] || \
            die "$target has unexpected ownership or permissions; it was not overwritten."
    else
        atomic_install "$source" "$target" "$owner" "$group" "$mode"
    fi
}

atomic_install() {
    local source=$1 target=$2 owner=$3 group=$4 mode=$5 temporary=${target}.entropy39-new
    if [[ -e $temporary ]]; then
        [[ -f $temporary && ! -L $temporary && $(sha256 "$source") == $(sha256 "$temporary") ]] || \
            die "Unexpected interrupted-install file at $temporary; it was not overwritten."
    else
        install -o "$owner" -g "$group" -m "$mode" "$source" "$temporary"
    fi
    mv -f -- "$temporary" "$target"
}

install_bitcoin_configuration() {
    local current original staged
    current=$(sha256 "$BITCOIN_CONF")
    original=$(sha256 "$WORK_DIR/bitcoin.conf.original")
    staged=$(sha256 "$WORK_DIR/bitcoin.conf")
    if [[ $current == "$original" ]]; then
        atomic_install "$WORK_DIR/bitcoin.conf" "$BITCOIN_CONF" root bitcoin 0640
    elif [[ $current != "$staged" ]]; then
        die "$BITCOIN_CONF changed during installation; no RPC credential was overwritten."
    fi
}

install_ports_configuration() {
    local current original staged
    current=$(sha256 "$APACHE_PORTS")
    original=$(sha256 "$WORK_DIR/ports.conf.original")
    staged=$(sha256 "$WORK_DIR/ports.conf")
    if [[ $current == "$original" ]]; then
        atomic_install "$WORK_DIR/ports.conf" "$APACHE_PORTS" root root 0644
    elif [[ $current != "$staged" ]]; then
        die "$APACHE_PORTS changed during installation; it was not overwritten."
    fi
}

wait_for_explorer() {
    local attempt code
    for ((attempt=0; attempt<20; attempt++)); do
        code=$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:3002/ || true)
        [[ $code == 200 || $code == 401 ]] && return 0
        sleep 0.5
    done
    return 1
}

verify_running_installation() {
    systemctl is-active --quiet bitcoind.service || die "Bitcoin Core is not active after configuration."
    systemctl is-active --quiet explorer.service || die "Explorer is not active."
    systemctl is-enabled --quiet explorer.service || die "Explorer is not enabled."
    [[ $(stat -c '%U:%G:%a' "$ENV_FILE") == root:explorer:640 ]] || die "Explorer environment permissions are incorrect."
    [[ -r $INSTALL_DIR/package.json ]] || die "Explorer package metadata is missing."
    wait_for_explorer || die "Explorer did not respond on port 3002 within 10 seconds."
    local proxy_code
    proxy_code=$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:4082/ || true)
    [[ $proxy_code == 200 || $proxy_code == 401 ]] || die "The Explorer Apache proxy did not respond successfully."
}

failure_handler() {
    local result=$? recorded_mode current staged
    trap - EXIT ERR INT TERM HUP
    if [[ $FAILURE_ARMED == yes ]]; then
        warn "Explorer did not complete; verified staged files and the recovery marker were preserved."
        if [[ -e $SERVICE_FILE ]]; then
            systemctl stop explorer.service >/dev/null 2>&1 || warn "Explorer could not be stopped; installed files were preserved for recovery."
        fi
        recorded_mode=$(marker_value mode 2>/dev/null || true)
        if [[ $recorded_mode == install ]] && ! systemctl is-active --quiet explorer.service; then
            if [[ -f $WORK_DIR/bitcoin.conf && -f $WORK_DIR/bitcoin.conf.original && -f $BITCOIN_CONF ]]; then
                current=$(sha256 "$BITCOIN_CONF")
                staged=$(sha256 "$WORK_DIR/bitcoin.conf")
                if [[ $current == "$staged" ]]; then
                    atomic_install "$WORK_DIR/bitcoin.conf.original" "$BITCOIN_CONF" root bitcoin 0640 || true
                    systemctl restart bitcoind.service >/dev/null 2>&1 || warn "Bitcoin Core could not be restarted after restoring its original configuration."
                fi
            fi
            if [[ -f $WORK_DIR/ports.conf && -f $WORK_DIR/ports.conf.original && -f $APACHE_PORTS ]]; then
                current=$(sha256 "$APACHE_PORTS")
                staged=$(sha256 "$WORK_DIR/ports.conf")
                if [[ $current == "$staged" ]]; then
                    atomic_install "$WORK_DIR/ports.conf.original" "$APACHE_PORTS" root root 0644 || true
                fi
            fi
            a2dissite explorer.conf >/dev/null 2>&1 || true
            apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 >/dev/null 2>&1 || true
        fi
    elif [[ ! -e $MARKER && -d $WORK_DIR ]]; then
        rm -rf -- "$WORK_DIR"
    fi
    exit "$result"
}

complete_operation() {
    printf '%s\n' "$SELECTED_TAG" >"$TAG_RECORD"
    printf '%s\n' "$SELECTED_COMMIT" >"$COMMIT_RECORD"
    chown root:root "$TAG_RECORD" "$COMMIT_RECORD"
    chmod 0644 "$TAG_RECORD" "$COMMIT_RECORD"
    rm -f -- "$MARKER"
    rm -rf -- "$WORK_DIR" "$UPDATE_BACKUP"
    FAILURE_ARMED=no
}

commit_install() {
    ensure_explorer_account
    if [[ ! -e $INSTALL_DIR ]]; then
        mv "$WORK_DIR/release" "$INSTALL_DIR"
        chown -R root:root "$INSTALL_DIR"
    else
        verify_release_tree "$INSTALL_DIR" "$SELECTED_COMMIT"
    fi

    install_or_verify "$WORK_DIR/btc-rpc-explorer.env" "$ENV_FILE" root explorer 0640 "Explorer environment"
    install_or_verify "$WORK_DIR/explorer.service" "$SERVICE_FILE" root root 0644 "Explorer service"
    install_or_verify "$WORK_DIR/explorer.conf" "$APACHE_SITE" root root 0644 "Explorer Apache site"
    install_bitcoin_configuration
    install_ports_configuration

    a2enmod proxy proxy_http >/dev/null
    a2ensite explorer.conf >/dev/null
    apache2ctl configtest
    systemctl daemon-reload
    systemctl restart bitcoind.service
    systemctl is-active --quiet bitcoind.service || die "Bitcoin Core failed to restart with the Explorer RPC credential."
    systemctl enable --now explorer.service
    systemctl reload apache2
    verify_running_installation
    complete_operation
    say "BTC RPC Explorer $SELECTED_TAG was installed successfully."
}

fresh_install() {
    local path
    for path in "$INSTALL_DIR" "$ENV_FILE" "$SERVICE_FILE" "$APACHE_SITE" /etc/apache2/sites-enabled/explorer.conf "$TAG_RECORD" "$COMMIT_RECORD"; do
        [[ ! -e $path ]] || die "An existing Explorer installation or configuration was detected at $path. Nothing was changed."
    done
    ! pgrep -f '/usr/local/lib/btc-rpc-explorer' >/dev/null 2>&1 || \
        die "A BTC RPC Explorer process is already running. Nothing was changed."
    getent passwd explorer >/dev/null && die "An existing explorer account was detected without a recognized installation. Nothing was changed."
    [[ ! -e $UPDATE_BACKUP ]] || die "An unexpected Explorer update backup exists at $UPDATE_BACKUP."
    rm -rf -- "$WORK_DIR"
    install -d -o root -g root -m 0700 "$WORK_DIR"
    prepare_release
    stage_install_configuration
    write_marker install committing
    FAILURE_ARMED=yes
    commit_install
}

load_common_marker() {
    SELECTED_TAG=$(marker_value tag) || die "The recovery marker has no valid tag."
    SELECTED_COMMIT=$(marker_value commit) || die "The recovery marker has no valid commit."
    [[ $SELECTED_TAG =~ $TAG_PATTERN && $SELECTED_COMMIT =~ ^[0-9a-f]{40}$ ]] || die "The recovery marker contains invalid release data."
}

recover_install() {
    say "Recovering an interrupted Explorer installation"
    [[ -d $WORK_DIR && ! -L $WORK_DIR && $(stat -c '%U:%G:%a' "$WORK_DIR") == root:root:700 ]] || \
        die "The Explorer recovery directory is missing or unsafe."
    load_common_marker
    verify_staged_file "$WORK_DIR/btc-rpc-explorer.env" env_sha256
    verify_staged_file "$WORK_DIR/bitcoin.conf" bitcoin_sha256
    verify_staged_file "$WORK_DIR/bitcoin.conf.original" bitcoin_original_sha256
    verify_staged_file "$WORK_DIR/explorer.service" service_sha256
    verify_staged_file "$WORK_DIR/explorer.conf" apache_sha256
    verify_staged_file "$WORK_DIR/ports.conf" ports_sha256
    verify_staged_file "$WORK_DIR/ports.conf.original" ports_original_sha256
    prepare_keyring
    if [[ -d $WORK_DIR/release ]]; then
        verify_tag "$WORK_DIR/release" "$SELECTED_TAG"
        verify_release_tree "$WORK_DIR/release" "$SELECTED_COMMIT"
    elif [[ -d $INSTALL_DIR ]]; then
        verify_tag "$INSTALL_DIR" "$SELECTED_TAG"
        verify_release_tree "$INSTALL_DIR" "$SELECTED_COMMIT"
    else
        die "The authenticated Explorer release is missing."
    fi
    FAILURE_ARMED=yes
    commit_install
}

verify_existing_update_source() {
    [[ -d $INSTALL_DIR/.git ]] || die "The Explorer installation has no Git metadata and cannot be updated safely."
    [[ -f $TAG_RECORD && -f $COMMIT_RECORD ]] || die "This Explorer installation was not recorded by the installer and cannot be updated automatically."
    OLD_TAG=$(<"$TAG_RECORD")
    OLD_COMMIT=$(<"$COMMIT_RECORD")
    [[ $OLD_TAG =~ $TAG_PATTERN && $OLD_COMMIT =~ ^[0-9a-f]{40}$ ]] || die "The installed Explorer release record is invalid."
    [[ $(git -C "$INSTALL_DIR" rev-parse HEAD) == "$OLD_COMMIT" ]] || die "The Explorer checkout does not match its recorded commit."
    verify_release_tree "$INSTALL_DIR" "$OLD_COMMIT"
}

rollback_update() {
    warn "The Explorer update failed; restoring the previous verified checkout."
    systemctl stop explorer.service >/dev/null 2>&1 || true
    if [[ -d $UPDATE_BACKUP ]]; then
        [[ ! -e $WORK_DIR/failed-release ]] || rm -rf -- "$WORK_DIR/failed-release"
        [[ ! -e $INSTALL_DIR ]] || mv "$INSTALL_DIR" "$WORK_DIR/failed-release"
        mv "$UPDATE_BACKUP" "$INSTALL_DIR"
        if systemctl start explorer.service && systemctl is-active --quiet explorer.service && wait_for_explorer; then
            rm -f -- "$MARKER"
            rm -rf -- "$WORK_DIR"
            FAILURE_ARMED=no
            return 0
        fi
        warn "The previous Explorer checkout was restored but did not start; preserving both releases for recovery."
        systemctl stop explorer.service >/dev/null 2>&1 || true
        mv "$INSTALL_DIR" "$UPDATE_BACKUP"
        mv "$WORK_DIR/failed-release" "$INSTALL_DIR"
    fi
    return 1
}

commit_update() {
    systemctl stop explorer.service || die "Explorer could not be stopped; no application files were changed."
    [[ ! -e $UPDATE_BACKUP ]] || die "The Explorer update backup path already exists."
    mv "$INSTALL_DIR" "$UPDATE_BACKUP"
    write_marker update swapped
    mv "$WORK_DIR/release" "$INSTALL_DIR"
    chown -R root:root "$INSTALL_DIR"
    if ! systemctl start explorer.service || ! systemctl is-active --quiet explorer.service || ! wait_for_explorer; then
        rollback_update || true
        die "The new Explorer release failed startup verification; the previous checkout was restored."
    fi
    complete_operation
    say "BTC RPC Explorer was updated from $OLD_TAG to $SELECTED_TAG successfully."
}

update_installation() {
    verify_existing_update_source
    [[ -f $ENV_FILE && -f $SERVICE_FILE && -f $APACHE_SITE ]] || die "The Explorer configuration is incomplete; update was refused."
    [[ ! -e $UPDATE_BACKUP ]] || die "An unexpected Explorer update backup exists at $UPDATE_BACKUP."
    rm -rf -- "$WORK_DIR"
    install -d -o root -g root -m 0700 "$WORK_DIR"
    prepare_release
    verify_tag "$INSTALL_DIR" "$OLD_TAG"
    [[ $(git -C "$INSTALL_DIR" rev-parse "$OLD_TAG^{commit}") == "$OLD_COMMIT" ]] || \
        die "The recorded Explorer tag no longer resolves to its installed commit."
    if [[ $SELECTED_COMMIT == "$OLD_COMMIT" ]]; then
        rm -rf -- "$WORK_DIR"
        say "BTC RPC Explorer is already current at $SELECTED_TAG."
        return
    fi
    write_marker update prepared
    FAILURE_ARMED=yes
    commit_update
}

recover_update() {
    local phase
    say "Recovering an interrupted Explorer update"
    load_common_marker
    OLD_TAG=$(marker_value old_tag) || die "The recovery marker has no previous tag."
    OLD_COMMIT=$(marker_value old_commit) || die "The recovery marker has no previous commit."
    phase=$(marker_value phase) || die "The recovery marker has no phase."
    [[ $OLD_TAG =~ $TAG_PATTERN && $OLD_COMMIT =~ ^[0-9a-f]{40}$ ]] || die "The recovery marker contains invalid previous-release data."
    prepare_keyring

    if [[ $phase == prepared ]]; then
        if [[ -d $UPDATE_BACKUP && ! -e $INSTALL_DIR ]]; then
            verify_tag "$UPDATE_BACKUP" "$OLD_TAG"
            [[ $(git -C "$UPDATE_BACKUP" rev-parse "$OLD_TAG^{commit}") == "$OLD_COMMIT" ]] || \
                die "The previous Explorer tag does not resolve to its recorded commit."
            verify_release_tree "$UPDATE_BACKUP" "$OLD_COMMIT"
            verify_tag "$WORK_DIR/release" "$SELECTED_TAG"
            verify_release_tree "$WORK_DIR/release" "$SELECTED_COMMIT"
            write_marker update swapped
            mv "$WORK_DIR/release" "$INSTALL_DIR"
            chown -R root:root "$INSTALL_DIR"
            if ! systemctl start explorer.service || ! systemctl is-active --quiet explorer.service || ! wait_for_explorer; then
                rollback_update || true
                die "The interrupted update failed verification; the previous checkout was restored."
            fi
            complete_operation
            say "Interrupted Explorer update to $SELECTED_TAG recovered successfully."
            return
        fi
        verify_tag "$INSTALL_DIR" "$OLD_TAG"
        [[ $(git -C "$INSTALL_DIR" rev-parse "$OLD_TAG^{commit}") == "$OLD_COMMIT" ]] || \
            die "The previous Explorer tag does not resolve to its recorded commit."
        verify_release_tree "$INSTALL_DIR" "$OLD_COMMIT"
        verify_tag "$WORK_DIR/release" "$SELECTED_TAG"
        verify_release_tree "$WORK_DIR/release" "$SELECTED_COMMIT"
        FAILURE_ARMED=yes
        commit_update
        return
    fi
    [[ $phase == swapped ]] || die "Unknown Explorer update recovery phase: $phase"
    [[ -d $UPDATE_BACKUP ]] || die "The previous Explorer checkout is missing; no files were changed."
    verify_tag "$UPDATE_BACKUP" "$OLD_TAG"
    [[ $(git -C "$UPDATE_BACKUP" rev-parse "$OLD_TAG^{commit}") == "$OLD_COMMIT" ]] || \
        die "The previous Explorer tag does not resolve to its recorded commit."
    verify_release_tree "$UPDATE_BACKUP" "$OLD_COMMIT"
    if [[ -d $INSTALL_DIR ]]; then
        verify_tag "$INSTALL_DIR" "$SELECTED_TAG"
        verify_release_tree "$INSTALL_DIR" "$SELECTED_COMMIT"
    elif [[ -d $WORK_DIR/release ]]; then
        verify_tag "$WORK_DIR/release" "$SELECTED_TAG"
        verify_release_tree "$WORK_DIR/release" "$SELECTED_COMMIT"
        mv "$WORK_DIR/release" "$INSTALL_DIR"
        chown -R root:root "$INSTALL_DIR"
    else
        die "The new authenticated Explorer checkout is missing; the previous checkout was preserved."
    fi
    if ! systemctl start explorer.service || ! systemctl is-active --quiet explorer.service || ! wait_for_explorer; then
        rollback_update || true
        die "The interrupted update failed verification; the previous checkout was restored."
    fi
    complete_operation
    say "Interrupted Explorer update to $SELECTED_TAG recovered successfully."
}

recover_if_needed() {
    local recorded_mode
    [[ -e $MARKER ]] || return 1
    recorded_mode=$(marker_value mode) || die "The Explorer recovery marker has no mode."
    [[ $MODE == "$recorded_mode" ]] || die "An interrupted Explorer $recorded_mode operation exists; rerun with --$recorded_mode."
    case $recorded_mode in
        install) recover_install ;;
        update) recover_update ;;
        *) die "Unknown Explorer recovery mode: $recorded_mode" ;;
    esac
    return 0
}

main() {
    parse_args "$@"
    require_root
    check_platform
    check_templates
    require_command flock
    acquire_lock
    trap failure_handler EXIT ERR INT TERM HUP

    if [[ -e $MARKER ]]; then
        check_commands
        check_prerequisites
        recover_if_needed
        return
    fi

    install_dependencies
    check_commands
    check_prerequisites
    case $MODE in
        install) fresh_install ;;
        update) update_installation ;;
    esac
}

main "$@"
