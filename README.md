# SolutionsHub

SolutionsHub is CodeBlue Technology's internal solutions-consulting quoting
tool. An account manager walks a customer conversation through IT Services,
Data Center Services, Voice over IP, Data Cabling, and Premise Security,
picking services/products/scope-of-work items as they go, and ends up with a
running "Solution" — a bill of materials with pricing, outcome messaging, and
(for PeopleFirst Managed IT deals) a printable/emailable Check Out proposal.

This repository is a **static-hosting port** of the original app, which was
built and lived entirely inside a Claude "design canvas" artifact (a
Claude.ai-only editing/preview sandbox). It reproduces the same catalog data,
pricing formulas, and UI flows using plain HTML/CSS/JS plus a small PHP
mailer, so it can run on ordinary shared hosting (this repo was built for
Bluehost + cPanel Git Version Control — see **Deploying** below).

## How it's built

- **`app.js`** — the app's data and business logic: `PILLARS` (the full
  service catalog), `SCOPE_LIBRARY`, `AUTO_SOFTWARE`, `OPTIONAL_ADDONS`,
  `FIREWALL_MODELS`, the Zultys phone catalog, embedded product/logo images,
  and every pricing method (`computeManagedIT()`,
  `computeScopeLinesForSelection()`, `addPartsCategoryToSolution()`, etc.) —
  ported essentially verbatim from the original artifact's `Component` class.
  Catalog data and pricing math were **not** modified during the port.
- **`runtime.js`** — a small hand-rolled template runtime that replaces the
  canvas's internal DOM patcher. It compiles the `{{path}}` / `sc-if` /
  `sc-for` template syntax once and then patches the live DOM in place on
  every state change, instead of ever reassigning `innerHTML` or diffing a
  virtual DOM — see the comment at the top of the file for why (short
  version: naive full re-renders steal focus/cursor position out of whatever
  text field the user happens to be typing in, which given this app's
  interaction pattern — one keystroke, one full re-render — would happen
  constantly).
- **`index.html`** — the page shell, plus the ported template markup
  (embedded inert inside a `<script type="text/x-template">` tag so the
  browser never parses or half-executes it before `runtime.js` compiles it).
- **`styles.css`** — the original artifact's CSS plus a small set of
  responsive additions (the source was a fixed 1180×820 preview canvas; see
  the comment at the top of the file for exactly what changed).
- **`mail/`** — a dependency-free PHP SMTP mailer backing the "Email Quote to
  Customer" button (`send-quote.php` + `smtp.php`), and `config.sample.php`
  for the site owner to copy and fill in on the server.

## Known limitations

- **AI invoice reading is inert (by design, fails soft).** The original
  artifact's Managed IT "Compare against their current invoice" flow could
  upload a PDF and have Claude read it via `window.claude.use('sample')` — a
  capability that only exists inside a claude.ai artifact sandbox. That
  method (`miAnalyzeInvoiceFile` in `app.js`) is ported unchanged, and it
  already defensively checked for `window.claude` before calling it. Outside
  the sandbox that global simply doesn't exist, so the app now always takes
  the pre-existing "AI invoice reading isn't available in this view — enter
  the total manually above" branch. The rest of that screen (manually
  entering the customer's current monthly spend, and the old-vs-new
  comparison built from it on the Check Out proposal) works exactly as
  before. No PDF.js is loaded, since the only code path that would load it is
  unreachable without `window.claude`.
- **"Email Quote to Customer" is new.** The original artifact had no server,
  so its only way to "send" a quote was "Copy Quote for Email" (copies the
  quote text to the clipboard so the rep can paste it into their own email
  client). This port adds a real send path — a POST to `mail/send-quote.php`
  — alongside the original copy/print buttons, so the tool can actually
  email a customer once `mail/config.php` is filled in. If mail isn't
  configured yet, the button fails with a clear inline message rather than
  silently doing nothing.
