# Contributing to Echo Platform OS

Echo Platform OS is developed as a stable operations system for WordPress and WooCommerce.

## Core rules

1. Keep `main` installable and stable.
2. Never commit credentials, customer data, private supplier exports, or private feed URLs.
3. Preserve existing catalog and supplier data during upgrades.
4. Do not permanently delete products automatically.
5. Preview and log risky changes before applying them.
6. Keep supplier-specific logic inside connectors; shared logic belongs in engines.
7. Update documentation and `CHANGELOG.md` with meaningful changes.

## Preferred workflow

- Small fixes may be committed directly while the project has one maintainer.
- Larger work should use `feature/<short-name>` branches.
- Validate PHP before packaging.
- Build release ZIPs with the scripts in `tools/`.

## Definition of done

A change is complete when it activates without fatal errors, passes syntax validation, preserves existing data, has clear test steps, and updates relevant documentation.
