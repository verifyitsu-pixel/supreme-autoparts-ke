# Email sender avatar (logo on profile chip)

Gmail’s circle avatar is **not** controlled by HTML in the email body.

## What ships today (message body)

Every WooCommerce / Brevo transactional email includes the store logo in the **HTML header**:

`https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/logo-light.jpg`

(also `logo-light.png`). Plain-text store emails are wrapped with the same header logo when sent via Brevo.

## BIMI (avatar in supporting clients)

**Logo URL (public):**  
`https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/bimi-logo.svg`

**DNS (HostAfrica zone `supremeautoparts.co.ke` — NS: ns1/ns2.host-ww.net):**

| Type | Name | Value |
|------|------|-------|
| TXT | `default._bimi` | `v=BIMI1; l=https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/bimi-logo.svg;` |

**Already OK for BIMI policy:** `_dmarc` = `v=DMARC1; p=reject`  
**DKIM (Brevo):** `brevo1._domainkey` / `brevo2._domainkey` CNAMEs present.

**Recommended SPF tighten (keep HostAfrica + add Brevo):**  
`v=spf1 include:spf.brevo.com include:spf.cloudeu.xion.oxcs.net ~all`

### Where logos show

| Client | Avatar chip | In-body header logo |
|--------|-------------|---------------------|
| Gmail (web/Android) | Needs BIMI **+ Verified Mark Certificate (VMC)** — without VMC Gmail usually keeps letter “S” | Yes (after deploy) |
| Apple Mail / Yahoo / some others | BIMI logo without VMC (often) | Yes |
| Outlook | Varies; often not BIMI | Yes |

**VMC:** DigiCert/Entrust mark certificate (~\$1.5k/yr). Publish BIMI now; buy VMC later if Gmail chip is required.

## Gravatar (optional)

Upload `branding/gravatar-logo.png` at https://gravatar.com for `calvin@supremeautoparts.co.ke`. Helps some clients; Gmail often ignores it for domains with their own branding path.
