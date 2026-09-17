-- Phase 4C1 — MySQL PlatformOperationId ↔ quoteId correlation (NOT APPLIED)
-- Required for deterministic recovery after MySQL commit before Azure markCompleted.
-- Do NOT overload tqoth.cref / tqoti.ref.
--
-- Non-prod first-write target uses suffix _dev.
-- Production table (empty suffix) requires separate release approval.

-- Non-production:
CREATE TABLE IF NOT EXISTS tc.quote_write_correlation_dev (
  platform_operation_id CHAR(36) NOT NULL,
  quote_id BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (platform_operation_id),
  UNIQUE KEY UX_quote_write_correlation_dev_quote_id (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Production (DO NOT CREATE in Phase 4C):
-- CREATE TABLE IF NOT EXISTS tc.quote_write_correlation (
--   platform_operation_id CHAR(36) NOT NULL,
--   quote_id BIGINT NOT NULL,
--   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--   PRIMARY KEY (platform_operation_id),
--   UNIQUE KEY UX_quote_write_correlation_quote_id (quote_id)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
