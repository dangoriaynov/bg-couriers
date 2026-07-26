# Speedy test waybills to cancel

Real Speedy shipments created on account **997253** while testing the plugin on dev.
The plugin has no auto-cancel yet - **cancel each in your Speedy panel** (or via the API).
New test shipments are appended here as they are created.

| Waybill | Date | Dev order | Context |
|---|---|---|---|
| 63646690078 | 2026-06-25 | #104 | First label-generation verification (to office; Sofia, office id 307) |
| 63646823924 | 2026-06-25 | - | Address-delivery API check (to address; Sofia, ул. Витоша 5, free-text streetName) |
| 63646825739 | 2026-06-25 | - | Full address field-set check (complex/block/entrance/floor/apartment/note) |
| 63646855832 | 2026-06-25 | #115 | To-address E2E label (ships to ул. Витоша 5, Sofia; live price) |
| 63646855845 | 2026-06-25 | #116 | To-automat E2E label (ships to locker 9386, Sofia; live price) |
| 63681572425 | 2026-07-24 | #214 | Batch-print layout test (to office 816, В. Търново; COD 18 as postal money transfer) - CANCELLED 2026-07-24 by owner |
| 63681572559 | 2026-07-24 | #216 | Batch-print layout test (to address, ул. Витоша 5, Sofia) - CANCELLED 2026-07-24 by owner |
| 63681603820 | 2026-07-24 | #215 | Batch-print layout test (to automat 9480, Sofia; created after the 200-with-error fix + 30x20x10 dims) - CANCELLED 2026-07-24 by owner |
| 63682897258 | 2026-07-26 | dev 218 | Re-issue-button E2E, step 1: generate (to office 190, site 151) - CANCELLED by the re-issue itself |
| 63682897289 | 2026-07-26 | dev 218 | Re-issue-button E2E, step 2: the waybill the re-issue created - CANCELLED by the script at the end |
| 63682905549 | 2026-07-26 | dev 219 | Bulk-behaviour E2E: pre-label, then proved bulk Generate + Print leave it untouched - CANCELLED by the bulk re-issue |
| 63682905554 | 2026-07-26 | dev 220 | Bulk-behaviour E2E: the only waybill bulk Generate created (the missing one) - CANCELLED by the script |
| 63682905565 | 2026-07-26 | dev 219 | Bulk-behaviour E2E: created by bulk Re-issue - CANCELLED by the script at the end |
| 63682911736 | 2026-07-26 | dev 221 | Bulk re-issue (new semantics) E2E: pre-label - CANCELLED by the re-issue |
| 63682911743 | 2026-07-26 | dev 221 | Bulk re-issue (new semantics) E2E: the replacement - CANCELLED by the script |
| 63682911801 | 2026-07-26 | dev 222 | Bulk re-issue (new semantics) E2E: order that had NO waybill and now gets one - CANCELLED by the script |
