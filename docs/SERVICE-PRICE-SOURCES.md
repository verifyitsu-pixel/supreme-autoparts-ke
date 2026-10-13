# Car service price sources

Only published prices are shown on the site. If no published price was found, the site shows **Quote on request**.
Retrieved 2026-10-13 (EAT). Raw page captures: `docs/service-price-sources/`.

## Kenya (KES) — AutoXpress Kenya e-store
Source: https://auto-xpress.co.ke/estore/services (rendered 2026-10-13)

| Service | Option | KES |
|---|---|---|
| AC Gas Refilling | Passenger | 5,500 |
| AC Gas Refilling | SUV | 7,500 |
| AC Gas Refilling | SUV (dual vents) | 10,000 |
| 3D Wheel Alignment | Toe | 2,500 |
| 3D Wheel Alignment | Caster Camber | 4,000 |
| 3D Wheel Alignment | Caster Camber – Shims | 6,000 |
| Wheel Balancing | Passenger 12"/13"/14" | 450 |
| Wheel Balancing | Passenger 15" | 550 |
| Wheel Balancing | Passenger 16" | 600 |
| Wheel Balancing | Passenger 17" | 800 |

No published KES price found for: oil change (labour), brake pads, tyre rotation, battery, diagnostics, suspension, full service → Quote on request.

## USA (USD) — RepairPal Fair Price national estimates ("Estimates last updated September 3, 2026")
| Service | USD range | URL |
|---|---|---|
| Oil change | 160–188 | https://repairpal.com/estimator/oil-change-cost |
| Brake pad replacement | 335–394 | https://repairpal.com/estimator/brake-pad-replacement-cost |
| Wheel alignment | 186–273 | https://repairpal.com/estimator/wheel-alignment-cost |
| Tire rotation | 49–72 | https://repairpal.com/estimator/tire-rotation-cost |
| AC recharge | 285–369 | https://repairpal.com/estimator/ac-recharge-cost |
| Battery replacement | 456–493 | https://repairpal.com/estimator/battery-replacement-cost |

Not found (RepairPal Cloudflare-blocked or no page; Pep Boys bot-blocked; Firestone/Midas pages carry no prices): wheel balancing, diagnostics, suspension, full service → Quote on request.

## Reference repos
- viepovsky/Car-Service-Garage-Backend — no license. Used only as a feature reference (garages, schedules, catalog offers, repair status, vehicles). No code copied.
- mohamedsamirmohamed/Car-Services — no license. Feature reference only. No code copied.

## Job durations
None of the price sources above publish job times (AutoXpress and RepairPal pages show prices only), so every duration on the site is labelled "(estimate)". Slots are 1 hour; a job reserves ceil(duration/60) consecutive hours, capacity 2 per hour.

## Garage address
Ground Floor, Midax Plaza, Kandundo Rd, Njiru, Nairobi (P.O. Box 00516-00100 G.P.O Nairobi) — from the Business Registration Certificate BN-93S9AZEJ (Standard Driveworks).

## Google Calendar sync (off until configured)
Railway env vars: `SA_GCAL_SERVICE_ACCOUNT_JSON` (service-account key JSON, raw or base64), `SA_GCAL_CALENDAR_ID` (e.g. the Gmail address or calendar id). Share that calendar with the service-account email with "Make changes to events". Optional `SA_GCAL_IMPERSONATE` for Workspace domain-wide delegation.
