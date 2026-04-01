<?php
/**
 * ShadowSign Web3 Delivery — Retrieve & Burn Endpoint
 * GET  /d/?t=TOKEN          → serves the delivery page HTML
 * POST /d/?t=TOKEN&burn=1   → marks as opened (burn-after-read), returns payload JSON
 */

define('STORE_DIR', __DIR__ . '/payloads/');
define('BASE_URL', 'https://YOUR_DOMAIN/d/'); // ← SET YOUR DOMAIN

$token = isset($_GET['t']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['t']) : '';
$burn  = isset($_GET['burn']) && $_GET['burn'] === '1';

if (!$token) {
    http_response_code(400);
    die('Missing token');
}

$file = STORE_DIR . $token . '.json';
if (!file_exists($file)) {
    http_response_code(404);
    die(renderError('Not Found', 'This delivery link is invalid or has expired.'));
}

$envelope = json_decode(file_get_contents($file), true);
if (!$envelope) {
    http_response_code(500); die(renderError('Error', 'Corrupted delivery record.'));
}

// Check expiry
if ($envelope['expires'] < time()) {
    @unlink($file);
    die(renderError('Expired', 'This delivery link has expired. Ask the sender for a new one.'));
}

// BURN endpoint (POST from JS after successful decrypt)
if ($burn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $envelope['opened'] = true;
    file_put_contents($file, json_encode($envelope), LOCK_EX);
    // Schedule deletion — set expires to past
    $envelope['expires'] = 0;
    file_put_contents($file, json_encode($envelope), LOCK_EX);
    echo json_encode(['ok'=>true]);
    exit;
}

// PAYLOAD fetch (POST from JS, returns the encrypted payload for decryption)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    if ($envelope['opened']) {
        http_response_code(410);
        echo json_encode(['ok'=>false,'error'=>'already_opened']);
        exit;
    }
    echo json_encode(['ok'=>true,'payload'=>$envelope['payload']]);
    exit;
}

// GET — serve the delivery page
header('Content-Type: text/html; charset=utf-8');
echo renderDeliveryPage($token, $envelope);
exit;