- **Pre-existing catalog data issue (inherited from the source, not
  introduced by this port):** the category id `servers` is reused by two
  different categories — `IT Services → Cyber Security → Servers`
  ("server-grade threat protection and hardening") and
  `IT Services → Provided Equipment → Servers` ("rack and tower servers,"
  parts-priced). Category ids are used as map keys for several pieces of
  per-category state (`SCOPE_LIBRARY[cat.id]`, `state.partsSelections`,
  `state.categoryProducts`, `state.scopeSelections`, notes, etc.), so in
  both this port and the original artifact, configuring one of these two
  categories and then visiting the other can show/share state that should
  be independent. This was verified against the original
  `Main.dc.html` source (not introduced while porting) and was intentionally
  **not** "fixed" by renaming an id, since the task here was faithful
  porting of the existing catalog data, not redesigning it. Flagging so
  whoever owns the catalog can decide how to resolve it (the cleanest fix is
  giving one of the two a distinct id, e.g. `servers-security`).
- **Google Fonts require outbound internet access.** `index.html` and
  `styles.css` reference Space Grotesk / IBM Plex Sans from
  `fonts.googleapis.com`. Every element that uses them also lists a
  `sans-serif` fallback, so the app is fully usable without that request
  succeeding — it just renders in the browser's default sans-serif instead
  of the branded typefaces. (This is what you'll see if you test in a
  network-restricted sandbox; on normal hosting it loads fine.)

## Known catalog fact-check (not a limitation — recorded for confidence)

While porting, `PILLARS` was walked programmatically to count category ids:
**49 category entries, 48 of them unique** (the one collision is the
`servers` id issue above — everything else is unique). Pricing was spot
checked against the ported `computeManagedIT()` — e.g. PeopleFirst approach,
4 staff members, default $180/hr rate, no devices/add-ons/firewall — and
matches by hand: `$19.00/mo` support pool ÷ 4 members = `$4.75/member/mo`,
onboarding `$45.00` (M365) + `$43.31` (member setup) = `$88.31` one-time.

## Deploying (Bluehost + cPanel Git Version Control)

This app is 100% static HTML/CSS/JS plus PHP — there is no build step, no
`npm install`, and no framework to compile. Deploy steps:

1. **Push this repository to GitHub** (or wherever cPanel's Git integration
   can reach) if it isn't already hosted there.
2. In cPanel, open **Git Version Control** and click **Create**.
3. **Clone URL**: the GitHub repository's clone URL (HTTPS, e.g.
   `https://github.com/your-org/solutionshub.git`).
4. **Repository Path**: the existing document root for
   `portal.codebluetechnology.com`. Find the exact path on the **Domains**
   page in cPanel (it'll look something like
   `/home/<cpanel-user>/public_html` or
   `/home/<cpanel-user>/portal.codebluetechnology.com`, depending on how the
   subdomain/addon domain was set up) — point the repository at that same
   path so the site serves the checked-out files directly.
5. Finish creating the repository. cPanel clones it into place.
6. **Copy the mail config into place directly on the server** (never via
   git — `mail/config.php` is listed in `.gitignore` on purpose):
   - Via cPanel's File Manager (or SSH/SFTP if enabled), go to the `mail/`
     folder inside the deployed site.
   - Copy `config.sample.php` to a new file named `config.php` in the same
     folder.
   - Edit `config.php` and fill in the real SMTP host/port/username/password
     and `from_email`/`from_name`, plus `allowed_origins` (the real site
     URL, e.g. `https://portal.codebluetechnology.com`).
7. **Every future push to the connected branch**: back in cPanel's Git
   Version Control page, open this repository and click
   **Pull or Deploy → Update from Remote**. There is nothing to build or
   restart — the next page load serves the new files immediately.

That's the whole deploy loop: push to GitHub, then "Update from Remote" in
cPanel. `mail/config.php` stays on the server across every update since it's
never part of the git history.

## Local development / testing

No build tooling is required. From the project root:

```
python3 -m http.server 8000
```

then open `http://localhost:8000/index.html`. The "Email Quote to Customer"
button needs a PHP server to actually send mail — e.g. `php -S
localhost:8000` from the project root instead (PHP's built-in server serves
static files too), with a filled-in `mail/config.php` alongside it.
