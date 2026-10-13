# Roadside Rescue — price sources

Rule: only verifiable, published Kenyan rates are shown. Everything else shows
"Quote on request, confirmed on call". No payment is required to request help; the price is
agreed on the phone before a responder is sent.

| Service | Shown on site | Source | Checked |
|---|---|---|---|
| Towing (Nairobi & environs, 0–50 km) | Rate tables: saloons/station wagons KES 6,000 / 9,000 / 12,000 / 15,000 / 17,000; SUVs, pick-ups, light trucks <2 t KES 6,500 / 9,500 / 12,500 / 15,500 / 17,500 (per 10 km band). "From KES 6,000". | Galaxy Towing Kenya, published rate card, VAT inclusive, same day and night: https://www.galaxytowing.co.ke/towing-truck-charges-prices-galaxy-towing-service/ (fetched with curl 14 Oct 2026, values confirmed in page HTML) | 14 Oct 2026 |
| Towing beyond 50 km | Quote on request | — | — |
| Battery / jump-start | Quote on request | Not used: Dojeto (breakdownservicesnairobi.co.ke) publishes KES 1,500–2,000, but the page dates from July 2022, so it isn't current enough to show. | 14 Oct 2026 |
| Flat tyre, fuel delivery, lockout, winching, minor repair | Quote on request | Not used: nairobionline.com lists "estimated cost" ranges from a directory, not a provider's own rate card. | 14 Oct 2026 |

Not used for display, for reference only: Rosselot (rosselot.co.ke/prices) and Elite Towing
(elitetowing.co.ke) also publish Nairobi towing tables. Rosselot's page renders client-side and
couldn't be verified from the HTML, so it isn't shown.

On /roadside/ the tables are labelled "Guide towing prices". Following the store-wide change in
7f69f16, no source name or link is shown to customers; the source lives only in this file and in
`data/roadside-catalog.php`. They are not Supreme Autoparts' own tariff. Dispatch confirms the actual price on the call and can
record it under "Agreed price" in Super Admin → Roadside Rescue.