// ─────────────────────────────────────────────────────
function renderError($title, $msg) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>ShadowSign · ' . htmlspecialchars($title) . '</title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;600&display=swap" rel="stylesheet">
<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#05080f;color:#eaf0fb;font-family:Geist,sans-serif;
display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;text-align:center}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:40px;max-width:420px}
.ic{font-size:52px;margin-bottom:18px}.t{font-size:22px;font-weight:600;margin-bottom:10px;color:#ff5e7e}
.s{font-size:13px;color:#8da4c0;line-height:1.6}</style></head>
<body><div class="card"><div class="ic">🔒</div>
<div class="t">' . htmlspecialchars($title) . '</div>
<div class="s">' . htmlspecialchars($msg) . '</div></div></body></html>';
}

function renderDeliveryPage($token, $envelope) {
    $created = date('M j, Y', $envelope['created']);
    $expires = date('M j, Y g:i A', $envelope['expires']);
    $apiBase = BASE_URL . '?t=' . $token;
    
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ShadowSign · Secure Web3 Delivery</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><text y=\'.9em\' font-size=\'90\'>🔏</text></svg>">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Geist:wght@300;400;500;600&family=Geist+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--ink:#05080f;--az:#f5b731;--jd:#00d9a0;--rs:#ff5e7e;--ms:#8da4c0;--snow:#eaf0fb}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--ink);color:var(--snow);font-family:Geist,system-ui,sans-serif;font-size:14px;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:\'\';position:fixed;inset:0;pointer-events:none;background:radial-gradient(ellipse 700px 500px at 20% 10%,rgba(245,183,49,.06),transparent),radial-gradient(ellipse 500px 600px at 80% 90%,rgba(0,217,160,.05),transparent)}
.wrap{position:relative;z-index:1;width:100%;max-width:500px}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:30px;justify-content:center}
.logo-seal{width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,rgba(79,142,255,.25),rgba(0,217,160,.15));border:1px solid rgba(79,142,255,.3);display:flex;align-items:center;justify-content:center;font-size:21px}
.logo-name{font-family:Instrument Serif,serif;font-size:26px;font-style:italic}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:20px;overflow:hidden}
.card-head{padding:24px 28px 20px;border-bottom:1px solid rgba(255,255,255,.07)}
.card-body{padding:24px 28px 28px;text-align:center}
.badge{display:inline-flex;align-items:center;gap:6px;font-family:Geist Mono,monospace;font-size:9px;letter-spacing:1.5px;padding:4px 10px;border-radius:20px;margin-bottom:12px;background:rgba(245,183,49,.12);border:1px solid rgba(245,183,49,.25);color:var(--az)}
.ht{font-family:Instrument Serif,serif;font-size:23px;font-style:italic;margin-bottom:5px}
.hs{font-family:Geist Mono,monospace;font-size:10px;color:var(--ms)}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:13px;border-radius:10px;font-family:Geist,sans-serif;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all .2s;margin-top:15px}
.btn-p{background:linear-gradient(90deg,#f5b731,#f57e31);color:#fff;box-shadow:0 4px 18px rgba(245,183,49,.3)}
.btn-p:hover:not(:disabled){transform:translateY(-1px);filter:brightness(1.1)}
.btn-p:disabled{opacity:.35;cursor:not-allowed}
.fpill{display:flex;align-items:center;text-align:left;gap:11px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:10px 14px;margin-bottom:20px}
.fp-nm{flex:1;font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--snow)}
.enc-badge{font-family:Geist Mono,monospace;font-size:8px;padding:2px 7px;border-radius:4px;background:rgba(245,183,49,.1);border:1px solid rgba(245,183,49,.2);color:var(--az);flex-shrink:0}
.err{color:var(--rs);font-family:Geist Mono,monospace;font-size:12px;margin-bottom:14px;display:none;line-height:1.5}
.prog-wrap{display:none;margin-bottom:16px;margin-top:15px}
.prog{height:5px;background:rgba(255,255,255,.06);border-radius:5px;overflow:hidden;margin-bottom:8px}
.prog-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--az),var(--jd));border-radius:5px;transition:width .35s cubic-bezier(.4,0,.2,1)}
.status{font-family:Geist Mono,monospace;font-size:10px;color:var(--jd);text-align:center}
.burned{text-align:center;padding:36px 28px}
.burn-ic{font-size:60px;margin-bottom:18px;filter:grayscale(1);opacity:.4}
.burn-title{font-family:Instrument Serif,serif;font-size:28px;font-style:italic;color:var(--rs);margin-bottom:10px}
.burn-sub{font-family:Geist Mono,monospace;font-size:11px;color:rgba(141,164,192,.45);line-height:1.8}
.exp-note{font-family:Geist Mono,monospace;font-size:9px;color:rgba(141,164,192,.4);margin-top:14px;text-align:center}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo"><div class="logo-seal">🔏</div><div class="logo-name">ShadowSign</div></div>
  <div class="card" id="main-card">
    <div class="card-head">
      <div class="badge">WEB3 ENCRYPTED DELIVERY</div>
      <div class="ht">Secure Package</div>
      <div class="hs">Created: ' . $created . '&nbsp;&nbsp;·&nbsp;&nbsp;Expires: ' . $expires . '</div>
    </div>
    <div class="card-body" id="unlock-panel">
      <div class="fpill">
        <span style="font-size:17px;flex-shrink:0">📦</span>
        <span class="fp-nm" id="fname-lbl">Encrypted Payload</span>
        <span class="enc-badge">AES-GCM-256</span>
      </div>
      <div class="err" id="err-msg"></div>
      <div class="prog-wrap" id="prog-wrap">
        <div class="prog"><div class="prog-fill" id="prog-fill"></div></div>
        <div class="status" id="status-txt"></div>
      </div>
      <button class="btn btn-p" id="unlock-btn" onclick="doUnlock()">🔗 Connect Wallet to Unlock</button>
      <div style="font-family:Geist Mono,monospace;font-size:9px;color:rgba(141,164,192,.4);line-height:1.65;margin-top:16px;text-align:center">Works with any Ethereum wallet · MetaMask, Coinbase, Trust, Rainbow<br>Connect your wallet · Decrypt locally · File downloads automatically</div>
      <div class="exp-note">🔥 This link self-destructs after download</div>
    </div>
  </div>
