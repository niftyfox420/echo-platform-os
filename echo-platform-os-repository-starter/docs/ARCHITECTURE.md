# Architecture

The current plugin is kept intact in `echo-platform/` so the repository begins from the last working release.

Future refactoring should happen incrementally, not as a one-shot rewrite.

## Target modules

- Core
- Mission Control
- Catalog
- Suppliers
- Review
- Automation
- Images
- Vehicles
- Reports
- Connectors

## Rules

- Keep activation safe and idempotent.
- Guard every class and function against duplicate declarations.
- Run database migrations by version.
- Use background jobs for long-running operations.
- Send uncertain changes to Review Center.
- Avoid direct coupling between modules where events can be used.
