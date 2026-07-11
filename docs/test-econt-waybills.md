# Econt test waybills (real account - owner cancels)

Real Econt waybills created during development against the owner's real e-Econt account.
**The owner cancels these - Claude never cancels.** Quotes use `mode:calculate` (no waybill).

| Waybill | Delivery type | Dev order | Date | Notes |
|---------|---------------|-----------|------|-------|
| `1055191332613` | to office (София Студентски град, code 1009) | - (API shape-capture, no WC order) | 2026-06-27 | 4.68 EUR. Created to confirm createLabel `mode:create` + getShipmentStatuses shapes for the adapter. **Please cancel.** |
| `1055198732164` | to address (София, ул. Витоша 1) | - (throwaway order, deleted) | 2026-07-07 | 5.89 EUR + **наложен платеж 24 EUR (CD139925)**. Created to verify the Econt COD end-to-end (опис == cdAmount, per-unit price×count, sender quarter). **Please cancel.** |

## Verification test waybills (2026-07-11) - PLEASE CANCEL
Created via server probe to verify label generation across methods (all live, real waybills):

| Waybill | Order | Method | Note |
|---|---|---|---|
| (unknown) | 173 | address | dangling - created before the pdf fix; number not recorded. Cancel via Econt panel by order date. |
| 1055201897408 | 175 | address | not saved to order meta |
| 1055201897873 | 176 | address | on the order - cancel via UI |
| 1055201897880 | 177 | office | on the order - cancel via UI |
| 1055201897897 | 178 | APS | on the order - cancel via UI |
