# DMZ Symfony Relay

Secure DMZ API using Symfony attribute routing + Doctrine with 3 core tables:

1. `synstitute_instance`
2. `appointment_type`
3. `available_slot`

## What it does

- Synstitute sends available slots every 5 minutes:
  - `POST /api/synstitute/slots/sync`
- The customer website reads future available slots:
  - `GET /api/public/synstitutes/{instance_identifier}/slots`
- Customer website reservations are forwarded to the Synstitute:
  - `POST /api/public/synstitutes/{instance_identifier}/bookings`
- Authenticated booking payloads can also be forwarded to Synstitute:
  - `POST /api/bookings`
- Synstitute pulls booked appointments every 5 minutes:
  - `GET /api/synstitute/bookings`
- Operators can view booked appointments in the browser (HTTP Basic: instance identifier / API key):
  - `GET /view/bookings/{id}`
- A separate administrator can manage instances without accessing bookings:
  - `GET /admin`

See [docs/slot-sync.md](docs/slot-sync.md) for the full sync and booking process.

## Security model

- Stateless API authentication with per-instance credentials on Synstitute routes.
- Required headers on authenticated `/api/*` requests:
  - `X-Instance-Id`
  - `X-Api-Key`
  - `X-Request-Timestamp` (unix timestamp, max drift 5 minutes)
  - `X-Request-Id` (replay-protection key, one-time use for 10 minutes)
- API key is stored hashed in database.
- HTTPS required in non-dev environments.
- Public website routes do not use or expose Synstitute API keys; they still require HTTPS.
- Forwarding target must be HTTPS and must resolve to public IP ranges only (SSRF hardening).
- Security response headers are set globally.

## Instance administration

The small `/admin` interface lists instances and lets an administrator create an instance,
edit its booking target URL, activate/deactivate it, or rotate its API key.
It does not display appointments or customer data. The identifier stays immutable.
There is no delete action, so existing instance data is preserved.

Administrator access is separate from Synstitute credentials. Configure it before logging in:

```bash
php bin/console app:admin:password-hash
```

The command prompts for a password of at least 16 characters without putting it in shell
history, then prints a hash. Put that hash in your server environment or untracked
`.env.local`, using single quotes to preserve the `$` characters:

```dotenv
ADMIN_USERNAME=your-admin-name
ADMIN_PASSWORD_HASH='<generated-password-hash>'
```

No default administrator password is provided: login is unavailable until a hash is configured.
Open `https://<dmz-host>/admin`. HTTPS is mandatory for the admin, including development.
Behind a reverse proxy, ensure `TRUSTED_PROXIES` only includes your trusted proxy addresses.

- Login, logout and every mutation require CSRF tokens.
- Login is limited to 5 failed attempts per username/IP over 15 minutes.
- Secure, HTTP-only, SameSite=Strict session cookies are used over HTTPS.
- Admin responses cannot be cached or embedded in a frame.
- API keys are displayed only in the response to successful creation or rotation. Save
  the key before leaving that page; rotation immediately invalidates the old key.
- Mutations are logged through the application logger without passwords, API keys or customer data.
- The admin session does not grant access to `/api/synstitute/bookings` or `/view/bookings/{id}`.
  As an infrastructure operator you still control the server/database; this is application-level isolation.
- Booking targets must resolve to public addresses and use HTTPS on port 443, without
  embedded credentials or URL fragments. Forwarding does not follow redirects.
  The CLI instance-creation command uses the same validation.

For an internet-facing installation, additionally restrict `/admin` to a VPN or an IP allowlist
at the reverse proxy where practical. For multiple application servers, use a shared cache
for login throttling and shared session storage.

## Quick start

1. Install dependencies:

```bash
composer install
```

2. Initialize database schema:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

3. Create a Synstitute instance credential:

```bash
php bin/console app:instance:create <instance_identifier> <https_booking_target_url>
```

4. Run local server:

```bash
php -S 0.0.0.0:8080 -t public
```

## Database

Default is sqlite for minimal operational footprint:

- DSN in `.env`:
  - `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"`

## Request examples

Example headers:

```text
X-Instance-Id: synstitute-eu-01
X-Api-Key: <api_key>
X-Request-Timestamp: 1785755100
X-Request-Id: 2f7f3b0b-748b-4252-8479-0fe2552ed8d2
Content-Type: application/json
```

Sync slots payload:

```json
{
  "slots": [
    {
      "uniqid": "slot-abc-123",
      "date": "2026-08-03",
      "startAt": "09:00",
      "endAt": "09:30",
      "appointmentType": {
        "string": "Initial consultation",
        "duration": 30,
        "description": "First patient visit"
      }
    }
  ]
}
```

Booking payload should include `uniqid` or `slotUid` so the slot can be marked as booked.
