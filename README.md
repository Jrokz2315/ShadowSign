# 🔏 ShadowSign

**Cryptographic document signing, watermarking, and leak attribution — entirely in your browser.**

No server. No uploads. No accounts. One HTML file.

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Zero Server](https://img.shields.io/badge/server-none-green.svg)](#architecture)
[![Web Crypto API](https://img.shields.io/badge/crypto-Web%20Crypto%20API-blueviolet.svg)](#cryptography)

---

## What It Does

ShadowSign lets you send uniquely fingerprinted copies of a document to multiple recipients. Every copy carries a cryptographic signature, a visible watermark, and hidden attribution embedded invisibly in the file itself. If any copy leaks, you know exactly who had it.

Everything — key generation, signing, encryption, watermarking, steganography — runs entirely in your browser using the native Web Crypto API. Nothing is ever transmitted to a server.

---

## Features

| Feature | Detail |
|---|---|
| 🔏 **RSA-2048 signing** | Keypair generated in-browser via Web Crypto API |
| 💧 **Visible watermarks** | Diagonal overlay with customizable text, color, and opacity |
| 🕵️ **HMAC-SHA256 fingerprinting** | Unique per-recipient cryptographic fingerprint |
| 🖼️ **LSB steganography** | HMAC hidden in image pixel data (images only) |
| 📋 **Full send ledger** | Every send recorded in your portable `.shadowid` file |
| 🔐 **Secure delivery** | AES-GCM-256 + RSA-OAEP encrypted delivery pages |
| 🔍 **Verification** | Drag any signed file to extract and confirm attribution |
| 📄 **Auto format detection** | PDF → PDF, PNG → PNG, DOCX → DOCX, XLSX → XLSX |
| 🚫 **Zero egress** | No data leaves your device, ever |
| 📦 **Single file** | One `index.html` — no build step, no dependencies to install |

---

## Quick Start

1. Download `index.html`
2. Open it in any modern browser (Chrome, Firefox, Edge, Safari)
3. Create an identity keypair → drop your document → add recipients → sign

That's it. No installation, no account, no server.

> **Self-hosting:** Drop `index.html` on GitHub Pages, Netlify, Vercel, any static host, or run it directly from your filesystem (`file://`).

---

## How It Works

### The Identity System

When you first open ShadowSign, you create an **identity** — a cryptographic keypair generated entirely in-browser:

```
crypto.subtle.generateKey(RSA-OAEP, 2048-bit, SHA-256)
  → publicKey  (SPKI format, hex-encoded)
  → privateKey (PKCS8 format, hex-encoded)
  → fingerprint = SHA-256(publicKey)
```

Your identity is exported as a `.shadowid` file — a JSON file containing your keypair and your complete send ledger. **You keep this file.** Load it every session to restore your history. Creating a new identity starts a blank ledger.

```json
{
  "schema": "shadowsign-identity-v3",
  "name": "Alice",
  "email": "alice@example.com",
  "publicKeyHex": "...",
  "privateKeyHex": "...",
  "fingerprint": "sha256-of-public-key",
  "created": "2025-01-01T00:00:00.000Z",
  "ledger": [ ... ]
}
```

---

### The Signing Pipeline

For each recipient, ShadowSign runs this pipeline:

```
1. SHA-256 hash the original document
2. Generate a unique HMAC-SHA256 per recipient:
     HMAC(key=sender_fingerprint, msg=docHash + timestamp + recipientId)
3. Build watermark text (substituting {name}, {date}, {hash} tokens)
4. Embed attribution into the file (format-specific — see below)
5. Record the full send in the .shadowid ledger
```

Every recipient's copy has a different HMAC. If a copy leaks, you compare its embedded HMAC against your ledger and instantly identify who had that copy.

---

### Format-Specific Signing

#### PDF
- Watermark drawn diagonally across every page using `pdf-lib`
- Recipient identity embedded in PDF metadata fields:
  - `Author` → sender name
  - `Subject` → `ss_recipient:Name||ss_email:email||ss_id:uuid`
  - `Keywords` → `ss_hmac:..., ss_fp:..., ss_ts:..., ss_doc:...`
- Optional cryptographic certificate page appended (dark-themed, monospaced)

#### PNG / JPG / Images
- Original image loaded onto an HTML5 Canvas
- Watermark painted as rotated text overlay
- **LSB Steganography**: the HMAC, sender fingerprint, recipient name/email/ID are encoded into the least-significant bits of the red channel of image pixels
  - Payload format: `hmac|fingerprint|name|email|recipientId`
  - Invisible to the human eye; recoverable by ShadowSign's Verify tab
- Attribution watermark written in corner as small text

#### DOCX
- If source is a `.docx`: file is parsed as a ZIP, the signature XML is injected directly before `</w:body>` in `word/document.xml`
- If source is any other format: a fresh valid `.docx` is built from scratch containing the content + signature block
- Identity stored in `docProps/custom.xml` as Open Packaging Convention custom properties:
  - `ss_recipient`, `ss_recipient_email`, `ss_sender`, `ss_key_fp`, `ss_hmac`, `ss_doc_sha256`, `ss_timestamp`

#### XLSX
- Source CSV or `.xlsx` data is placed in `sheet1`
- A `ShadowSign Cert` worksheet is appended with all attribution data in key/value rows
- Identity also stored in `docProps/custom.xml` (same schema as DOCX above)
- Full OOXML structure built via JSZip: workbook, styles, relationships, content types

#### Any Other File → ZIP Bundle
- Original file preserved at `original/<filename>`
- `<filename>_<recipient>.sig` — plain-text signature certificate
- `manifest.json` — machine-readable JSON with all attribution fields
- `VERIFY.md` — human-readable verification table in Markdown

---

### The Secure Delivery System

ShadowSign can optionally wrap signed files in an **encrypted delivery page** — a standalone `.html` file the recipient opens in their browser to decrypt with a passphrase.

**How it works:**

```
Sender side:
  1. Generate a fresh RSA-2048 keypair (ephemeral, delivery-specific)
  2. Derive an AES-256-GCM wrapping key from passphrase:
       PBKDF2(password, salt=random16, iterations=200,000, hash=SHA-256)
  3. Wrap the ephemeral private key with AES-GCM:
       wrapKey(pkcs8, privateKey, wrapKey, AES-GCM)
  4. For each file:
       - Generate a random AES-256-GCM key
       - Encrypt file bytes: AES-GCM(fileKey, fileData)
       - Encrypt fileKey: RSA-OAEP(ephemeral publicKey, rawFileKey)
  5. Serialize everything into a self-contained HTML payload
  6. Download as shadowsign_delivery_<recipient>.html

Recipient side:
  1. Opens the .html file in their browser
  2. Enters passphrase → PBKDF2 derives the wrapping key
  3. Unwraps the private key: AES-GCM decrypt
  4. Decrypts each file: RSA-OAEP → AES key → AES-GCM decrypt
  5. Files download automatically
  6. Page marks itself "burned" in localStorage — one-time use
```

**Security properties:**
- Passphrase never stored or transmitted
- 200,000 PBKDF2 iterations with a random 16-byte salt — brute force resistant
- Each file encrypted with a unique AES-256-GCM key
- The RSA keypair is ephemeral — generated fresh per delivery, discarded after
- Delivery page self-destructs after first successful decryption (localStorage burn flag)
- Share the passphrase via a different channel (Signal, phone call, etc.)

---

### Verification

The **Verify tab** accepts any ShadowSign-signed file and extracts attribution without needing the original `.shadowid`. It reads attribution from:

| Format | Where attribution is read from |
|---|---|
| PNG/JPG | LSB steganography in pixel data |
| PDF | PDF metadata fields (`Author`, `Subject`, `Keywords`) |
| DOCX | `docProps/custom.xml` custom properties |
| XLSX | `docProps/custom.xml` + optional cert worksheet |
| ZIP | `manifest.json` + `.sig` file |

If your `.shadowid` is loaded in the Identity tab simultaneously, the verifier will cross-reference the extracted HMAC against your ledger. A match confirms attribution with **LEDGER CONFIRMED** status.

**Ledger matching priority:**
1. Exact HMAC match (most reliable)
2. Recipient UUID match
3. Recipient name + timestamp within 5 seconds
4. Recipient name alone (only if unambiguous — one match in ledger)

---

## Cryptography Reference

All cryptography is performed exclusively via the browser's native **Web Crypto API** (`window.crypto.subtle`). No third-party crypto libraries are used.

| Operation | Algorithm | Details |
|---|---|---|
| Keypair generation | RSA-OAEP | 2048-bit modulus, SHA-256, public exponent 65537 |
| Document hashing | SHA-256 | `crypto.subtle.digest` |
| Per-recipient fingerprint | HMAC-SHA256 | `crypto.subtle.sign` with HMAC |
| Identity key wrapping | AES-256-GCM | 12-byte IV, passphrase-derived via PBKDF2 |
| Passphrase derivation | PBKDF2 | 200,000 iterations, SHA-256, 16-byte random salt |
| File encryption (delivery) | AES-256-GCM | Random 12-byte IV per file, unique key per file |
| Delivery key encryption | RSA-OAEP/SHA-256 | Ephemeral keypair, wraps the per-file AES key |
| Steganography payload | LSB encoding | 2 bits per pixel, red channel only |
| Key export format | SPKI / PKCS8 | Hex-encoded in `.shadowid` JSON |

---

## The `.shadowid` File

Your identity file is everything — treat it like a private key.

```
⚠️  Never lose your .shadowid.
    It holds your private key and your complete send ledger.
    Creating a new identity = blank ledger = lost attribution history.
    Export it after every session.
```

**What's inside:**

```json
{
  "schema": "shadowsign-identity-v3",
  "name": "Your Name",
  "email": "you@example.com",
  "publicKeyHex": "30820122...",       // RSA public key, SPKI, hex
  "privateKeyHex": "308204be...",      // RSA private key, PKCS8, hex
  "fingerprint": "a3f8c2...",          // SHA-256 of public key
  "created": "2025-01-01T00:00:00Z",
  "ledger": [
    {
      "recipient": "Bob Smith",
      "email": "bob@example.com",
      "recipientId": "uuid-v4",
      "filename": "contract.pdf",
      "format": "pdf",
      "fileSize": "142.3KB",
      "timestamp": "2025-06-01T14:32:00Z",
      "docHash": "sha256-of-original-doc",
      "hmac": "unique-per-recipient-hmac",
      "senderFingerprint": "your-key-fingerprint",
      "keyUsed": "RSA-OAEP/SHA-256 + HMAC-SHA256",
      "watermarkText": "CONFIDENTIAL · Bob Smith · 2025-06-01",
      "stegUsed": true,
      "certAppended": true,
      "notes": ""
    }
  ]
}
```

---

## File Format Support

| Input | Output | Watermark | Fingerprint | Steganography | Cert page |
|---|---|---|---|---|---|
| `.pdf` | `.pdf` | ✅ diagonal overlay | ✅ metadata | — | ✅ appended page |
| `.png` `.jpg` `.jpeg` `.gif` `.webp` `.bmp` | `.png` | ✅ canvas overlay | ✅ corner text | ✅ LSB pixels | — |
| `.docx` `.doc` | `.docx` | ✅ injected paragraph | ✅ custom XML | — | ✅ appended section |
| `.xlsx` `.xls` | `.xlsx` | ✅ injected row | ✅ custom XML | — | ✅ cert sheet |
| `.csv` | `.xlsx` | ✅ injected row | ✅ custom XML | — | ✅ cert sheet |
| `.txt` `.md` `.log` | `.zip` bundle | — | ✅ manifest + .sig | — | ✅ .sig file |
| Any other | `.zip` bundle | — | ✅ manifest + .sig | — | ✅ .sig file |

---

## Dependencies

ShadowSign loads two CDN libraries for file format handling. No npm, no build step.

| Library | Version | Purpose | CDN |
|---|---|---|---|
| [pdf-lib](https://pdf-lib.js.org/) | 1.17.1 | PDF read/write/watermark | cdnjs.cloudflare.com |
| [JSZip](https://stuk.github.io/jszip/) | 3.10.1 | DOCX/XLSX/ZIP assembly | cdnjs.cloudflare.com |

All cryptography uses the browser's native **Web Crypto API** — no third-party crypto dependency.

---

## Browser Support

| Browser | Support |
|---|---|
| Chrome / Edge 90+ | ✅ Full |
| Firefox 90+ | ✅ Full |
| Safari 15+ | ✅ Full |
| Opera 80+ | ✅ Full |
| IE / Legacy | ❌ Not supported |

**Requirement:** Web Crypto API (`window.crypto.subtle`) — available in all modern browsers over HTTPS or `localhost`. Works on `file://` in Chrome and Edge; Firefox requires a local server for `file://` due to CSP restrictions.

---

## Self-Hosting

ShadowSign is a single static HTML file. No backend, no database, no configuration.

**GitHub Pages:**
```bash
git clone https://github.com/Jrokz2315/ShadowSign
# Enable GitHub Pages on the repo → serves index.html automatically
```

**Netlify / Vercel:**
- Drag and drop `index.html` → live in seconds

**Local filesystem:**
- Chrome/Edge: Open `index.html` directly — works fully
- Firefox: Serve locally due to Web Crypto `file://` restrictions:
```bash
python3 -m http.server 8080
# Open http://localhost:8080
```

**Any static host (Nginx, Apache, S3, etc.):**
- Upload `index.html` — no special configuration needed

---

## Security Model

**What ShadowSign protects against:**

- ✅ Identifying which recipient leaked a document (attribution)
- ✅ Proving a specific copy was sent to a specific person (ledger + HMAC)
- ✅ Intercepted delivery files without the passphrase (AES-256-GCM + RSA-OAEP)
- ✅ Stripping visible watermarks — HMAC fingerprint survives in metadata and pixels
- ✅ Recipient denying they received a specific copy — HMAC is unique and ledger-recorded

**What ShadowSign does NOT protect against:**

- ❌ A recipient photographing a document on screen (screenshotting bypasses all digital fingerprinting)
- ❌ Loss of your `.shadowid` file — no recovery mechanism exists
- ❌ A recipient who intentionally strips all metadata and rebuilds the file from scratch
- ❌ Quantum computing attacks on RSA-2048 (theoretical, long-horizon threat)

---

## Threat Model: The Leak Attribution Chain

```
Sender signs document for Alice, Bob, and Carol.
Each copy has a different HMAC:

  Alice's copy → HMAC: a1b2c3...
  Bob's copy   → HMAC: d4e5f6...
  Carol's copy → HMAC: g7h8i9...

Document leaks publicly.

Sender opens the leaked file in ShadowSign Verify tab:
  → Extracts HMAC from metadata / steganography
  → Compares against .shadowid ledger
  → Match found: HMAC d4e5f6... = Bob's copy
  → Attribution confirmed ✅
```

---

## Architecture

```
index.html
├── HTML structure (topbar, grid, cards, modals, overlays)
├── CSS (design system — dark glass aesthetic, CSS variables)
└── JavaScript (inline, no build step)
    ├── State management (S object — identity, recipients, session)
    ├── Identity system
    │   ├── _doCreateIdentity()   — RSA-2048 keygen via Web Crypto
    │   ├── loadIdFile()          — parse .shadowid JSON, import keys
    │   ├── doExportId()          — serialize identity + ledger → .shadowid
    │   └── updateIdPanel()       — sidebar UI sync
    ├── Signing pipeline
    │   ├── generateAll()         — orchestrates all recipients
    │   ├── buildPDF()            — pdf-lib watermark + cert + metadata
    │   ├── buildPNG()            — Canvas watermark + LSB steg
    │   ├── buildDOCX()           — JSZip OOXML assembly + injection
    │   ├── buildXLSX()           — JSZip OOXML spreadsheet build
    │   └── buildZIP()            — bundle: original + .sig + manifest
    ├── Steganography
    │   ├── lsbEmbed()            — write bits into red channel LSBs
    │   └── lsbExtract()          — read bits back from red channel
    ├── Verification
    │   └── runVerify()           — multi-format attribution extraction
    ├── Secure delivery
    │   ├── doGenerateDelivery()  — encrypt files, build delivery HTML
    │   └── buildDeliveryHtml()   — self-contained recipient decrypt page
    └── UI
        ├── Onboarding wizard     — 5-step guide, localStorage gated
        ├── Identity modal        — create / load .shadowid
        ├── Ledger modal          — per-send detail view
        └── Delivery modal        — passphrase + encrypt + download
```

---

## Watermark Token Reference

The watermark text field supports dynamic tokens:

| Token | Replaced with |
|---|---|
| `{name}` | Recipient's full name |
| `{date}` | Current date (locale format) |
| `{hash}` | First 8 characters of document SHA-256 |

**Example:** `CONFIDENTIAL · {name} · {date}` → `CONFIDENTIAL · BOB SMITH · 6/1/2025`

---

## Contributing

Pull requests welcome. Areas that would benefit most:

- **ETH wallet + passphrase dual-factor decryption** (see discussion in repo)
- **PDF steganography** — LSB embedding in PDF image streams
- **HEIC / AVIF image support**
- **Batch file signing** — multiple documents in one session
- **Dark/light theme toggle**
- **Localization / i18n**

Please open an issue before starting large changes.

---

## License

MIT License

Copyright (c) 2025 ShadowSign Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Support & Contact

- 🐛 **Issues:** [github.com/Jrokz2315/ShadowSign/issues](https://github.com/Jrokz2315/ShadowSign/issues)
- 📧 **Email:** shadowsign@hackeao.com
- ☕ **Bitcoin:** `bc1q53hn7mrnjj68fv756r9qr6gc8frqavvdl5yaqr`

---

*ShadowSign — because trust should be verifiable.*
