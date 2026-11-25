# Sports Event Coordinator – Backend API

Laravel REST API for the **Offline-First Mobile Sports Event Coordination System featuring AI-Optimised Dashboards via SDUI** (Kingston University BSc Software Engineering final project).

It serves the React Native app in [`sports-cordination-frontend`](https://github.com/muwafaqDAVcreator/sports-cordination-frontend).

A test deployment is live at `https://api-app.muwamurad.com/api` (Ubuntu 24.04, Nginx, PHP 8.3-FPM, MySQL 8, HTTPS via Let's Encrypt behind Cloudflare).

## Features

- Token authentication (Laravel Sanctum) with coordinator and administrator roles
- Events, athletes, results, schedule delays, standings and medal tables
- Offline sync: batched upload of queued device changes, last-write-wins conflict resolution with an audit log, idempotent retries, full snapshot download
- Analytics engine: schedule delays, unusual delays (z-score), knock-on risk, venue clashes, officials shortfalls and missing results
- Server-Driven UI: role- and version-aware dashboard layouts generated from the analytics

## Requirements

PHP 8.3, Composer, MySQL 8 (Laragon works well on Windows).

## Setup

```bash
composer install
cp .env.example .env            # set DB_DATABASE, APP_TIMEZONE
php artisan key:generate
php artisan migrate --seed      # schema, demo users and a live demo scenario
php artisan serve --host=127.0.0.1 --port=8000
```

Refresh the demo scenario at any time with `php artisan db:seed --class=DemoScenarioSeeder`.

## Tests

```bash
php artisan test
```

## Main endpoints (`/api`)

| Group | Endpoints |
|---|---|
| Auth | `POST auth/register`, `POST auth/login`, `GET auth/user`, `POST auth/logout`, `POST auth/refresh-token` |
| Events | `GET/POST events`, `GET/PUT/DELETE events/{id}`, `GET/POST events/{id}/delays` |
| Athletes | `GET/POST events/{id}/athletes`, `PUT/DELETE athletes/{id}` |
| Results | `GET/POST events/{id}/results`, `PUT/DELETE results/{id}`, `GET events/{id}/standings`, `GET standings/overall` |
| Dashboard | `GET dashboard`, `GET dashboards/{role}?schema_version=N`, `GET analytics` |
| Sync | `POST sync/upload`, `GET sync/download`, `GET sync/status`, `GET sync/conflicts` |
| Profile | `GET/PUT user/profile`, `POST user/change-password` |

## Author

M.A.M Muwafaq (E297031) – supervised by Ms. Nethmini Abeysekare.
