# QC & Lab Workflow Procedure

This guide connects every QC and laboratory touchpoint inside `geotex`, from creating a test order to final approval and reporting. Use it as a quick reference when onboarding, auditing, or extending the workflow.

## Module Inventory

- **Forms (tester entry)**
  - `forms/qc_test_order.php` – single-source entry for ASTM/ISO mechanical tests (thickness, GSM, tensile, CBR, grab, weathering, seam).
  - `forms/qc_test.php`, `forms/qc_entry.php`, `forms/submit_qc_entry.php` – legacy QC entry/editors that still feed historical reports.
  - `forms/characteristics_test.php` – ISO 12956 characteristics tests with sieve data capture and bundle-aware referencing.
  - `forms/water_permeability_test.php` – ISO 11058 water permeability with dynamic specimen rows and sample ID generator.
  - `forms/sun_test_report.php`, `forms/uv_test_enhanced.php`, `forms/weathering_exposure_test*.php` – exposure tests (sunlight & UV) including bundle roll handling.
  - `forms/fabric_pre_production_test.php`, `forms/fabric_after_production_test.php` – stage-specific fabric validations tied to customer/product references.
  - `forms/fiber_test_report.php`, `forms/fiber_entry.php`, `forms/fiber_to_roll_entry.php` – raw material intake and lab verification.
  - `forms/material_consumption_entry.php`, `forms/project_entry.php`, `forms/production_entry.php` – upstream production context that seeds reference numbers for QC.
- **Admin & dashboards**
  - `admin/lab_testing_dashboard.php` – checker-only queue that merges QC orders, fabric, water, and characteristics tests.
  - `admin/qc_reports_dashboard.php` – AGM/Admin approval center for every lab table plus sewing, UV, fiber, fabric, sun, water, characteristics.
  - `admin/qc_approval_dashboard.php`, `admin/qc_test_approval_dashboard.php`, `admin/view_qc_test_order.php`, `admin/view_qc_entry.php` – focused review & audit screens.
  - `reports/qc_inspection_report.php`, `reports/qc_pass_fail_trend.php`, `reports/stage_wise_stock_qc_report.php` – downstream analytics.
- **APIs & helpers (`forms/api/`)**
  - Reference fetchers: `get_qc_references.php`, `get_reference_data.php`, `get_references_list.php`.
  - Bundle/test snapshots: `get_first_bundle_test_data_qc.php`, `get_first_bundle_test_data_char.php`, `get_first_bundle_test_data_wpt.php`, `get_first_bundle_test_data_sun.php`, `get_first_bundle_test_data_uv.php`.
  - Summary feeds for dashboards: `get_qc_test_summary.php`, `get_characteristics_test_summary.php`, `get_water_permeability_summary.php`, `get_sun_test_summary.php`, `get_uv_test_summary.php`, `get_fiber_test_summary.php`.
  - Roll/test status checks: `get_tested_rolls.php` plus the `_char`, `_sun`, `_uv`, `_wpt` variants.

## Roles & Access Control

- **Tester/QC Inspector**: allowed to submit orders and lab data (`tester`, `qc_inspector` roles). Forms enforce session checks and `AccessControl::hasModuleAccess`.
- **Checker**: reviews results first (`checker` role); sees `admin/lab_testing_dashboard.php` and in-form pending lists. Actions set status to `checked`/`pending_approval`.
- **Admin / AGM Ops**: final approval inside `admin/qc_reports_dashboard.php`. Roles: `admin`, `agm ops`, `agm operations`.
- **Management/Read-only**: certain dashboards (e.g., water permeability form) whitelist `management`.
- Sessions rely on `SecurityConfig` (timeout, lock, role gating), so all workflows inherit the same login and anti-cache behavior.

## End-to-End Procedure

### 1. Seed Production & References

1. **Roll/Store entries** (`forms/roll_entry.php`, `forms/roll_received_entry.php`, `forms/store_received_entry.php`) capture `reference_number`, bundle suffixes (`-N`), weights, and material metadata.
2. **Associated entries** (`forms/fiber_entry.php`, `forms/fiber_to_roll_entry.php`, `forms/production_entry.php`) attach fiber/yarn references, feeding duplicate-prevention logic inside `qc_test_order.php`.
3. **Auto-generated data**: session variables like `$_SESSION['last_roll_entry_reference']` and tables such as `user_qc_preferences` help auto-fill future test orders.

### 2. Create QC Test Order (`forms/qc_test_order.php`)

- **Inputs**
  - Reference selection from `roll_entry` (individual roll vs bundle) or external references (`EXT-YYYYMMDD-###`).
  - Product/fiber/yarn references, customer details, GSM, roll count, remarks, attachments, photos (handled via JSON in `test_data`).
  - Single test selection enforced from defined set (`$test_methods`), covering ASTM/ISO combinations for core mechanical tests.
