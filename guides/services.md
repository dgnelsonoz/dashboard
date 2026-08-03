# Service Commands

Quick commands for common services.

### Status

```bash
systemctl status bitcoind
systemctl status electrs
```

### Logs

```bash
journalctl -u bitcoind -n 200 --no-pager
journalctl -u electrs  -n 200 --no-pager
```

### Restart

```bash
sudo systemctl restart bitcoind
sudo systemctl restart electrs
```
