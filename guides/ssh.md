# SSH Remote Access

We can remotely log into the node server from another computer on the local network using the SSH protocol. This allows us to install and configure software, perform maintenance, and run diagnostics. We do this from the Terminal application on macOS or Linux, or from PowerShell on Windows, using the command-line interface.

Open the Terminal program on Linux or MacOS, or PowerShell on Windows. Use you own username and hostname if they are different. You might have to use the node's IP address if the hostname doesn't respond.

```bash
ssh nelson@bitcoin-node.local
```
```
ssh youruser@<node-hostname-or-ip>
```

Login as the root superuser.

```bash
su - root
```

Run a check on Bitcoin Core:

```bash
systemctl status bitcoind
```
You can do the same with the other services:
```
systemctl status electrs (Electrum Server)
systemctl status lnd (Lightning node)
systemctl status rtl (Ride The Lightning)
systemctl status mempool (Mempool)
systemctl status explorer (BTC RPC Explorer)
```
Ctrl-C will exit  most commands if you get stuck in it.

You can query Bitcoin Core with the  bitcoin-cli command.
```
bitcoin-cli -datadir=/var/lib/bitcoin getblockchaininfo
bitcoin-cli -datadir=/var/lib/bitcoin getnetworkinfo
```
