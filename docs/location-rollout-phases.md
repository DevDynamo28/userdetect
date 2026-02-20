# Passive Location Accuracy Rollout (No Permission Prompts)

This rollout keeps detection fully passive. Do not use `navigator.geolocation` or any browser location-permission flow.

## Phase 1 - Data Integrity and Safety

### To-do
- [x] Scope IP-range learning by tenant (`client_id`) to prevent cross-customer contamination.
- [x] Add verified-location fields to detections (`is_location_verified`, `verification_source`).
- [x] Require verified labels before self-learning by default (`LEARNING_REQUIRE_VERIFIED_LOCATION=true`).
- [x] Resolve client IP using trusted edge headers (`CF-Connecting-IP`, `X-Real-IP`) only when request is Worker-forwarded.
- [x] Make learning queries portable across PostgreSQL and SQLite.
- [x] Disable insecure ensemble sources by default.

### Validation checklist
- Run `php artisan migrate` successfully.
- Run detection API test suite and confirm no SQL errors from `ip_range_learnings`.
- Verify responses still return location payload for normal requests.
- Verify learning job does not run unless `signals.location_verification` is provided.
- Verify no browser permission prompt appears during SDK use.

## Phase 2 - Passive Signal Quality Improvements

### To-do
- [x] Send `language_analysis` from SDK based on `navigator.languages`.
- [x] Send `regional_fonts` from SDK as passive font-signal evidence.
- [x] Improve fusion fallback trigger to call ensemble on weak/disagreeing city evidence.
- [x] Reuse reverse-DNS hostname from fusion to avoid duplicate DNS lookup.
- [x] Propagate country from evidence instead of hardcoding everywhere.

### Validation checklist
- Run unit tests for ensemble and feature tests for detect endpoint.
- Use a sample request and verify `diagnostics.quality_telemetry.fallback_reason` is set when city evidence is weak.
- Confirm `location.country` is sourced from evidence when available.
- Confirm request latency remains within SLO targets under normal test traffic.

## Phase 3 - Operations and Ongoing Accuracy Measurement

### To-do
- [x] Schedule weekly GeoIP database refresh (`geoip:update`).
- [x] Add dashboard/API metrics for:
  - City-level hit rate by method
  - Low-confidence rate
  - Method disagreement rate
  - Verified-label accuracy (when verification is provided)
- [x] Add a backfill/annotation flow for verified city labels from checkout/profile events.

### Validation checklist
- Verify scheduler has `data:cleanup-old`, `model:prune`, and `geoip:update`.
- Verify GeoIP refresh logs success/failure and does not break existing jobs.
- Verify analytics queries can segment by `is_location_verified` and `verification_source`.
- Verify `POST /api/v1/user/{fingerprintId}/verify-location` updates recent detections and returns method-wise match rates.
- Run `php artisan test --no-ansi` and confirm feature coverage for analytics and verification endpoints.

## Hard Constraint

- Never request browser location permission.
- Keep location inference passive (IP, headers, DNS, language, fonts, historical patterns).
