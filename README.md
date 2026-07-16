# Echo Platform OS

Echo Platform OS is the operations system behind Echo Motorworks. It brings catalog health, supplier connections, sync previews, image intelligence, reviews, automation, fitment, garage, reports, and Mission Control into one WordPress/WooCommerce platform.

## Source of truth

This GitHub repository is the master copy. The installable WordPress plugin lives in `echo-platform/`. Release ZIPs are generated from that folder.

## Repository map

- `echo-platform/` — WordPress plugin source
- `docs/` — architecture, roadmap, APIs, connectors, modules, database, and operating procedures
- `tools/` — release build scripts
- `tests/` — test instructions and future automated tests
- `build/` — generated installable ZIPs
- `.github/` — validation workflow and contribution templates

## Current working baseline

The repository began from the confirmed working Echo Platform OS supplier-sync release. Preserve working behavior while the code is gradually reorganized into formal Core OS modules.

## Build a WordPress ZIP

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-release.ps1
```

macOS or Linux:

```bash
bash tools/build-release.sh
```

The generated ZIP appears in `build/`.

## Safety rules

- Never commit credentials, customer exports, private supplier feeds, or WordPress configuration files.
- Never permanently delete products automatically.
- Preview risky supplier and catalog changes before applying them.
- Preserve existing data during upgrades.
- Test replacement installs on staging before production.

See `CONTRIBUTING.md`, `SECURITY.md`, and `docs/operations/RELEASE-CHECKLIST.md` before major changes.
