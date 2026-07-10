# Docker

Container definitions and service configuration for local development and CI.

## Services (`docker-compose.yml`)

| Service      | Image / Build          | Port(s)        | Purpose                                  |
|--------------|------------------------|----------------|------------------------------------------|
| `backend`    | `docker/php/Dockerfile`| 9000 (fpm)     | Laravel API (PHP-FPM)                    |
| `horizon`    | `docker/php/Dockerfile`| —              | Queue workers via Laravel Horizon       |
| `nginx`      | `nginx:1.27-alpine`    | 8000 → 80      | Web server / reverse proxy to PHP-FPM   |
| `frontend`   | `docker/node/Dockerfile`| 5173          | React 19 dev server (Vite)              |
| `mysql`      | `mysql:8.0`            | 3306           | Central + tenant databases              |
| `redis`      | `redis:7-alpine`       | 6379           | Cache, queue, session, Horizon          |
| `phpmyadmin` | `phpmyadmin:5`         | 8080 → 80      | DB admin UI                             |

## Usage

```bash
docker compose up -d            # start everything
docker compose ps               # status
docker compose logs -f backend  # tail logs
docker compose exec backend php artisan migrate --seed
docker compose down             # stop (add -v to wipe volumes)
```

## Notes

- **Database-per-tenant:** the app user needs `CREATE`/`DROP` privileges to provision tenant databases. In production, scope these to a database-name prefix rather than granting globally.
- `mysql_data` and `redis_data` are named volumes; remove with `docker compose down -v`.
- Override env via a root `.env` consumed by Compose (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
