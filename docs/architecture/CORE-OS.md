# Core OS Architecture

The Core OS owns bootstrapping, module loading, permissions, events, background jobs, logging, settings, migrations, and shared UI services.

Modules should communicate through stable services and events rather than directly reaching into another module's internal classes.

## Architectural boundaries

- Core: shared infrastructure only.
- Modules: business capabilities such as Catalog, Suppliers, Images, Reviews, and Vehicles.
- Connectors: supplier-specific authentication, discovery, mapping, and fetch behavior.
- WooCommerce adapters: product, order, taxonomy, and media operations.
- Admin UI: presentation and user workflows.

## Non-negotiable safety behavior

- No silent permanent deletion.
- Risky changes require preview or explicit automation rules.
- Background work must be resumable and logged.
- Migrations must be idempotent.
