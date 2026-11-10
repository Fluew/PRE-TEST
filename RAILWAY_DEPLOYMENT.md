# Deploying MR Pride to Railway

Railway builds this repository with its own builder (Railpack). No Docker is needed. The repo
already contains the Railway settings:

| File | Service | What it does |
|---|---|---|
| `railway.json` | **web** (required) | Serves the app. Runs `php artisan migrate --force` before each deploy and checks `/up` before switching traffic. |
| `railway.worker.json` | **worker** (recommended) | Runs the queue (`php artisan queue:work`). Team invitation emails are queued and are only sent while it runs. |
| `railway.scheduler.json` | **scheduler** (optional) | Runs `php artisan schedule:work`. Today it only deletes expired team invitations once a day. |

## 1. Create the project

1. Push this branch (`main`) to GitHub.
2. In Railway: **New Project → Deploy from GitHub repo**, and pick the repository. This becomes the **web** service.
3. In the project, **+ New → Database → MySQL**. Railway names it `MySQL`.

## 2. Set the web service's variables

Open the web service → **Variables** → **Raw Editor**, and paste the block below. Then:

- Replace `APP_KEY` with your own key. Generate one locally with `php artisan key:generate --show`.
- Set `APP_URL` after step 3 gives you the domain.

```env
APP_NAME="MR Pride"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:REPLACE_ME
APP_URL=https://your-app.up.railway.app

DB_CONNECTION=mysql
DB_URL=${{MySQL.MYSQL_URL}}

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

LOG_CHANNEL=stderr
LOG_LEVEL=info

INERTIA_SSR_ENABLED=false

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@your-domain.com"
MAIL_FROM_NAME="MR Pride"
```

`${{MySQL.MYSQL_URL}}` is a Railway reference: Railway fills in the database address and
password itself. Don't paste the real values.

## 3. Give the web service a public address

Web service → **Settings → Networking → Generate Domain**. Copy the domain into `APP_URL`
(starting with `https://`). Railway redeploys the service.

The first deploy creates all the tables. The `/up` health check must pass before Railway sends
traffic to the new version.

## 4. Add the worker (and scheduler)

For each one: **+ New → GitHub Repo**, pick the same repository, then:

- **Settings → Config-as-code → Railway config file**: `/railway.worker.json` (or `/railway.scheduler.json` for the scheduler).
- **Variables**: the same variables as the web service. Use **Shared Variables** at project level so you set them once.
- No public domain is needed.

If you don't want a worker, set `QUEUE_CONNECTION=sync` on the web service instead. Invitation
emails are then sent while the person waits.

## 5. Create the first account

Registration is open on this version. Open `https://<your-domain>/register` and create the
administrator account.

Don't run `php artisan db:seed` in production. It creates a demo admin
(`admin@example.com` / `admin123`) and sample quotations.

## Email

With `MAIL_MAILER=log`, emails (password reset, team invitations) are only written to the logs.
To really send them, configure a mail provider: set `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_SCHEME` for SMTP. Railway blocks outgoing SMTP on
some plans; if SMTP times out, use a provider with an HTTP API instead.

## Good to know

- **Files are not kept between deploys.** Railway's disk resets on every deploy. This version
  doesn't store uploads; if file uploads are added later, attach a Railway Volume at
  `/app/storage/app` or use S3-compatible storage.
- **Logs** appear in the service's **Deployments → Logs** (`LOG_CHANNEL=stderr`).
- **HTTPS**: Railway handles TLS. The app trusts Railway's proxy headers (`bootstrap/app.php`), so
  links, redirects and cookies use https.
- **Run an artisan command** in a service's shell (**⋯ → Shell** in the dashboard), or with the
  Railway CLI: `railway ssh` then `php artisan <command>`.
