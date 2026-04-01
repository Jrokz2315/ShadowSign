# 🔏 ShadowSign

**Client-side document signing, cryptographic fingerprinting, leak attribution, and video watermarking — with Web3 wallet-gated delivery.**

> 🌐 **Live production instance:** [https://shadowsign.io](https://shadowsign.io)

ShadowSign is a fully self-hostable, single-file HTML application that lets you stamp documents and videos with invisible cryptographic watermarks, generate signed delivery packages for specific Ethereum wallet addresses, and verify attribution if a document is ever leaked. All cryptography runs in the browser. No backend is required for signing, watermarking, or verification — only for the optional Web3 encrypted delivery relay.

---

## Table of Contents

1. [What ShadowSign Does](#what-shadowsign-does)
2. [Architecture Overview](#architecture-overview)
3. [Repository Structure](#repository-structure)
4. [How It Works — Feature by Feature](#how-it-works--feature-by-feature)
   - [Signing & Fingerprinting](#signing--fingerprinting)
   - [ChromaGrid Steganography](#chromagrid-steganography)
   - [QR Attribution Code](#qr-attribution-code)
   - [Video Watermarking](#video-watermarking)
   - [Web3 Encrypted Delivery](#web3-encrypted-delivery)
   - [Verify Tab](#verify-tab)
5. [Where YOUR_DOMAIN Must Be Set](#where-your_domain-must-be-set)
6. [Self-Hosting Setup](#self-hosting-setup)
   - [Option A — No Web3 Delivery (Pure Client-Side)](#option-a--no-web3-delivery-pure-client-side)
   - [Option B — Full Deploy with Web3 Delivery Relay](#option-b--full-deploy-with-web3-delivery-relay)
7. [Directory Layout on Your Server](#directory-layout-on-your-server)
8. [PHP Relay Endpoints](#php-relay-endpoints)
   - [store.php — Store a Delivery Package](#storephp--store-a-delivery-package)
   - [index.php — Retrieve & Burn a Delivery Package](#indexphp--retrieve--burn-a-delivery-package)
9. [verify.html — QR Scan Landing Page](#verifyhtml--qr-scan-landing-page)
10. [Security Model](#security-model)
11. [Cryptographic Primitives](#cryptographic-primitives)
12. [Supported File Types](#supported-file-types)
13. [Browser & Wallet Compatibility](#browser--wallet-compatibility)
14. [Configuration Reference](#configuration-reference)
15. [Contributing](#contributing)
16. [License](#license)

---

## What ShadowSign Does

| Capability | Description |
|---|---|
| **Invisible watermark** | Embeds a per-recipient ChromaGrid steganographic pattern into image/PDF pages — survives screenshots |
| **Cryptographic HMAC fingerprint** | HMAC-SHA256 binding of document hash + recipient identity + timestamp |
| **QR attribution stamp** | Scannable QR code printed on every page linking to the verify landing page |
| **Web3 encrypted delivery** | AES-GCM encrypted package, gated by the recipient's Ethereum wallet address — delivered via a burn-after-read URL |
| **Full attribution verify** | Drop a leaked document back in to recover the embedded HMAC, sender fingerprint, and recipient name |
| **RSA-OAEP signing** | Per-sender RSA-2048 keypair generated in-browser; public key included in every signed package |
| **DOCX / XLSX badge embedding** | Cryptographic metadata injected as a hidden XML part inside Office documents |
| **Video watermarking** | Visible watermark text + QR fingerprint burned into every Nth frame — survives re-encode and screenshot; optional HMAC signature block in metadata; output as MP4 or ZIP bundle |
| **No server for core features** | Signing, verifying, watermarking, and fingerprinting are all 100% client-side |

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│  index.html  (main app — runs entirely in browser)   │
│                                                       │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐  │
│  │  Sign    │ │ Deliver  │ │  Verify  │ │ Stamp  │  │
│  │  Tab     │ │  Tab     │ │  Tab     │ │ (img)  │  │
│  └──────────┘ └────┬─────┘ └──────────┘ └────────┘  │
│                    │                                  │
│          HTTPS POST (encrypted payload)               │
└────────────────────┼────────────────────────────────-┘
                     │
          ┌──────────▼──────────────┐
          │  /d/store.php           │  ← ephemeral relay
          │  /d/index.php           │    (PHP, any host)
          │  /d/payloads/*.json     │    burn-after-read
          └─────────────────────────┘
                     │
          recipient opens link → wallet unlock → AES-GCM decrypt → file downloads → link self-destructs

┌───────────────────────────────────────────────────┐
│  /verify.html  (standalone QR scan landing page)   │
│  Reads URL params, shows attribution, links back   │
│  to main app Verify tab                            │
└───────────────────────────────────────────────────┘
```

---

## Repository Structure

```
/
├── index.html          # Main ShadowSign app (Sign, Deliver, Verify tabs)
├── verify.html         # QR scan landing page (separate, lightweight)
├── d/
│   ├── index.php       # Delivery retrieve & burn endpoint
│   ├── store.php       # Delivery store endpoint
│   ├── .htaccess       # Routes all /d/ requests through index.php
│   └── payloads/       # Auto-created at runtime — stores encrypted envelopes
│       └── .htaccess   # Auto-created — blocks direct HTTP access to payload files
└── README.md
```

---

## How It Works — Feature by Feature

### Signing & Fingerprinting

When a sender uploads a document and enters a recipient name, ShadowSign:

1. **Hashes the document** using SHA-256 via the Web Crypto API.
2. **Derives an HMAC-SHA256 fingerprint** binding: `document_hash + recipient_name + sender_public_key + timestamp`.
3. **Generates a sender fingerprint** from the RSA-2048 public key.
4. **Embeds the certificate** as hidden metadata inside DOCX/XLSX files (custom XML part), or as invisible metadata in image and PDF targets.
5. **Stamps a QR code** onto every page linking to `https://YOUR_DOMAIN/verify.html?h=<hmac>&fp=<sender_fp>&r=<recipient>&ts=<timestamp>`.
6. **Returns the stamped file** for download — no data leaves the browser except for the optional Web3 delivery relay.

The signed certificate JSON looks like:

```json
{
  "version": 2,
  "hmac": "a3f9...",
  "senderFp": "d71c...",
  "recipient": "Alice",
  "timestamp": "2025-01-15T10:30:00.000Z",
  "docHash": "e3b0...",
  "publicKey": "<JWK RSA-2048 public key>"
}
```

---

### ChromaGrid Steganography

ChromaGrid is ShadowSign's screenshot-survivable watermarking system. It encodes a binary payload (the HMAC fingerprint) into the chroma channels (red/blue differential) of a canvas-rendered image.

**Encoding:**

1. The document page is rendered to a `<canvas>` element.
2. The HMAC fingerprint is split into a binary bit stream.
3. Bits are encoded across a grid of color cells using a red/blue channel differential: a `1` bit slightly increases the red channel relative to blue; a `0` bit does the inverse.
4. **Anchor blobs** (high-contrast corner markers) are painted at known grid positions so the decoder can re-align after screenshot crop/resize.
5. The modified canvas is exported back to the file.

**Decoding (Verify tab):**

1. The uploaded image is drawn to canvas.
2. Anchor blobs are located by searching for high-contrast corner signatures.
3. The grid is re-aligned from anchor positions.
4. Each cell's R/B differential is measured and thresholded to recover `1` or `0`.
5. Bits are reassembled into the HMAC and matched against the ledger or displayed as attribution.

ChromaGrid is designed to survive: JPEG re-compression, screenshot crops, minor resizing, color profile changes, and social media re-encoding.

---

### QR Attribution Code

Every stamped PDF or image page receives a QR code in the bottom-right corner. The QR code encodes:

```
https://YOUR_DOMAIN/verify.html?h=<first16chars_of_hmac>&fp=<first16chars_of_sender_fp>&r=<recipient_name>&ts=<iso_timestamp>
```

- The `h` and `fp` values are partial (first 16 hex characters) to fit inside a compact QR while still being meaningful for attribution.
- The `r` (recipient name) is URL-encoded and embedded directly so a camera scan immediately reveals who the document was stamped for.
- Scanning the QR code opens `verify.html`, which parses the URL parameters and renders the attribution result — with a button to open the main app's Verify tab for full cryptographic confirmation.

The QR rendering text printed beneath the code reads: `SCAN TO VERIFY · YOUR_DOMAIN`

---


### Video Watermarking

ShadowSign can burn a per-recipient attribution watermark directly into video frames, making it one of the few attribution tools that works on leaked video clips, screen recordings, and re-encoded footage.

**What gets embedded:**

- **Visible floating watermark** — The recipient's name (or a custom label) is rendered as text directly onto every processed frame. The position bounces across the frame using a DVD-screensaver-style path so cropping a single edge cannot remove it.
- **QR fingerprint** — A scannable QR code linking to the verify page is stamped onto every Nth frame alongside the watermark text.
- **HMAC signature block in metadata** — When the "Embed signature block" option is enabled, the HMAC fingerprint and sender identity are written into the video's metadata/comment stream so the certificate travels with the file even without the visible overlay.

**Output formats:**

The pipeline has two paths depending on browser support:

1. **VideoEncoder path (Chrome/Edge)** — Uses the WebCodecs `VideoEncoder` API to re-encode frames to H.264 AVC, then muxes the result to MP4 using the bundled `mp4-muxer` library (v5.2.2, inlined). This is the fast path and produces a standard `.mp4`.
2. **MediaRecorder fallback (Firefox/Safari)** — Falls back to `MediaRecorder` with VP8/VP9, producing a `.webm`. Duration metadata is patched in post to ensure the file plays correctly.

**Short vs. long video handling:**

- Short clips use **seek mode**: the video is seeked frame-by-frame at the target FPS, each frame drawn to canvas, watermarked, and captured. This is accurate and fast.
- Long videos use **real-time play mode**: the video plays at normal speed and frames are captured in real time to avoid memory exhaustion on large files.

**ZIP bundle (with signature block):** When "Embed signature block in video metadata" is enabled, the output is a ZIP containing the watermarked video file plus a `shadowsign_manifest.txt` certificate in plaintext.

**Size limit:** Up to 500 MB video files are supported (vs. 15 MB for documents).

**Supported input formats:** MP4, MOV, AVI, WebM, MKV, M4V, WMV — anything the browser's native `<video>` element can decode.

### Web3 Encrypted Delivery

This is an optional feature that lets you deliver a signed file to a specific Ethereum wallet address via a burn-after-read encrypted URL.

**Sender flow:**

1. Sender enters the recipient's Ethereum address (e.g. `0xABCD...`).
2. ShadowSign derives a 32-byte AES-GCM key by XOR-combining a random `obfKey` with the recipient's address bytes.
3. The stamped file is AES-GCM encrypted client-side; the ciphertext + IV + obfKey + filename + target address are bundled into a payload JSON.
4. The payload is POSTed to `https://YOUR_DOMAIN/d/store.php`, which stores it encrypted in `d/payloads/<token>.json` and returns a delivery URL: `https://YOUR_DOMAIN/d/?t=<token>`.
5. The sender shares this URL with the recipient.

**Recipient flow:**

1. Recipient opens the delivery URL in a browser with an Ethereum wallet (MetaMask, Coinbase Wallet, Trust Wallet, Rainbow, Brave Wallet).
2. The delivery page (`index.php`) serves a self-contained HTML unlock UI.
3. The page POSTs to itself (`?t=TOKEN`) to fetch the encrypted payload.
4. Recipient clicks **Unlock** → wallet `eth_requestAccounts` → their address is read.
5. The AES-GCM key is reconstructed: `rawKey[i] = obfKey[i] XOR addressBytes[i % len]`.
6. The ciphertext is decrypted in-browser using `crypto.subtle.decrypt`.
7. The decrypted file is downloaded automatically.
8. The page POSTs to `?t=TOKEN&burn=1` to mark the payload as opened and schedule deletion.
9. After 6 seconds, the card replaces with a "Delivery Opened — link self-destructed" screen.

If the wrong wallet address is used, decryption fails with an error showing the first 6 and last 4 characters of the expected address.

---

### Verify Tab

The Verify tab in the main app accepts a dropped/uploaded signed file and:

1. Attempts to extract the embedded certificate JSON from DOCX/XLSX custom XML parts, PDF metadata, or image EXIF/steg data.
2. Recomputes the HMAC from the recovered certificate fields.
3. Compares against the stored HMAC.
4. Runs ChromaGrid decode on image pages to recover the steg fingerprint.
5. Applies attribution cascading rules:
   - **Full match**: HMAC + steg + certificate all agree → full recipient attribution with name.
   - **Partial match**: HMAC matches but steg is degraded → partial attribution with fingerprint only.
   - **No match**: Document has not been signed with ShadowSign, or has been heavily modified.
6. A `signerIsMe` check gates whether the local sender's fingerprint matches the certificate — to confirm you are the original signer.

---

## Where YOUR_DOMAIN Must Be Set

Search for `YOUR_DOMAIN` across the codebase — these are the **only places** you need to change after cloning:

| File | Location | What to change |
|---|---|---|
| `index.html` | `const SHADOWSIGN_RELAY = 'https://YOUR_DOMAIN/d/store.php';` | Your domain, e.g. `https://sign.example.com/d/store.php` |
| `index.html` | `return 'https://YOUR_DOMAIN/verify.html#'` | Base URL for QR verify links |
| `index.html` | `'YOUR_DOMAIN'` (WalletConnect metadata / origin check) | Your domain string |
| `index.html` | `sp.drawText('SCAN TO VERIFY · YOUR_DOMAIN', ...)` | The text printed under QR codes in PDFs |
| `index.html` | `ctx.fillText('SCAN TO VERIFY · YOUR_DOMAIN', ...)` | The text printed under QR codes in images |
| `index.html` | `alert('[SECURITY BLOCKED] ... https://YOUR_DOMAIN ...')` | Error messages referencing your domain |
| `store.php` | `header('Access-Control-Allow-Origin: https://YOUR_DOMAIN');` | CORS header |
| `store.php` | `strpos($origin, 'https://YOUR_DOMAIN')` | Origin check |
| `store.php` | `strpos($referer, 'https://YOUR_DOMAIN')` | Referer check |
| `store.php` | `define('BASE_URL', 'https://YOUR_DOMAIN/d/');` | Returned delivery URL base |
| `index.php` | `define('BASE_URL', 'https://YOUR_DOMAIN/d/');` | Internal URL base |

> **Tip:** Run `grep -r "YOUR_DOMAIN" .` after cloning to confirm all instances are replaced before deploying.

---

## Self-Hosting Setup

### Option A — No Web3 Delivery (Pure Client-Side)

If you only want signing, watermarking, QR stamping, and local verification — with no Web3 delivery — you need **zero backend**:

1. Replace all `YOUR_DOMAIN` values in `index.html` and `verify.html` with your actual domain (even a GitHub Pages URL works).
2. Host `index.html` and `verify.html` as static files on any web server, CDN, or GitHub Pages.
3. Done. The relay endpoints (`store.php`, `index.php`) can be ignored.

---

### Option B — Full Deploy with Web3 Delivery Relay

Requirements: a web server running **PHP 7.4+** with `file_put_contents` write access to the `d/payloads/` directory, and **mod_rewrite** (Apache) or equivalent.

#### Step 1 — Replace YOUR_DOMAIN everywhere

```bash
# Quick replace (macOS/Linux)
DOMAIN="https://sign.example.com"
sed -i "s|https://YOUR_DOMAIN|$DOMAIN|g" index.html store.php index.php
sed -i "s|YOUR_DOMAIN|sign.example.com|g" index.html
```

Verify nothing is missed:

```bash
grep -r "YOUR_DOMAIN" .
# Should return no results
```

#### Step 2 — Upload files to your server

```
/public_html/              (or your web root)
├── index.html
├── verify.html
└── d/
    ├── index.php
    ├── store.php
    └── .htaccess          ← the d_htaccess.txt file, renamed to .htaccess
```

Make sure `d/` is writable by PHP:

```bash
chmod 755 d/
# The payloads/ subdirectory is auto-created by store.php on first use
```

#### Step 3 — Configure .htaccess

The `d/.htaccess` file (provided as `d_htaccess.txt` in this repo — rename it) routes all `/d/?t=TOKEN` requests through `index.php`:

```apache
DirectoryIndex index.php
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

If you're using Nginx instead of Apache, add this to your server block:

```nginx
location /d/ {
    try_files $uri $uri/ /d/index.php?$query_string;
}
```

#### Step 4 — Test the relay

Open your browser console on `index.html` and run:

```javascript
fetch('https://sign.example.com/d/store.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ payload: 'test', ttl: 60 })
}).then(r => r.json()).then(console.log);
```

Expected response:

```json
{
  "ok": true,
  "token": "abc123...",
  "url": "https://sign.example.com/d/?t=abc123...",
  "expires": 1736000000
}
```

---

## Directory Layout on Your Server

```
/d/
├── .htaccess           Routes requests to index.php
├── index.php           GET: serve delivery HTML  |  POST: return payload or burn
├── store.php           POST: accept encrypted package, return delivery URL
└── payloads/
    ├── .htaccess       Auto-created: "Deny from all" — blocks direct file access
    ├── <token>.json    Encrypted delivery envelopes (auto-deleted after open or expiry)
    └── ...
```

Payload envelopes are plain JSON files containing:

```json
{
  "token": "abc123...",
  "created": 1736000000,
  "expires": 1736086400,
  "opened": false,
  "payload": "<base64-encoded AES-GCM ciphertext + metadata>"
}
```

Cleanup runs probabilistically (5% chance per store request) and deletes envelopes where `expires < now` or `opened === true`.

---

## PHP Relay Endpoints

### store.php — Store a Delivery Package

**Endpoint:** `POST /d/store.php`

**Headers required:** `Content-Type: application/json`

**Request body:**

```json
{
  "payload": "<string — AES-GCM ciphertext JSON, base64-encoded>",
  "ttl": 86400
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `payload` | string | Yes | Client-encrypted ciphertext. Max 10 MB. |
| `ttl` | integer | No | Seconds until expiry. Default: 86400 (24h). Max: 86400. |

**Success response (200):**

```json
{
  "ok": true,
  "token": "abc123xyz...",
  "url": "https://YOUR_DOMAIN/d/?t=abc123xyz...",
  "expires": 1736086400
}
```

**Security:** The endpoint validates `Origin` and `Referer` headers against `YOUR_DOMAIN`. Requests from other origins receive `403 Forbidden`. This is a CSRF-style guard — the payload is already encrypted client-side, so even a bypassed origin check would yield no plaintext.

---

### index.php — Retrieve & Burn a Delivery Package

**Endpoint:** `GET /d/?t=TOKEN` → serves delivery HTML page

**Endpoint:** `POST /d/?t=TOKEN` → returns encrypted payload JSON

**Endpoint:** `POST /d/?t=TOKEN&burn=1` → marks as opened, schedules deletion

**GET response:** Full self-contained delivery HTML page with wallet unlock UI.

**POST response (payload fetch):**

```json
{ "ok": true, "payload": "<string>" }
```

**POST response (already opened):**

```json
{ "ok": false, "error": "already_opened" }
```
Status: `410 Gone`

**Error responses:**

| Code | Condition |
|---|---|
| 400 | Missing `t` parameter |
| 404 | Token not found or expired (file deleted) |
| 410 | Delivery already opened |
| 500 | Corrupted envelope file |

---

## verify.html — QR Scan Landing Page

A lightweight, standalone page served at `/verify.html`. When a QR code printed by ShadowSign is scanned by a phone camera, the QR URL opens this page.

**URL format:**

```
https://YOUR_DOMAIN/verify.html?h=<hmac_partial>&fp=<sender_fp_partial>&r=<recipient_name>&ts=<iso_timestamp>
```

Also accepts hash-based format:
```
https://YOUR_DOMAIN/verify.html#h=...&fp=...&r=...&ts=...
```

**Attribution states rendered:**

| State | Condition | Display |
|---|---|---|
| 🎯 Full Attribution | `r` param present | Recipient name, timestamp, instructions to verify file |
| 🔏 Partial Attribution | `h` param present, no `r` | HMAC partial + sender FP, instructions to verify file |
| ⚠️ No Data | No params | Warning that no attribution data was found |

The page includes a **"Open in ShadowSign → Verify Tab"** button that deep-links back to the main app with all parameters pre-filled, and a **"Go to ShadowSign"** fallback button.

`verify.html` has **zero external dependencies** — all styles are inline, no fonts loaded from CDN, no JavaScript libraries. It is safe to serve as a static file from any CDN.

---

## Security Model

| Threat | Mitigation |
|---|---|
| Relay abuse (spam deliveries) | Origin/Referer check in `store.php`; max payload 10 MB; max TTL 24h |
| Payload interception in transit | Payload is AES-GCM encrypted client-side before leaving the browser |
| Direct payload file access | `payloads/.htaccess` auto-set to `Deny from all` |
| Directory listing | `Options -Indexes` in `d/.htaccess` |
| Wrong wallet decrypting | AES-GCM decryption fails cryptographically if wrong address is used; IV mismatch |
| Replay attack | Burn-after-read: `opened` flag set immediately after first successful POST fetch; `burn` endpoint called after decrypt |
| Document tampering detection | HMAC is bound to the original document hash — any byte change breaks verification |
| Recipient impersonation | HMAC includes recipient name — swapping the name breaks the HMAC |

**What ShadowSign does NOT protect against:**
- A recipient who photographs rather than screenshots their screen (ChromaGrid is weaker against optical re-capture, though still partially effective).
- Complete document re-creation from scratch (only the original document bytes produce a valid HMAC).
- A compromised sender key — if the RSA private key in browser storage is exfiltrated, a forged signature is possible.

---

## Cryptographic Primitives

| Operation | Algorithm | Library |
|---|---|---|
| Document hashing | SHA-256 | Web Crypto API (`crypto.subtle.digest`) |
| Fingerprinting | HMAC-SHA256 | Web Crypto API (`crypto.subtle.sign`) |
| Signing keypair | RSA-OAEP 2048-bit | Web Crypto API (`crypto.subtle.generateKey`) |
| Delivery encryption | AES-GCM 256-bit | Web Crypto API (`crypto.subtle.encrypt`) |
| Key derivation (delivery) | XOR(obfKey, addressBytes) | Custom (in-browser) |
| QR code generation | QRCode (bundled inline) | Bundled inline UMD |
| Wallet connection | EIP-1193 (`window.ethereum`) | MetaMask / injected provider |
| WalletConnect fallback | WalletConnect v2 SignClient | WalletConnect UMD (CDN) |

---

## Supported File Types

| File type | Watermark | Certificate embed | QR stamp | Delivery |
|---|---|---|---|---|
| PDF | ChromaGrid on rendered pages | PDF metadata field | Yes (pdf-lib) | Yes |
| PNG / JPEG / WebP | ChromaGrid on canvas | EXIF-style metadata | Yes | Yes |
| DOCX | ChromaGrid on embedded images | Custom XML part (`shadowsign.xml`) | Yes (injected image) | Yes |
| XLSX | ChromaGrid on embedded images | Custom XML part (`shadowsign.xml`) | Yes (injected image) | Yes |
| MP4 / MOV / AVI / WebM / MKV | Floating text + QR burned into frames (VideoEncoder → H.264 MP4, or MediaRecorder → WebM fallback) | HMAC in metadata/comment stream | Yes (per-frame) | Yes (as ZIP when metadata embed enabled) |

---

## Browser & Wallet Compatibility

**Browsers (for main app):**
- Chrome / Chromium 90+ ✅
- Firefox 88+ ✅
- Safari 15+ ✅ (requires HTTPS — `crypto.subtle` not available on `http://`)
- Edge 90+ ✅

**Wallets (for Web3 delivery unlock):**

| Wallet | Desktop (extension) | Mobile (deep-link) |
|---|---|---|
| MetaMask | ✅ | ✅ |
| Coinbase Wallet | ✅ | ✅ |
| Trust Wallet | ✅ | ✅ |
| Rainbow | ✅ | ✅ |
| Brave Wallet | ✅ (built-in) | ✅ |

The delivery page (`index.php`) auto-detects mobile vs desktop and serves appropriate deep-link or extension install CTAs when no wallet is found.

---

## Configuration Reference

These constants/variables in the source files are the full set of things you may want to configure:

**`index.html`**

| Variable | Default | Purpose |
|---|---|---|
| `SHADOWSIGN_RELAY` | `https://YOUR_DOMAIN/d/store.php` | URL of the store endpoint for Web3 delivery |
| QR verify base URL | `https://YOUR_DOMAIN/verify.html#` | Base URL embedded in QR codes |
| WalletConnect `projectId` | (bundled) | WalletConnect v2 project ID — replace with your own from [cloud.walletconnect.com](https://cloud.walletconnect.com) |

**`store.php`**

| Constant | Default | Purpose |
|---|---|---|
| `MAX_PAYLOAD_BYTES` | `10 * 1024 * 1024` | Max upload size (10 MB) |
| `DEFAULT_TTL` | `86400` | Default expiry in seconds (24h) |
| `MAX_TTL` | `86400` | Maximum allowed TTL |
| `BASE_URL` | `https://YOUR_DOMAIN/d/` | Prefix for returned delivery URLs |
| `STORE_DIR` | `__DIR__ . '/payloads/'` | Server path for payload storage |

**`index.php`**

| Constant | Default | Purpose |
|---|---|---|
| `BASE_URL` | `https://YOUR_DOMAIN/d/` | URL base for self-referencing delivery links |
| `STORE_DIR` | `__DIR__ . '/payloads/'` | Must match `store.php` |

---

## Contributing

Pull requests welcome. Areas of active interest:

- **Stronger steg encoding** — Increasing ChromaGrid payload capacity and JPEG resilience.
- **IPFS delivery** — Replace the PHP relay with a decentralized storage backend.
- **Multi-page DOCX watermarking** — ChromaGrid currently targets embedded images only.
- **Video steg channel** — ChromaGrid is not yet applied to video frames; a chroma-channel steg layer on frames would survive re-encoding better than the visible overlay alone.
- **Audio fingerprinting** — Inaudible watermark in the audio track for audio-only or audio-forward leaks.
- **Threshold signatures** — Multi-party signing where M-of-N senders must co-sign.
- **Mobile app wrapper** — React Native or Flutter shell for camera-based verify.

Please open an issue before submitting large PRs.

---

## License

MIT License. See `LICENSE` for full text.

---

*Built with the Web Crypto API, pdf-lib, WalletConnect v2, and no backend dependencies for its core features.*
