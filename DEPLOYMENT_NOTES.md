# Deployment Notes — Read this before deploying anywhere (Railway, Render, or elsewhere)

This file exists so nothing gets lost when moving hosts. Everything the app
needs is listed here — if you're setting this up on a new platform, this is
the checklist.

## 1. Environment variables required

All of these are documented with comments in `.env.example` — copy that file's
structure, but never copy `.env.example`'s actual values (they're placeholders).
Set real values in whichever host's dashboard you're using (Railway → Variables
tab; Render → Environment tab; same idea, different UI).

| Variable | Required for | Notes |
|---|---|---|
| `APP_KEY` | Everything | Laravel generates this — run `php artisan key:generate` if missing |
| `RESEND_API_KEY`, `RESEND_FROM_ADDRESS` | OTP emails, password reset emails | Free tier at resend.com, no domain verification needed with the default `onboarding@resend.dev` sender |
| `TERMII_API_KEY`, `TERMII_SENDER_ID` | SMS OTP option only | Optional — Email verification works without this. Free trial only sends to your own verified number until upgraded. |
| `PAYSTACK_SECRET_KEY` | Bank account verification (registration + Personal Data edit) | Free test-mode key works — no real payments are ever made with it, it's only used for account-number lookups |

**Without any one of these set**, the feature it powers fails gracefully with a
clear error message (not a crash) — e.g. registration still works even with
no Paystack key, it just can't verify the account number field.

## 2. Why email uses Resend's API instead of SMTP — read this before Render

This app used to send OTP/password-reset emails via raw SMTP (Gmail,
`MAIL_MAILER=smtp`). That was the actual root cause of registration
silently failing for a long time: **Railway blocks outbound SMTP (ports
587/465) entirely on Free, Trial, and Hobby plans** — confirmed directly
from Railway's own support responses. The connection would just hang until
PHP's execution time limit killed the request with a fatal error, which is
why the app only ever showed a dead "connection timeout," never a real
error message, even though the member record had already been created
server-side.

The fix: `NotificationService::sendEmail()` now calls **Resend's plain
HTTPS API** instead of SMTP. This matters for your Render move too — most
free-tier PaaS hosts block raw SMTP for the same anti-abuse reasons, so
this isn't just a Railway quirk. Using an HTTPS-based email API (Resend,
or similar — Mailgun/Postmark/SendGrid all work the same way) is the
actually-portable choice here, not something to revert back to SMTP later.

If you ever do end up on a host/plan that *does* allow outbound SMTP, the
old `MAIL_*` config is still sitting in `.env.example` for reference, but
nothing in the code reads it anymore — you'd need to revert
`sendEmail()` back to using Laravel's `Mail` facade to use it again.

## 3. The scheduled job (monthly loan deductions)

`app/Console/Commands/ProcessLoanDeductions.php` needs to actually run once a
day for automatic loan repayments to happen. Defining it in
`routes/console.php` is not enough by itself — something on the server has to
call `php artisan schedule:run` every minute (which then checks if anything's
due), or you run `php artisan schedule:work` as a standing process.

- **On Railway**: use Railway's Cron Job feature (if on your plan) pointed at
  `php artisan schedule:run`, or run a second service with the start command
  `php artisan schedule:work`.
- **On Render**: Render has a native "Cron Job" service type — create one
  pointed at this same repo, with the command `php artisan schedule:run`, on
  whatever schedule you want it checked (every 5–15 minutes is fine; the
  command itself only acts on loans actually due that day).
- **For a quick demo without setting any of this up**: you can just run
  `php artisan loans:process-deductions` manually in the host's console
  whenever you want to show the feature working.

## 3a. Render specifically needs a Dockerfile

Railway auto-detects and builds PHP/Laravel apps with no extra files needed.
Render's supported path for PHP goes through Docker instead — `Dockerfile`
and `scripts/00-laravel-deploy.sh` in this repo exist only for that; Railway
ignores them completely, so having both files doesn't affect Railway at all.

The deploy script runs `migrate --force` automatically on every Render
deploy — unlike Railway, where that had to be run by hand in the Console
tab after each push.

## 3b. Database on Render — kept on Railway, not migrated

Render has no free managed MySQL (only Postgres is managed there; MySQL on
Render would need a paid persistent disk, same ephemeral-filesystem problem
as SQLite). The simplest, zero-risk option: keep the existing Railway MySQL
database exactly where it is, and point the Render-hosted app at it using
Railway's **public** MySQL connection details (not the internal
`mysql.railway.internal` host, which only works for other services inside
Railway's own network) — found on the MySQL service's own Variables tab in
Railway, not the backend service's.

## 4. Things that must be updated by hand when switching hosts

These aren't automatic — you have to physically go change them:

1. **The Flutter app's `API_BASE`** (`lib/utils/constants.dart`) — currently
   points at the Railway URL. Change it to the new host's URL, then rebuild
   the APK (`flutter build apk --release`) and redistribute it. The app will
   silently keep talking to the old backend otherwise.
2. **Re-enter every environment variable from the table above** in the new
   host's dashboard — moving hosts does not carry these over.
3. **Database**: this app uses SQLite (a file, not a separate server) — check
   whether the new host's free tier persists the filesystem across deploys/
   restarts. If not, every redeploy wipes your data (members, transactions,
   everything). Render's free tier, for example, resets the filesystem on
   redeploy unless you add a paid persistent disk.

## 5. Nothing in the PHP code itself is Railway-specific

Checked: no hardcoded Railway URLs, no Railway-only config, no CORS rules
tied to a specific host. The backend code is portable as-is — only the
*environment variables* (per host dashboard) and the *Flutter `API_BASE`
constant* (per app rebuild) need to change when switching hosts.
