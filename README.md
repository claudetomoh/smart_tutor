# SmartTutor Connect

Modern tutoring platform prototype that combines a hand-crafted marketing site, a Vite-powered React SPA, and a hardened PHP/MySQL API with live security instrumentation, messaging, and admin tooling.

## Why This Repo Matters
- Landing experience with modular HTML templates, accessible components, and brand-ready styling.
- React single-page app prototype for booking flows, dashboards, and tutor discovery.
- PHP 8.1 API surface that persists bookings, enforces password policy, tracks auth attempts, and emits JWT + refresh pairs.
- Security-first utilities (2FA, risk scoring, audits, WebSocket bridge) plus an admin console to triage incidents, bookings, and notifications.

## Architecture at a Glance
- **Marketing site**: `templates/` + `scripts/build.js` generate `index.html`; CSS lives in `src/css` and shared assets in `public/images`.
- **React SPA**: `src/react` bootstrapped with Vite; reuses the design tokens and mock data under `src/react/data`.
- **PHP backend**: `api/` exposes auth, tutor, booking, messaging, and analytics endpoints backed by the schemas in `api/db/*.sql` and `stc2025_schema.sql`.
- **Realtime + security tooling**: `api/lib/*`, `lib/RealtimeNotifier.php`, and `scripts/securityWebSocket.js` push alerts to admins and mirror chat notifications.

## Tech Stack
| Layer | Tools |
| --- | --- |
| Frontend build | Vite 5, React 18, vanilla JS modules, custom CSS |
| Templates | Node 18 build/watch scripts composing partials + sections |
| Backend | PHP 8.1+, PDO/MySQL, JWT signing, custom security services |
| Database | MySQL 8 (UTF8MB4, strict schema with audit + messaging tables) |
| Realtime | Node-based WebSocket bridge + long-poll fallbacks |

## Repository Layout
```
SmartTutor/
├── api/                # PHP endpoints, configs, DB schemas, bootstrap scripts
│   ├── config/         # app.php, database.php read env vars
│   ├── db/             # schema + security analytics DDL
│   ├── lib/            # Database, Security, IncidentResponse, realtime helpers
│   ├── tutor/, admin/  # Role-specific route handlers
│   ├── scripts/        # init_db.php seeding + utilities
│   └── *.php           # auth, bookings, tutors, reports, metrics, etc.
├── lib/                # Frontend PHP helpers (RealtimeNotifier, etc.)
├── pages/              # Legacy PHP views (admin dashboard, profiles, auth)
├── public/             # Static assets served by PHP + Vite
├── scripts/            # Node build/watch/security websocket utilities
├── src/
│   ├── css/            # Global styles, dashboards, security theming
│   ├── js/             # Vanilla landing page interactions
│   └── react/          # SPA entry, routes, components, mock data
├── templates/          # Landing page partials + sections consumed by build.js
├── tools/              # Debug helpers (DB inspectors, hash generator)
├── package.json
├── vite.config.js
└── README.md
```

## Prerequisites
- Node.js 18+ and npm
- PHP 8.1+ with PDO MySQL extension
- MySQL 8 (default config assumes port `3307`)
- Optional: Composer (for adding PHP packages) and a modern browser

## Quick Start
### 1. Install JavaScript dependencies
```bash
npm install
```

### 2. Build or watch the marketing site
- One-off build: `npm run build:html`
- Auto rebuild on template changes: `npm run watch:html`
- Open `index.html` or serve the repo root to preview the generated page.

### 3. Run the React SPA
```bash
npm run dev
```
Visit the Vite dev URL (usually `http://localhost:5173`) to explore the SPA prototype. Components pull mock tutors from `src/react/data/tutors.js` and reuse styles from `src/css`.

### 4. Bring up the PHP API + database
1. Create a MySQL database (default name `smarttutor`).
2. Import schemas: `mysql -u root -p smarttutor < api/db/schema.sql` (or run `php api/scripts/init_db.php`).
3. Configure environment variables (see table below) or edit the arrays in `api/config/database.php` and `api/config/app.php`.
4. Start a PHP dev server from the project root:
	```bash
	php -S 127.0.0.1:8000 -t .
	```
5. Hit endpoints such as `POST http://127.0.0.1:8000/api/auth.php?action=login` or `POST /api/bookings.php` with JSON payloads.

### 5. (Optional) Realtime/WebSocket bridge
```bash
set JWT_SECRET=your-secret
set REALTIME_BRIDGE_SECRET=bridge-secret
node scripts/securityWebSocket.js
```
The bridge listens for HTTP callbacks from `RealtimeNotifier` and relays security alerts or message previews to authenticated WebSocket clients (admins and subscribed users).

## Environment Variables
| Variable | Purpose | Default |
| --- | --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connectivity for the PHP API | `localhost`, `3307`, `smarttutor`, `root`, empty |
| `JWT_SECRET` | Signs access tokens; shared with WebSocket server | `change-me-in-env` |
| `REALTIME_BRIDGE_URL` | HTTP endpoint the API uses to fan out notifications | `http://localhost:8090/notify/messages` |
| `REALTIME_BRIDGE_SECRET` | Shared secret between API and bridge | `change-bridge-secret` |
| `WS_URL`, `WS_PORT` | Client/WebSocket server URL + port | `ws://localhost:8080`, `8080` |

## npm Scripts
| Script | Description |
| --- | --- |
| `npm run dev` | Start Vite dev server for the SPA prototype |
| `npm run build` | Production build for the React app |
| `npm run preview` | Preview the Vite build locally |
| `npm run build:html` | Generate `index.html` from templates/partials |
| `npm run watch:html` | Rebuild landing page when templates change |

## API Surface (high level)
- `api/auth.php` – registration, login, refresh tokens, logout, 2FA enable/verify, rate-limited login attempts, lockouts.
- `api/bookings.php` – booking submission with tutor validation, deduping, and admin notifications.
- `api/tutors.php` – directory search with filters (`q`, `subject`, `mode`, pagination).
- `api/metrics.php`, `api/reports.php`, `api/security.php` – aggregates for dashboards, compliance checklists, risk scoring, and security exports.
- `api/messages.php`, `api/notifications.php` – threaded messaging, admin broadcasts, and notification history endpoints.

## Admin & Security Instrumentation
- Role-gated dashboards (`pages/admin.php`, `pages/notification_history.php`) render live KPIs across bookings, sessions, and security tables using the shared CSS system.
- `api/lib/Security.php` centralizes password policy, lockouts, checklists, and risk scoring across incidents, compliance, and vulnerabilities.
- `api/lib/SecurityLogger.php`, `SecurityAudit.php`, and `SecurityAnalytics.php` record structured events for later review.
- `RealtimeNotifier` + `scripts/securityWebSocket.js` allow incident alerts or message previews to fan out to admins in real time with JWT-authenticated sockets.
- `tools/debug_*` scripts help inspect database state safely while debugging locally.

## Development Notes & Next Steps
- Replace SVG placeholders under `public/images/` with production-ready photography while keeping filenames identical for instant swaps.
- Hook the React SPA to live API endpoints, implement role-aware routing, and move mock data into the database.
- Add automated tests (Jest + React Testing Library, PHPUnit/Pest) and CI linting before shipping to production.
- Consider wiring Stripe/Paystack for payments and scheduling, plus queuing (Redis/SQS) for high-volume notifications.

## License
Provided as-is for educational and demonstration purposes.
