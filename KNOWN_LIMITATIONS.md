# EQ Finance — Known limitations

Read this before relying on the API.

## Data source

1. **Unofficial source.** Google Finance has no public API. EQ Finance parses the HTML of public quote pages. Google can change the markup at any time without notice. When that happens the parser returns `EXTRACTION_FAILED` (HTTP 502) rather than guessing, and `app/Services/StockParser.php` must be updated. The *Upstream probe* on the System Status page shows what is currently extracted.
2. **Blocking and throttling.** Google may show consent, verification or "unusual traffic" pages to automated clients, especially from shared-hosting IP ranges that many customers share. EQ Finance detects these and returns `SOURCE_BLOCKED`. It does **not** bypass CAPTCHAs, rotate proxies, spoof fingerprints or otherwise evade protections. Mitigations: a longer cache TTL, conservative per-key limits, and the optional stale-serving fallback.
3. **Delayed data.** Prices on the source are delayed according to exchange rules and may lag the live market by many minutes. `meta.retrieved_at` is when EQ Finance read the page; `data.source_timestamp` (when available) is the last-trade time reported by the source. This API is not suitable for trading or any time-critical use.
4. **Field coverage varies.** Volume, EPS, sector and industry are often not present on the quote page. Indices and currency pairs expose only a handful of fields. Missing fields are `null`, and `data.availability` lists what was actually found.
5. **Number parsing assumes an English locale.** The `Accept-Language` setting defaults to `en-US`. Do not change it to a locale that formats numbers differently (e.g. `1.234,56`).
6. **No symbol search.** `/api/v1/search` returns `501 UNSUPPORTED_OPERATION` because the source offers no permitted public search endpoint. Clients must know the exchange and ticker.
7. **Live scraping was not verified from the build environment.** The sandbox used to build and test this project could not reach `www.google.com`, so the fetch layer was exercised with a transport stub and the parser with an HTML fixture modelled on Google Finance's current quote-page markup (`data-last-price`, `.YMlKec.fxKbKc`, `.gyFHrc` label/value rows, `.zzDege`, `.bLLb2d`). Run the upstream probe after installation and, if it reports `EXTRACTION_FAILED`, adjust the XPath strategies in `StockParser.php` to the current markup.

## Platform

8. **Sequential batches.** Shared hosting has no background workers, so `/stocks` fetches symbols one after another. With a 30-second `max_execution_time` and a 12-second upstream timeout, a batch may be cut short; remaining symbols are reported with `SERVICE_UNAVAILABLE` and should be retried.
9. **Fixed-window rate limiting.** Limits reset at minute/day/month boundaries (UTC), so a client can make up to 2× the per-minute limit across a boundary. This is intentional for simplicity and MySQL efficiency.
10. **Stampede protection is best-effort.** The DB lock prevents most duplicate fetches for the same symbol; a request that waits more than 3 seconds proceeds to fetch on its own.
11. **Cache and logs live in MySQL.** Very high request volumes (thousands per minute) would need a different storage design; that is outside the scope of a shared-hosting deployment. Run the cleanup cron to keep tables small.
12. **`.htaccess` dependence.** Protection of private folders and clean URLs rely on Apache/LiteSpeed honouring `.htaccess`. On hosts that do not, move the project outside the document root or configure equivalent rules on the server.
13. **Authorization header.** Some FastCGI setups strip `Authorization`. The bundled `.htaccess` forwards it; if that is disabled by the host, use the `X-API-Key` header.

## Security scope

14. **No password-reset email.** Recovery is done via phpMyAdmin (see README). This avoids shipping a weak reset flow that depends on shared-hosting mail.
15. **Single admin role.** All administrators have identical permissions; there is no audit trail of admin actions beyond the application log.
16. **IP-based login lockout** can be triggered deliberately against a shared office IP (denial of service of the login page for 15 minutes). The API itself is unaffected.
