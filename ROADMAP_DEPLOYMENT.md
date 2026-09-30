# Public Roadmap & Feedback — backend deployment guide

Branch: `feature/roadmap` (repo `RnD-Experts-Team/TasksSystem`, app in `Tasks_Back/`).

Adds a public product roadmap (boards, feature requests, anonymous voting, comments, roadmap columns, changelog + RSS), a staff admin API (moderation, statuses, tags, merge, branding, analytics) for an internal audience (no search-engine or link-preview support, by design). Everything is additive: 14 new tables and new routes under `/api/public/roadmap` and `/api/roadmap/admin`. No existing table, model or route is changed.

Deploy the **backend first**, then the frontend (see the `task-system` repo's `ROADMAP_DEPLOYMENT.md`). No nginx change is needed.

> **Do not skip the checklist in §8.** The two things missed on the Work Sessions deploy (the seeder and build-time variables) have direct equivalents here.

---

## 1. What changed

Existing files touched (2): `Tasks_Back/routes/api.php` (one `require` line) and `Tasks_Back/public/openapi.json` (additive paths/schemas). Optional: `.env.example` (documents the new variables) and `composer.json` (makes `league/commonmark` an explicit dependency — it was already installed transitively).

New: `config/roadmap.php`, migrations `2026_10_01_0000{01..14}_create_roadmap_*`, `app/Models/Roadmap/*`, `app/Enums/Roadmap/*`, `app/Services/Roadmap/*`, `app/Support/Roadmap/*`, `app/Http/{Controllers,Requests,Resources,Middleware}/Roadmap/*`, `app/Events/Roadmap/*`, `app/Jobs/Roadmap/*`, `app/Console/Commands/Roadmap/*`, `routes/api/roadmap.php`, seeders `RoadmapPermissionSeeder` and `RoadmapDemoSeeder`, `tests/Feature/Roadmap/*`.

## 2. New database objects

Tables (all prefixed `roadmap_`): `boards`, `statuses`, `tags`, `visitors`, `posts`, `post_tag`, `votes`, `comments`, `status_changes`, `changelog_entries`, `changelog_post`, `settings`, `abuse_events`, `slug_redirects`.

Permissions (guard `sanctum`, seeded and granted to the `admin` role): `manage roadmap`, `moderate roadmap`, `manage roadmap settings`, `manage changelog`.

Access rules:
- **The `admin` role always passes** every admin route (they use `role_or_permission:admin|<permission>`), even before the seeder has run.
- **Any other role** needs the explicit permissions, so the seeder is still required for them (see §7).
- **Anonymous visitors** need nothing: the public API is unauthenticated by design.

## 3. Environment variables (host file `Tasks_Back/.env`)

```env
# REQUIRED. Secret for the visitor IP hashes and the signed form tokens.
# Generate once, keep it stable (changing it invalidates in-flight form tokens and IP-hash matching):
#   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
ROADMAP_HASH_KEY=<64 hex chars>

# Public site origin: used for the links inside the changelog RSS feed.
ROADMAP_FRONTEND_URL=https://tasks.rdexperts.tech

# Must be false in production. (The public routes hide errors even if it is true, but do not rely on that.)
APP_DEBUG=false

# Correct absolute URL of the API host: uploaded logos are served from APP_URL/storage/...
APP_URL=https://tasksbackend.rdexperts.tech

# Optional
# ROADMAP_IP_RETENTION_DAYS=90          # IP hashes older than this are wiped by `roadmap:prune`
# ROADMAP_TRUSTED_PROXIES=              # comma separated CIDRs whose X-Forwarded-For is trusted (see §5)
# ROADMAP_IP_HEADER=X-Forwarded-For
```

Notes:
- **`ROADMAP_HASH_KEY` empty in production = every token issue fails with a 500.** This is intentional (fail closed).
- **Shared cache store.** Rate limits and single-use form tokens live in the cache. Use `CACHE_STORE=database` (current) or `redis`. Do **not** use `file` or `array` when more than one container serves requests: each container would keep its own counters.
- Broadcasting/Reverb is **not** used by this module. `QUEUE_CONNECTION=database` is already enough (only the optional prune job dispatches).

## 4. Deploy steps (docker-compose, as on the current server)

```bash
# 0. From the repo root (where docker-compose.yml is)
git fetch origin && git checkout feature/roadmap && git pull      # or merge / tag per your flow

# 1. Edit Tasks_Back/.env as in §3

# 2. Rebuild + restart the PHP containers
docker compose up -d --build backend queue

# 3. Migrate (14 new tables) and seed permissions
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan db:seed --class=RoadmapPermissionSeeder --force
docker compose exec backend php artisan permission:cache-reset

# 4. Clear caches (re-run config:cache / route:cache afterwards if you use them)
docker compose exec backend php artisan config:clear
docker compose exec backend php artisan route:clear
docker compose exec backend php artisan view:clear
docker compose exec backend php artisan cache:clear

# 5. Uploaded branding images need the public disk symlink and writable storage
docker compose exec backend php artisan storage:link
docker compose exec backend sh -c "mkdir -p storage/app/public/roadmap && chown -R www-data:www-data storage bootstrap/cache"

# 6. OPTIONAL: seed a demo board so the site is not empty on first look
docker compose exec backend php artisan db:seed --class=RoadmapDemoSeeder --force
```

`route:cache` is safe: throttling uses its own middleware (not named rate limiters), and this was verified with cached routes.

Without docker (plain PHP-FPM host): run the same `php artisan` commands directly and reload PHP-FPM.

## 5. Real client IP (matters for every abuse control)

Rate limits and vote caps are keyed by a **hash of the client IP**. If the app sees the wrong IP, all visitors share one bucket and get blocked together.

Production today is nginx + PHP-FPM with no proxy hop, so `REMOTE_ADDR` is the real client IP and nothing needs configuring. Verify after deploying:

```bash
# Issue a token from two different networks (e.g. your laptop and your phone on mobile data)
curl -s -X POST https://tasksbackend.rdexperts.tech/api/public/roadmap/visitor
# then compare the stored hashes — they MUST differ:
docker compose exec backend php artisan tinker --execute="echo App\Models\Roadmap\Visitor::latest('first_seen_at')->take(3)->pluck('first_ip_hash');"
```

If both show the **same** hash, the app is seeing a proxy or docker-gateway address. Fix by putting that address range in `ROADMAP_TRUSTED_PROXIES` and making the proxy send `X-Forwarded-For`. By default the trusted list is the private ranges (`10/8`, `172.16/12`, `192.168/16`, `127/8`), so a proxy on a private address works without configuration; only a proxy on a public address must be listed.

If a CDN such as Cloudflare is added later: set `ROADMAP_IP_HEADER=CF-Connecting-IP` and list Cloudflare's ranges in `ROADMAP_TRUSTED_PROXIES`.

## 6. Verify

```bash
docker compose exec backend php artisan route:list --path=roadmap        # public + admin routes
docker compose exec backend php artisan tinker --execute="echo \Spatie\Permission\Models\Permission::where('name','like','%roadmap%')->orWhere('name','manage changelog')->count();"   # expect 4
```

Public API smoke (no token needed):

```bash
curl -s https://tasksbackend.rdexperts.tech/api/public/roadmap/config | head -c 300
curl -s https://tasksbackend.rdexperts.tech/api/public/roadmap/boards
```

Anonymous flow smoke (proves tokens, throttling and moderation): request a visitor token (`POST /visitor`), start a form (`POST /forms/post/start`), wait the returned `min_seconds`, submit a post — expect `201` with `moderation_state: "pending"` and confirm it is **absent** from `GET /boards/{slug}/posts` until approved in the admin console.

RSS smoke: `curl -s https://tasksbackend.rdexperts.tech/api/public/roadmap/changelog/feed.xml | head` returns an `<rss>` document.

Scalar docs (`/api-docs`) should list the new **Roadmap** tags after the OpenAPI merge.

Run the module tests before deploying (they use sqlite, no MySQL needed):

```bash
php artisan test --filter=Roadmap
```

## 7. Giving staff access

- The `admin` role works immediately.
- For a manager/moderator role, grant only what they need (Roles page in the frontend, or tinker):

```bash
docker compose exec backend php artisan tinker --execute="\Spatie\Permission\Models\Role::findByName('Developer','sanctum')->givePermissionTo(['moderate roadmap']);"
docker compose exec backend php artisan permission:cache-reset
```

| Permission | Allows |
|---|---|
| `moderate roadmap` | approve/reject/spam posts and comments, visitors, bans, bulk removal, analytics |
| `manage roadmap` | boards, statuses, tags, edit/delete posts, status changes, official replies, merge, roadmap board |
| `manage changelog` | changelog entries |
| `manage roadmap settings` | branding, logo uploads, limits, blocklist |

## 8. First-deploy checklist (tick every box)

- [ ] `ROADMAP_HASH_KEY` set (64 hex), `ROADMAP_FRONTEND_URL`, `APP_URL`, `APP_DEBUG=false`
- [ ] Cache store is shared across containers (`database` or `redis`)
- [ ] `migrate` ran, `RoadmapPermissionSeeder` ran, `permission:cache-reset` ran
- [ ] `storage:link` ran; a test logo upload (Settings → Branding) shows on the public site
- [ ] Two different networks produce different `first_ip_hash` values (§5)
- [ ] `GET /api/public/roadmap/config` returns JSON; anonymous test post is `pending` and invisible until approved
- [ ] At least one board exists (create it in the admin console, or run the demo seeder) and moderation defaults are what you want per board
- [ ] Frontend deployed (see the frontend guide)
- [ ] After the first week: review **Roadmap → Visitors & Abuse** for suspicious IPs/visitors and tune the limits in Settings

## 9. Operations

- **Cleanup:** `php artisan roadmap:prune` wipes IP/UA hashes older than the retention window, old abuse events and idle empty visitors. It also runs opportunistically (1-in-500 token issues dispatch it on the queue worker). There is no scheduler container; if you add a cron entry for `schedule:run`, nothing else is needed.
- **Repair counters:** `php artisan roadmap:recount` recomputes vote and comment counts; `php artisan roadmap:rerender` re-renders stored markdown after a renderer change.
- **Anonymous voting is layered, not absolute.** A determined person can still mint new visitor tokens. Controls: token issuance caps per IP, lower caps for brand-new visitors, per-IP daily vote caps, suspicion flags on the analytics page and one-click bulk removal by visitor or IP hash. If it is ever abused, set a board to `voting_mode = verified_email` once that mode is implemented (the field is reserved).
- **Shadow bans:** a banned visitor gets normal-looking success responses but nothing is stored; they are not told.
- **Privacy:** no email addresses and no raw IPs are stored. IPs exist only as monthly-rotating hashes and are wiped after the retention window.

## 10. Rollback

```bash
docker compose exec backend php artisan migrate:rollback --step=14      # DROPS all roadmap data (posts, votes, changelog)
docker compose exec backend php artisan tinker --execute="\Spatie\Permission\Models\Permission::whereIn('name',['manage roadmap','moderate roadmap','manage roadmap settings','manage changelog'])->delete();"
docker compose exec backend php artisan permission:cache-reset
git checkout <previous-branch-or-tag> && docker compose up -d --build backend queue
```

The two touched existing files (`routes/api.php`, `public/openapi.json`) revert with the checkout.
