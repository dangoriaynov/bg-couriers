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

## Sameday
- **Contact:** https://sameday.bg / your Sameday account manager · test host: `sameday-api.demo.zitec.com`
- **Steps:**
  1. Sign a **Sameday business contract** (via sameday.bg or your Sameday account manager).
  2. Request **API / eAWB access**; you receive a **username + password**.
  3. Ask for your **pickup-point ID** and the **service IDs** for each delivery type (office / address / easyBox locker) from your contract.
  4. Enter the username, password, pickup point and service IDs in the plugin's **Sameday** settings; tick **Sandbox** to use the test environment.

## Express One
- **Contact:** **international@expressone.bg** / your Express One BG account manager · https://expressone.bg
- *Important:* the API account is **not** your my.expressone.bg web login, and it is a **username + password**, not an API key. The address (`system.expressone.bg`) is fixed in the plugin, so there is no base URL to enter.
- **Steps:**
  1. Contact Express One BG and request **API access** for your contract. They start you on a **test environment** and issue production credentials once the integration works.
  2. Ask them for: the **API username + password**, and your **sender object id** (the address parcels are collected from).
  3. Enter the **username** and **password** in the plugin's **Express One** settings, click **Validate**, then **Sync**, and pick your sender address from the list that appears.

## Европът (Evropat-2000)
- **Contact:** your own cabinet at **https://online.evropat.com**, or **sales@evropat.com** · https://evropat.bg
- *Important:* this is the one courier here with **self-service** credentials, and there is **no username**: the API key is the whole credential.
- **Steps:**
  1. Generate the **API Key** in the online cabinet, or e-mail sales@evropat.com and ask for one.
  2. Enter the **API Key** in the plugin's **Европът** settings, **Validate**, then **Sync**.
- *Note:* Европът delivers to **offices and addresses** (no lockers), and its prices already include VAT; the plugin splits the VAT back out so WooCommerce does not add it twice.

---

## Quick contact summary

| Courier | Where to write | You receive |
|---|---|---|
| Speedy | speedy.bg / Speedy office | API username + password |
| Econt | econt.com (Моят Еконт) | account username (email) + password |
| BOX NOW | integrationsupport@boxnow.bg | OAuth2 Client ID + Secret |
| Pigeon Express | support@pigeonexpress.com | API Key + Secret + base URL |
| Sameday | sameday.bg / account manager | username + password (+ pickup point & service IDs) |
| Express One | international@expressone.bg | API username + password (+ sender object id) |
| Европът | online.evropat.com / sales@evropat.com | API Key (no username) |

A courier's exact process can change - if a step differs from the above, follow the courier's own
instructions; the plugin only needs the credentials they give you.
