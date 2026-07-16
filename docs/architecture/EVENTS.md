# Event Bus

The event bus lets modules react to platform changes without hard dependencies.

Initial event names:

- `echo_product_imported`
- `echo_product_updated`
- `echo_supplier_sync_started`
- `echo_supplier_sync_completed`
- `echo_supplier_sync_failed`
- `echo_image_candidate_found`
- `echo_review_item_created`
- `echo_review_item_resolved`
- `echo_health_scan_completed`
- `echo_background_job_failed`

Every event should include a timestamp, source module, actor, correlation ID, and sanitized context payload.
