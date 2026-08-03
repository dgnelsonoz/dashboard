# Upgrade Bitcoin Core

Procedure for upgrading Bitcoin Core on this node using versioned binaries and a stable symlink.

---

### Overview

This node stores versioned Bitcoin Core binaries under `/data` and exposes the active binary via a symlink in `/usr/local/bin`. The `bitcoind` systemd service always runs the symlink target.

### Current Setup (Example)

```text
/data/bitcoin-29.2/
/data/bitcoin-30.0/

/usr/local/bin/bitcoind-29.2
/usr/local/bin/bitcoind-30.0
/usr/local/bin/bitcoind -> bitcoind-30.0
```

### Step 1 - Download New Version

Download the new release from the official Bitcoin Core website.

```bash
cd /data
wget https://bitcoincore.org/bin/bitcoin-30.1/bitcoin-30.1-x86_64-linux-gnu.tar.gz
```

### Step 2 - Verify Release

Verify the SHA256 checksums and PGP signature before extracting.

```bash
sha256sum bitcoin-30.1-x86_64-linux-gnu.tar.gz
gpg --verify SHA256SUMS.asc
```

Always confirm you are using trusted maintainer keys.

### Step 3 - Extract

```bash
tar -xzf bitcoin-30.1-x86_64-linux-gnu.tar.gz
```

This creates:

```text
/data/bitcoin-30.1/
```

### Step 4 - Install Versioned Binary

Copy the new binaries into `/usr/local/bin` with version suffix.

```bash
sudo cp /data/bitcoin-30.1/bin/bitcoind /usr/local/bin/bitcoind-30.1
sudo chmod +x /usr/local/bin/bitcoind-30.1
```

### Step 5 - Stop Bitcoin Service

```bash
sudo systemctl stop bitcoind
```

### Step 6 - Update Symlink

```bash
cd /usr/local/bin
sudo ln -sf bitcoind-30.1 bitcoind
```

Confirm:

```bash
ls -l bitcoind
```

### Step 7 - Start Service

```bash
sudo systemctl start bitcoind
```

### Verification

```bash
bitcoin-cli getnetworkinfo | grep version
systemctl status bitcoind
```

### Rollback Procedure

If necessary, revert to previous version:

```bash
sudo systemctl stop bitcoind
sudo ln -sf bitcoind-30.0 bitcoind
sudo systemctl start bitcoind
```

### Notes

* Never overwrite previous binaries.
* Keep at least one known-good previous version available.
* Always verify signatures before installing.

### Upstream Reference

* [Official Bitcoin Core Downloads](https://bitcoincore.org/en/download/)
