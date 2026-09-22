/**
 * Feature flags — deliberately hardcoded, not a runtime/admin-toggleable
 * setting. "belum diaktifkan, hanya developer yang bisa aktifkan": only a
 * developer editing this file (and the backend's FEATURE_MATERIAL_REQUEST_ENABLED
 * in .env) can turn a flag back on.
 */

/**
 * Off since 2026-09-22 — RequestService::reserve() has a known dead-end for
 * physical-check-mismatch lines that can leave a request line permanently
 * unpurchasable. Re-enable only after backend/app/Services/RequestService.php
 * is fixed (see docs/phase-13 audit).
 */
export const MATERIAL_REQUEST_ENABLED = false
