# DMZ Symfony Relay

Secure DMZ API using Symfony attribute routing + Doctrine with 3 core tables:

1. `synstitute_instance`
2. `appointment_type`
3. `available_slot`

## What it does

- Synstitute sends available slots every 5 minutes:
  - `POST /api/synstitute/slots/sync`
- Public booking payloads are forwarded to Synstitute instance target URL:
  - `POST /api/bookings`
- Synstitute pulls booked appointments every 5 minutes:
  - `GET /api/synstitute/bookings`
- Operators can view booked appointments in the browser (HTTP Basic: instance identifier / API key):
  - `GET /view/bookings/{id}`

See [docs/slot-sync.md](docs/slot-sync.md) for the full sync and booking process.

## Security model

- Stateless API authentication with per-instance credentials.
- Required headers on every `/api/*` request:
  - `X-Instance-Id`
  - `X-Api-Key`
  - `X-Request-Timestamp` (unix timestamp, max drift 5 minutes)
  - `X-Request-Id` (replay-protection key, one-time use for 10 minutes)
- API key is stored hashed in database.
- HTTPS required in non-dev environments.
- Forwarding target must be HTTPS and must resolve to public IP ranges only (SSRF hardening).
- Security response headers are set globally.

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