- **Automations & Validation**
  - Sample reference generation (`generateSampleReferenceId()`) and external reference generator endpoint (`action=generate_external_ref`).
  - Duplicate guard builds a `sample_reference_id => [test_methods]` map from `qc_test_orders`.
  - External API usage: `forms/api/get_qc_references.php`, `get_qc_test_summary.php`, `get_tested_rolls.php` for quick lookups; `get_first_bundle_test_data_qc.php` preloads existing bundle data.
  - Preferences: merges DB entries from `user_qc_preferences` with session caches.
- **Persistence & Status**
  - Saves to `qc_test_orders` with JSON `test_data`, `test_standard_id`, `report_number`.
  - Status lifecycle: `pending_checker` → `pending_approval` → `approved` (`rejected_by_checker` / `rejected_by_approver` loops testers back into edit mode).
  - Role-specific views: testers can edit rejected records; checkers/admins have inline queues; `admin/view_qc_test_order.php` renders detail for audit.

### 3. Execute Lab Tests (by module)

- **Characteristics Test (`forms/characteristics_test.php`)**
  - Auto-creates `characteristics_tests` table if missing, adding columns for bundles and references.
  - Reference sourcing checks `roll_received` and ensures pending tests aren’t duplicated.
  - Captures sieve data (≤20 rows) plus sand weight, sieving time, humidity, and JSON `test_results`.
  - Report numbers follow `CT-YYYYMMDD-###`; lab test numbers reset per shift (8 AM boundary) via `generateLabTestNumber()`.
  - Status states: `pending` (tester), `checked` (checker), `approved` (admin); `rejected` includes remarks; bundle completion tracked through `checkAndMarkBundleComplete`.

- **Water Permeability (`forms/water_permeability_test.php`)**
  - Accepts tester/checker/admin/management roles; captures dynamic experimental rows (H0/H1, times, corrections).
  - Generates `sample_id` pattern `GSM.LYYMONDD-LT##-R##` and report numbers `WPT-YYYYMMDD-#####` with 8 AM reset.
  - Stores JSON `test_results` plus meta fields (shift, reference, bundle reference, lab test number) in `water_permeability_tests`.
  - Status path mirrors characteristics (`pending` → `checked` → `approved`), with rejected states logged via `remarks`.
  - Surfaces linked orders by filtering `qc_test_orders` on ISO 11058/12956 terms.

- **Weathering / Exposure Tests**
  - `forms/uv_test_enhanced.php`, `forms/weathering_exposure_test*.php`, and `forms/sun_test_report.php` collect sample descriptions, exposure cycles, and bundle references.
  - Use supporting APIs (`get_first_bundle_test_data_sun.php`, `get_tested_rolls_sun.php`, `get_sun_test_summary.php`, etc.) to prevent duplicate bundle submissions.
  - Data lands in tables such as `weathering_exposure_reports` and `sun_test_reports` with statuses `pending`, `approved`, `rejected`.

- **Fabric Stage Tests**
  - `forms/fabric_pre_production_test.php` & `forms/fabric_after_production_test.php` manage pre/after production checkpoints, linking to `fabric_pre_production_tests` and `fabric_after_production_tests`.
  - Capture sample details (customer, GSM, seam/joint results) and maintain `status` fields used by dashboards.

- **Fiber & Raw Material**
  - `forms/fiber_test_report.php`, `forms/tenacity_fiber_report.php`, `forms/fineness_fiber_report.php`, etc., log lab tests tied to `store_entry_reference`.
  - `forms/material_consumption_entry.php`, `forms/project_entry.php` ensure BOM context for later QC references.

- **Legacy/Support Forms**
  - `forms/qc_test.php`, `forms/qc_entry.php`, `forms/submit_qc_entry.php` continue to feed `qc_test_reports` and are surfaced in dashboards for history/reference.

### 4. Checker Review (`admin/lab_testing_dashboard.php`)

- Consolidates `qc_test_orders`, `fabric_pre_production_tests`, `water_permeability_tests`, and `characteristics_tests` into a single list (`$pendingReports`).
- Role gate: only `checker` role can access the dashboard; approvals update `qc_test_orders.status` to `pending_approval` and add `checked_by`/`checker_remarks`.
- Bundle awareness: grouping by `bundle_reference`, so all rolls from the same bundle stay together.
- Provides quick filters per module (QC, Fabric, Water, Characteristics) and rejection reason capture.

### 5. Admin / AGM Approval (`admin/qc_reports_dashboard.php`)

- Requires `admin` or `agm ops`. Displays everything awaiting final approval:
  - QC orders (`qc_test_orders.status = 'pending_approval'`)
  - Sewing (`sewing_thread_reports`), UV (`weathering_exposure_reports`), fiber, fabric (pre/after), sun, water, characteristics.
