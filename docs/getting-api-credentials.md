# Getting your courier API credentials (merchant guide)

To enable a courier in this plugin you need **your own API access** from that courier (a business
account + API credentials). Couriers issue these to registered business clients - there is no instant
self-signup for most. Below is exactly **who to contact and what to ask for** per courier, then where
to enter the credentials.

> **Keep credentials private.** You enter them in **WooCommerce → Settings → Shipping → BG Couriers →
> [courier]**, where they are stored encrypted. Never post them publicly or share them in email threads
> beyond the courier's official integration contact. Use a courier **test/sandbox** account while setting up.

---

## Speedy
- **Contact:** your Speedy account / a Speedy office · https://www.speedy.bg
- **Steps:**
  1. Have (or open) a **Speedy business contract**.
  2. Ask Speedy to enable **API access** for your account - you receive an **API username + password** (the credentials for `api.speedy.bg`).
  3. Enter the **username** and **password** in the plugin's **Speedy** settings, click **Validate**, then **Sync**.

## Econt
- **Contact:** https://www.econt.com (register "Моят Еконт") · or an Econt office
- **Steps:**
  1. Register/open an **Econt client account** (e-Econt / Моят Еконт). For real shipments you need a real business account.
  2. The API uses **your account credentials** (username = your account email, password). Confirm with Econt that API access is enabled.
  3. Enter the **username (email)** and **password** in the plugin's **Econt** settings, **Validate**, then **Sync**.

## BOX NOW
- **Contact:** **integrationsupport@boxnow.bg** · docs: https://www.boxnow.bg/partner-api
- **Steps:**
  1. Email integrationsupport@boxnow.bg with: **company name, address, tax ID (ЕИК), contact details**, and the **phone numbers** of the people who will use the Partner Portal (used for OTP SMS login).
  2. They issue your **OAuth2 Client ID + Client Secret** and the **API base URL** (sandbox + production).
  3. Enter the **Client ID + Secret** in the plugin's **BOX NOW** settings.
- *Note:* BOX NOW delivers to **lockers (APM)** only, with a flat partner rate.

## Pigeon Express
- **Contact:** **support@pigeonexpress.com** · https://pigeonexpress.com · API docs: https://api-docs.pigeonexpress.com
- **Steps:**
  1. Contact Pigeon Express and request **API access**.
  2. They issue an **API Key + API Secret** and the **Production + Sandbox base URLs**.
  3. Enter the **API Key, API Secret, base URL** (and your **pickup office**) in the plugin's **Pigeon Express** settings, **Validate**, then **Sync**.

## Express One
- **Contact:** **international@expressone.bg** / your Express One BG account manager · https://expressone.bg
- *Important:* Express One BG is part of the **Austrian Post Group (CEE)**. Express One's API uses an **API Key**; the technical shape is the group's `api.expressone.*` style, but **confirm the correct Bulgaria API base URL** with Express One BG (do not assume the Slovenia host).
- **Steps:**
  1. Contact Express One BG and request **API integration / an API Key** for your account.
  2. Ask them for: your **API Key** and the **Bulgaria API base URL**.
  3. Enter the **API Key + base URL** in the plugin's **Express One** settings.

---

## Quick contact summary

| Courier | Where to write | You receive |
|---|---|---|
| Speedy | speedy.bg / Speedy office | API username + password |
| Econt | econt.com (Моят Еконт) | account username (email) + password |
| BOX NOW | integrationsupport@boxnow.bg | OAuth2 Client ID + Secret |
| Pigeon Express | support@pigeonexpress.com | API Key + Secret + base URL |
| Express One | international@expressone.bg | API Key (+ confirm BG base URL) |

A courier's exact process can change - if a step differs from the above, follow the courier's own
instructions; the plugin only needs the credentials they give you.