</div>
<script>
const API = ' . json_encode($apiBase) . ';
let p = null; // payload loaded from server

function b64ToU8(b){const s=atob(b),u=new Uint8Array(s.length);for(let i=0;i<s.length;i++)u[i]=s.charCodeAt(i);return u;}

function dlBlob(blob, name) {
  try {
    const isCB = !!(window.ethereum && window.ethereum.isCoinbaseWallet) || /CoinbaseWallet|CBW\//.test(navigator.userAgent||"");
    if (isCB) {
      const reader = new FileReader();
      reader.onloadend = () => { const a = document.createElement("a"); a.href = reader.result; a.download = name; a.style.display="none"; document.body.appendChild(a); a.click(); setTimeout(()=>{try{document.body.removeChild(a);}catch(e){}},2000); };
      reader.readAsDataURL(blob); return;
    }
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a"); a.href = url; a.download = name; a.style.display="none";
    document.body.appendChild(a); a.click();
    setTimeout(()=>{ try{URL.revokeObjectURL(url);document.body.removeChild(a);}catch(e){} },2000);
  } catch(err) {
    const st = document.getElementById("status-txt");
    if(st){ const url2=URL.createObjectURL(blob); st.innerHTML=\'✅ Ready — <a href="\'+url2+\'" download="\'+name+\'" style="color:#f5b731;font-weight:700">Tap here to download \'+name+\'</a>\'; }
  }
}

function setP(pct,t){document.getElementById("prog-wrap").style.display="block";document.getElementById("prog-fill").style.width=pct+"%";if(t)document.getElementById("status-txt").textContent=t;}
function showErr(msg){document.getElementById("err-msg").innerHTML=msg;document.getElementById("err-msg").style.display="block";document.getElementById("prog-wrap").style.display="none";document.getElementById("unlock-btn").disabled=false;}

// Fetch payload from server on load
async function loadPayload() {
  try {
    const r = await fetch(API, {method:"POST"});
    const data = await r.json();
    if (!r.ok || !data.ok) {
      if (data.error === "already_opened") { showBurned(); return; }
      showErr("Failed to load delivery: " + (data.error||"unknown error"));
      return;
    }
    p = JSON.parse(data.payload);
    document.getElementById("fname-lbl").textContent = p.fname || "Encrypted File";
    document.getElementById("unlock-btn").disabled = false;
  } catch(e) {
    showErr("Network error loading delivery. Please refresh.");
  }
}

// Wallet detection poller
if (!window.ethereum) {
  let polls = 0;
  const poll = setInterval(() => {
    polls++;
    if (window.ethereum) {
      clearInterval(poll);
      const btn = document.getElementById("unlock-btn");
      if (btn) { btn.textContent = "🔗 Wallet Detected — Click to Unlock"; btn.style.boxShadow = "0 4px 18px rgba(0,217,160,.3)"; }
    }
    if (polls > 15) clearInterval(poll);
  }, 400);
}

const _isAndroid = /Android/i.test(navigator.userAgent);
const _isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
const _pageUrl = encodeURIComponent(location.href);
const DELIVERY_WALLETS = [
  {name:"MetaMask",   icon:"🦊",
   deeplink:"metamask://dapp/"+location.host+location.pathname+location.search,
   store:_isAndroid?"https://play.google.com/store/apps/details?id=io.metamask":"https://apps.apple.com/app/metamask/id1438144202"},
  {name:"Coinbase Wallet",icon:"🔵",
   deeplink:"cbwallet://dapp?url="+_pageUrl,
   store:_isAndroid?"https://play.google.com/store/apps/details?id=org.toshi":"https://apps.apple.com/app/coinbase-wallet/id1278383455"},
  {name:"Trust Wallet",icon:"🛡️",
   deeplink:"trust://open_url?coin_id=60&url="+_pageUrl,
   store:_isAndroid?"https://play.google.com/store/apps/details?id=com.wallet.crypto.trustapp":"https://apps.apple.com/app/trust-crypto-bitcoin-wallet/id1288339409"},
  {name:"Rainbow",    icon:"🌈",
   deeplink:"rainbow://dapp?url="+_pageUrl,
   store:_isAndroid?"https://play.google.com/store/apps/details?id=me.rainbow":"https://apps.apple.com/app/rainbow-ethereum-wallet/id1457119021"},
  {name:"Brave Wallet",icon:"🦁",
   deeplink:null,
   store:_isAndroid?"https://play.google.com/store/apps/details?id=com.brave.browser":"https://apps.apple.com/app/brave-private-web-browser-vpn/id1052879175"}
];

async function getAddr() {
  if (window.ethereum) {
    const accounts = await window.ethereum.request({method:"eth_requestAccounts"});
    return accounts[0].toLowerCase();
  }
  const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
  const panel = document.getElementById("unlock-panel");
  const btn = document.getElementById("unlock-btn");
  const existing = document.getElementById("_wallet_pick");
  if (existing) existing.remove();
  const pick = document.createElement("div");
  pick.id = "_wallet_pick";
  pick.style.cssText = "margin-top:14px;border-top:1px solid rgba(255,255,255,.08);padding-top:14px";
  const lbl = document.createElement("div");
  lbl.style.cssText = "font-family:Geist Mono,monospace;font-size:10px;color:rgba(141,164,192,.6);margin-bottom:10px;text-align:center";
  if (isMobile) {
    lbl.innerHTML = "No wallet detected. Open this page inside your wallet app\u2019s browser, or install one below.";
    pick.appendChild(lbl);
    DELIVERY_WALLETS.forEach(function(w) {
      const a = document.createElement("a");
      a.href = w.deeplink || w.store;
      a.target = "_blank"; a.rel = "noopener";
      a.style.cssText = "display:flex;align-items:center;gap:10px;padding:11px 14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;margin-bottom:7px;color:#eaf0fb;font-family:Geist,sans-serif;font-size:13px;font-weight:600;text-decoration:none";
      const action = w.deeplink ? "Open in" : (_isAndroid ? "Get on Google Play:" : "Get on App Store:");
      const sub    = w.deeplink ? "Tap to open wallet app with this page loaded" : "Install, then open this page in the wallet browser";
      a.innerHTML = "<span style=\'font-size:18px\'>"+w.icon+"</span><div><div>"+action+" "+w.name+"</div><div style=\'font-size:10px;color:rgba(141,164,192,.5);margin-top:1px\'>"+sub+"</div></div>";
      if (w.deeplink) {
        a.onclick = function(e) {
          e.preventDefault();
          if (_isIOS) {
            var t0 = Date.now(); location.href = w.deeplink;
            setTimeout(function(){ if(Date.now()-t0<2500) location.href = w.store; }, 1500);
          } else {
            var fr = document.createElement("iframe");
            fr.style.cssText = "display:none;width:0;height:0;position:absolute";
            fr.src = w.deeplink; document.body.appendChild(fr);
            setTimeout(function(){ try{document.body.removeChild(fr);}catch(err){} }, 2000);
          }
          setTimeout(function(){
            a.href = w.store; a.onclick = null;
            var d = a.querySelector("div > div:last-child");
            if(d) d.textContent = "Not installed? Get from "+(_isAndroid?"Google Play":"App Store");
          }, 1800);
        };
      }
      pick.appendChild(a);
    });
  } else {
    const extUrls = {
      "MetaMask":      "https://metamask.io/download/",
      "Coinbase Wallet":"https://www.coinbase.com/wallet/downloads",
      "Trust Wallet":  "https://trustwallet.com/browser-extension",
      "Rainbow":       "https://rainbow.me/download",
      "Brave Wallet":  "https://brave.com/download/"
    };
    lbl.innerHTML = "No wallet extension detected.<br><span style=\'font-size:9.5px;color:rgba(141,164,192,.6)\'>Install one below, then click <strong style=\'color:#eaf0fb\'>Unlock</strong> again.</span>";
    pick.appendChild(lbl);
    DELIVERY_WALLETS.forEach(function(w) {
      const a = document.createElement("a");
      a.href = extUrls[w.name] || "https://metamask.io/download/";
      a.target = "_blank"; a.rel = "noopener";
      a.style.cssText = "display:flex;align-items:center;gap:10px;padding:11px 14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;margin-bottom:7px;color:#eaf0fb;font-family:Geist,sans-serif;font-size:13px;font-weight:600;text-decoration:none";
      a.innerHTML = "<span style=\'font-size:18px\'>"+w.icon+"</span><div><div>"+w.name+"</div></div><span style=\'color:rgba(141,164,192,.3);font-size:13px;margin-left:auto\'>↗</span>";
      pick.appendChild(a);
    });
    const rb = document.createElement("button");
    rb.textContent = "🔄 Reload after installing";
    rb.style.cssText = "display:block;width:100%;padding:10px;background:rgba(0,217,160,.08);border:1px solid rgba(0,217,160,.2);border-radius:8px;margin-top:4px;color:#00d9a0;font-family:Geist,sans-serif;font-size:12px;font-weight:600;cursor:pointer";
    rb.onclick = () => location.reload();
    pick.appendChild(rb);
  }
  panel.appendChild(pick);
  if (btn) btn.disabled = false;
  throw new Error("Install a wallet extension, then click Unlock again");
}

async function doUnlock() {
  if (!p) { showErr("Delivery not loaded yet. Please refresh."); return; }
  const btn = document.getElementById("unlock-btn");
  btn.disabled = true;
  document.getElementById("err-msg").style.display = "none";
  try {
    setP(10, "Connecting wallet…");
    const userAddr = await getAddr();
    setP(40, "Verifying address & decrypting…");
    const addrBytes = new TextEncoder().encode(userAddr);
    const obfKey = b64ToU8(p.obfKey);
    const rawAes = new Uint8Array(32);
    for(let i=0;i<32;i++) rawAes[i] = obfKey[i] ^ addrBytes[i % addrBytes.length];
    setP(70, "Decrypting file…");
    const aesKey = await crypto.subtle.importKey("raw", rawAes, {name:"AES-GCM"}, false, ["decrypt"]);
    let decData;
    try { decData = await crypto.subtle.decrypt({name:"AES-GCM",iv:b64ToU8(p.iv)}, aesKey, b64ToU8(p.encData)); }
    catch(err) { throw new Error("Decryption failed — wrong wallet address. Expected: "+p.targetAddress.slice(0,6)+"…"+p.targetAddress.slice(-4)); }
    dlBlob(new Blob([decData]), p.fname);
    setP(100, "✅ Done — file downloaded. This link will now self-destruct.");
    // Burn
    await fetch(API + "&burn=1", {method:"POST"}).catch(()=>{});
    await new Promise(r=>setTimeout(r,6000));
    showBurned();
  } catch(e) {
    if (e.message && e.message.includes("rejected")) { showErr("Connection cancelled."); }
    else { showErr(e.message); }
  }
}

function showBurned() {
  const c = document.getElementById("main-card");
  if (!c) return;
  c.innerHTML = "<div class=\'burned\'><div class=\'burn-ic\'>🔥</div><div class=\'burn-title\'>Delivery Opened</div><div class=\'burn-sub\'>This Web3 delivery has already been opened.<br>Files were decrypted and downloaded.<br><br><span style=\'color:rgba(255,94,126,.5)\'>This link is no longer usable.</span><br><br>Contact the sender for a new package.</div></div>";
}

// Lock the button until payload is loaded
document.getElementById("unlock-btn").disabled = true;
loadPayload();
</script>
</body>
</html>';
}
?>
