# Dashboard

Dashboard is a lightweight web interface for monitoring a self-hosted Bitcoin and Lightning node.

It provides a simple, dependency-free interface for viewing the status of Bitcoin Core and related services, including Electrs, LND, Mempool, Ride The Lightning (RTL), and BTC RPC Explorer.

Designed for Debian-based systems, Dashboard is intended for trusted, self-hosted environments.

![Dashboard Home](docs/screenshots/home.png)

## Features

- Monitor Bitcoin Core, Electrs and LND
- Launch integrated node applications
- View blockchain and Lightning information
- Simple service status indicators
- Safe system shutdown
- Lightweight PHP implementation with no database or framework

![Mempool Integration](docs/screenshots/mempool.png)

## Documentation

Complete installation and configuration instructions are available from the **Bitcoin Node Workshop**:

**https://nelson.au**

The workshop provides a step-by-step guide to building a Debian-based Bitcoin node, including installation and configuration of all supported services.

## Demo mode

The same codebase can run as a public, read-only demonstration. Enable it with
the `DASHBOARD_MODE` environment variable:

```bash
DASHBOARD_MODE=demo php -S 127.0.0.1:8080 -t public
```

In demo mode, the home page uses fixed sample data, Lightning and Explorer show
static previews, Mempool links to `mempool.space`, and shutdown is disabled. All
system-status and shutdown API endpoints return HTTP 404 without inspecting the
host system.

Live mode remains the default when `DASHBOARD_MODE` is absent or has any value
other than `demo`. For Apache, enable the public demo inside its virtual host:

```apache
SetEnv DASHBOARD_MODE demo
```

The supplied preview images are stored in `public/assets/demo/` and can be
replaced later while retaining the existing filenames.

## License

MIT License