- Actions:
  - `Approve` → updates status to `approved`, stamps `approved_by`/`approved_at`.
  - `Reject` → sets `rejected`/`rejected_by_approver`, stores reasons (checkbox text + comments) in `remarks` or `admin_remarks`.
- Groups rows by `bundle_reference` or `reference_number`, ensuring entire bundle decisions stay synchronized.
- Links to detail views such as `admin/view_qc_test_order.php`, `view_characteristics.php`, `view_water_permeability.php`.

### 6. Reporting & Downstream Use

- **Dashboards & PDFs**
  - `reports/qc_inspection_report.php` – aggregated QC output by reference and date.
  - `reports/qc_pass_fail_trend.php` (with `reports/api/qc_pass_fail_trend_data.php`) – time-series analytics.
  - `reports/stage_wise_stock_qc_report.php` – inventory impact.
- **APIs for BI**
  - `api/get_submitted_tests.php` exposes a consolidated feed for integrations.
  - Additional admin APIs (`admin/api/management_kpi_api.php`, `admin/api/production_cost_settings_api.php`) consume QC status to influence KPIs and costing.

## Status Reference

| Module | Table | Tester Submit | Checker Action | Admin Action | Rejection Paths |
| --- | --- | --- | --- | --- | --- |
| QC Test Order | `qc_test_orders` | `pending_checker` | `pending_approval` | `approved` | `rejected_by_checker`, `rejected_by_approver` |
| Characteristics | `characteristics_tests` | `pending` | `checked` | `approved` | `rejected` |
| Water Permeability | `water_permeability_tests` | `pending` | `checked` | `approved` | `rejected` |
| Fabric Pre | `fabric_pre_production_tests` | `pending_checker` | `pending_approval` | `approved` | `rejected` |
| Fabric After | `fabric_after_production_tests` | `pending` | (checker optional) | `approved` | `rejected` |
| Sun / UV | `sun_test_reports`, `weathering_exposure_reports` | `pending` | (checker optional) | `approved` | `rejected` |
| Fiber | `fiber_test_reports` | `pending` | (checker optional) | `approved` | `rejected` |

> Missing numeric fields in dashboards default to `0` (never `N/A`), matching user preference and existing UI logic.

## Supporting APIs & Data Sync

- **Reference lookups**
  - `forms/api/get_qc_references.php` – pulls latest `roll_entry` references.
  - `forms/api/get_projects.php`, `get_available_material.php` – used by `material_consumption_entry.php` and other upstream forms.
- **Bundle Helpers**
  - `get_first_bundle_test_data_*.php` endpoints return the earliest report per bundle, ensuring testers only fill missing rolls.
  - `get_tested_rolls*.php` endpoints let UIs disable already-tested roll numbers.
- **Summary Blocks**
  - `get_qc_test_summary.php`, `get_characteristics_test_summary.php`, etc., feed dashboard widgets via AJAX without reloading heavy forms.

## Operational Tips

- Always start from `forms/qc_test_order.php`; downstream lab forms reference its `sample_reference_id` or at least the same bundle/roll.
- Bundle format is enforced with the `-N` suffix; selecting an individual roll auto-derives `bundle_reference` for water/characteristics/sun tests.
- If a record is rejected, use the same form with the `edit` or `resubmit` link; relevant PHP files fetch the existing row, pre-fill JSON fields, and limit edits to testers who created it.
- Dashboards intentionally disable browser caching (`Cache-Control: no-store` headers) – when debugging, avoid relying on the back button.
- All modules call `SecurityConfig::checkSessionTimeout()`; long data-entry sessions should use the built-in draft/prefill mechanisms to prevent loss on timeout.

## Appendix: Table & File Map

- `qc_test_orders` ← `forms/qc_test_order.php`, reviewed via `admin/lab_testing_dashboard.php`, approved in `admin/qc_reports_dashboard.php`.
- `characteristics_tests` ← `forms/characteristics_test.php`, views `admin/view_characteristics.php`.
- `water_permeability_tests` ← `forms/water_permeability_test.php`, views `admin/view_water_permeability.php`.
- `fabric_pre_production_tests` / `fabric_after_production_tests` ← respective forms and dashboards.
- `sun_test_reports` / `weathering_exposure_reports` ← `forms/sun_test_report.php`, `forms/uv_test_enhanced.php`, surfaced in `admin/qc_reports_dashboard.php`.
- `fiber_test_reports`, `sewing_thread_reports`, etc., follow the same tester → checker → admin flow, ensuring uniform governance across the QC/Lab landscape.

This file should stay close to the implemented code; update it whenever new forms or dashboards are added so downstream teams always know how data flows through the QC pipeline.

