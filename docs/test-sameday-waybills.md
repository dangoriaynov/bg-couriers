# Sameday test AWBs (BG DEMO env - sameday-api-bg.demo.zitec.com)

Individual test environment issued to us by Sameday (account name withheld per the no-creds-in-VCS rule); AWBs live only on the
demo stack, no real pickups happen. All E2E waybills are CANCELLED right after the flow.

| Date (EEST) | Order (dev) | AWB | Case | Result |
|---|---|---|---|---|
| 2026-07-23 | 212 | 1VTDLN0017633 | automat: easybox Asenova Krepost 2, София (20147), COD 24 EUR | create+pdf(A6 98KB)+track+CANCELLED |
| 2026-07-23 | 213 | 1VTD240017634 | address: бул. Витоша 5, София, COD 24 EUR | create+pdf(A6 85KB)+track+CANCELLED |

Notes: AWB prefixes carry the service (LN = Locker NextDay 15, 24 = 24H 7); COD amount verified = full
order total (sender-pays model); tracking returns Bulgarian statuses (expeditionStatus.status).
