# Echo Platform OS

Echo Platform OS is the operations layer for Echo Motorworks. It unifies catalog health, suppliers, sync previews, image intelligence, fitment, garage, reviews, automation, notifications, and Mission Control inside WordPress/WooCommerce.

## Current baseline

This repository begins from the last confirmed working release:

- Echo Platform OS 1.2.0
- Supplier Sync Engine
- WordPress plugin folder: `echo-platform/`

## Repository layout

- `echo-platform/` — installable WordPress plugin source
- `docs/` — vision, roadmap, architecture, and release process
- `tools/` — local build scripts
- `build/` — generated release ZIPs (ignored by Git except `.gitkeep`)
- `.github/workflows/` — automated validation and release packaging

## Local build

### Windows

Run:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-release.ps1
```

### macOS / Linux

Run:

```bash
bash tools/build-release.sh
```

The generated WordPress ZIP will appear in `build/`.

## WordPress installation

1. Build or download a release ZIP.
2. In WordPress, go to **Plugins → Add Plugin → Upload Plugin**.
3. Upload the release ZIP.
4. Choose **Replace current with uploaded** when prompted.
5. Open **Echo Platform → Mission Control**.

## Safety rules

- Never commit API keys, passwords, tokens, private supplier feeds, or customer exports.
- Never auto-delete products.
- Preview supplier changes before applying them.
- Back up WordPress before production upgrades.

## Branching

- `main` — stable source
- `feature/<name>` — active development
- `release/<version>` — release preparation
- tags such as `v1.2.0` — shipped versions
