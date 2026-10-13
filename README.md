# E-Commerce API

Laravel 12 JSON API for a small catalog, cart, promotion, and order workflow. Guests can browse and hold a cart; checkout, pay, and cancel require a logged-in customer. Admins manage catalog, promotions, fulfilment, and append-only audits.

---

## Contents

- [Requirements](#requirements)
- [Setup](#setup)
- [Environment configuration](#environment-configuration)
- [Database setup](#database-setup)
- [Running the API](#running-the-api)
- [Testing](#testing)
- [API](#api)
- [Orders and checkout](#orders-and-checkout)
- [Architecture](#architecture-decisions)
- [Concurrency](#concurrency-handling)
- [Trade-offs](#important-trade-offs)

---

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.2+ (extensions: `bcmath`, `ctype`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`) |
| Composer | 2.x |
| MySQL | 8.x (InnoDB). PHPUnit uses in-memory SQLite and does not need MySQL. |
| curl | Used by the PowerShell smoke script |

---

## Setup

From the project root:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, copy the env file with:

```powershell
Copy-Item .env.example .env
```

Create a MySQL database, set `DB_*` in `.env` (see [Environment configuration](#environment-configuration)), then:

```bash
php artisan migrate --seed
php artisan serve
```

The API is at `http://127.0.0.1:8000/api/`. Health check: `GET http://127.0.0.1:8000/up`.

Seeded logins (password `Password123` for both):

| Email | Role |
|---|---|
| `admin@example.com` | admin |
| `customer@example.com` | customer |

Promote any user later with `php artisan user:role {email} admin`.

---

## Environment configuration

Copy `.env.example` to `.env` and fill at least `APP_KEY` (from `key:generate`) and the database block. Values that change this API’s behaviour:

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | Debug off hides internal exception text (`SERVER_ERROR`). |
| `APP_URL` | `http://localhost` | Used in pagination links. |
| `DB_CONNECTION` | `mysql` | Runtime store. Tests override this to SQLite. |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `127.0.0.1` / `3306` / empty / `root` / empty | Create the schema first; `DB_DATABASE` must not be empty. |
| `CACHE_STORE` | `database` | Product listing cache (version-key bump on catalog writes). Tests use `array`. |
| `QUEUE_CONNECTION` | `database` | `OrderPlaced` and `OrderStatusChanged` listeners are queued. Tests use `sync`. |
| `MAIL_MAILER` | `log` | No outbound mail; fulfilment hooks only log. |
| `SANCTUM_EXPIRATION` | `10080` (7 days, minutes) | Bearer token lifetime. |
| `CART_RESERVATION_TTL_MINUTES` | `15` | How long a cart line holds stock from other shoppers. Activity slides the window. |
| `CART_IDLE_DAYS` | `30` | Unused **guest** carts (and their reservations) are deleted. User carts are kept. |
| `ORDERS_UNPAID_PENDING_MINUTES` | `30` | After checkout, unpaid `pending` orders are cancelled by `orders:expire-pending`. |

Leave Redis unset unless you switch `CACHE_STORE` / `QUEUE_CONNECTION` to `redis`.

---

## Database setup

1. Create an empty MySQL database (utf8mb4).
2. Point `.env` at it.
3. Run migrations and seeders together so catalog, users, and promotions exist:

```bash
php artisan migrate --seed
```

`migrate:fresh --seed` wipes the schema and reseeds. Use that only on a local database.

What the seeder loads:

- Admin and demo customer (table above) plus five random customers
- Category tree (electronics, fashion, …) and active products (including `SNK-LTD-001` with stock 5 for the concurrency demo)
- Promotions: `SUMMER20`, `WELCOME10`, `AUDIO10` (audio category), `SNKDEAL` (sneakers), `ONCEONLY` (already used by `customer@example.com`)

Scheduled jobs that touch the same tables (run `php artisan schedule:work` in a second terminal while serving):

| Command | When | Effect |
|---|---|---|
| `cart:expire-reservations` | every minute | Drops expired stock holds and idle guest carts |
| `orders:expire-pending` | every minute | Cancels unpaid `pending` orders past `payment_due_at`; restores stock and promo usage |
| `sanctum:prune-expired` | daily | Deletes expired tokens |

Local queue worker (needed so pay/ship/deliver logs actually run when `QUEUE_CONNECTION=database`):

```bash
php artisan queue:work --tries=3
```

---

## Running the API

```bash
php artisan serve
```

Typical local terminals: `serve`, `queue:work`, and `schedule:work`. PHPUnit does not need those processes.

---

## Testing

PHPUnit uses SQLite in memory (`phpunit.xml`). It does not read your MySQL `.env`.

```bash
php artisan test
```

The PowerShell smoke script hits a **running** server and the **seeded MySQL** database. Wait a minute between runs: login is limited to 20 requests per IP per minute.

```powershell
php artisan migrate --seed
php artisan serve
# other terminal:
powershell -ExecutionPolicy Bypass -File scripts/api-smoke-test.ps1
```

---

## API

Request bodies, query params, headers, and example responses live in the Postman collection:

**[`dgt-assessment.postman_collection.json`](dgt-assessment.postman_collection.json)**

Set `base_url` to `http://127.0.0.1:8000/api/` (trailing slash). Run **auth / login** first; the test script stores `auth_token`.

---

## Orders and checkout

Guests shop with `X-Cart-Token`. Checkout needs Sanctum. `POST /api/checkout` requires `expected_total` (the last `GET /cart` `data.total`); a mismatch is `409 PRICE_CHANGED` and does not place. Matching checkout snapshots shipping and line prices, decrements stock, and opens the order as `pending`. Pay is a stub (`pending` → `paid`). Admin fulfilment is forward-only (`paid` → `processing` → `shipped` → `delivered`). Cancel from `pending` or `paid` restores stock. Unpaid pending orders expire after 30 minutes.

Diagrams, the checkout transaction, inventory holds, and the status machine: [`docs/orders-and-checkout.md`](docs/orders-and-checkout.md).

---

## Architecture decisions

The HTTP layer stays thin. Controllers authorize, validate, and return the envelope. Domain rules live in services so checkout, pay, cancel, and cart mutations can run inside one database transaction with row locks.

```
HTTP (routes, FormRequest, Policy)
        ↓
Service (Cart, Checkout, Orders, Promotions, Catalog, Inventory)
        ↓
Eloquent + MySQL (row locks, unique idempotency, snapshots)
```

**Why this shape**

- **Form requests + policies** keep validation and ownership off the controllers. List filters (`status`, dates, search) are parsed once and reused by customer and admin order indexes.
- **Money as integer cents in PHP, decimal strings on the wire** (`App\Support\Money`) avoids float rounding in discounts and totals.
- **Server-owned prices.** Cart and checkout re-read the catalog. `expected_total` must match the live payable total (integer cents) or checkout returns `409 PRICE_CHANGED` with no order. After place, item rows snapshot `product_name`, `product_sku`, and `unit_price` so later catalog edits do not rewrite history.
- **Shipping snapshot on `orders.shipping_*`**, not an address-book table. `GET /checkout/shipping` prefills from the latest order; `POST /checkout` still requires a full `shipping` object.
- **Guest cart, authenticated checkout.** `X-Cart-Token` is enough to shop. Register/login with that header merges the guest cart (locking both cart ids in order). Placing an order requires Sanctum.
- **Inventory in two layers.** Cart writes `stock_reservations` (TTL). Checkout decrements `products.stock_quantity` and releases the reservation. Cancel / unpaid expiry increment stock back.
- **Promotions re-checked at checkout**, including product/category scope, windows, caps, and per-customer limits. Applying a code on the cart is a preview that can go stale; checkout fails closed.
- **Idempotency** on checkout (`unique(idempotency_scope, idempotency_key)` with `scope = u:{userId}`) and on pay/cancel (second call is `200` with no extra stock or timestamp change).
- **Append-only history.** `order_status_changes`, product price/stock audits, promotion commercial-field audits. Checkout `increment`/`decrement` does not write product audits, so operational stock moves stay off the admin log.
- **Events as fulfilment hooks**, not mail. `OrderPlaced` and `OrderStatusChanged` dispatch only after a real commit (not on idempotent replay). Queued listeners log structured lines so mail can replace the log later without touching checkout or pay.
- **Catalog listing cache** with a version key (`CatalogCache`) so MySQL/array stores work without Redis tags. `available_quantity` is overlaid after the cache so reservations stay correct.
- **JSON always.** `ForceJsonResponse` plus a single renderer: domain `ApiException` subclasses expose stable `code` values (`INSUFFICIENT_STOCK`, `INVALID_ORDER_TRANSITION`, …).

---

## Concurrency handling

All contended writes take **row locks inside a transaction**. Locks are acquired in **ascending primary key order** so two requests cannot deadlock.

### Two buyers, last units

```
Buyer A and Buyer B both POST /checkout for the same SKU
        │
        ├─ BEGIN
        ├─ lock cart
        ├─ lock products ORDER BY id
        ├─ UPDATE products SET stock_quantity = stock_quantity - qty
        │     WHERE id = ? AND stock_quantity >= qty
        │
        └─ if affected rows = 0 → ROLLBACK, 409 INSUFFICIENT_STOCK
```

The `WHERE stock_quantity >= qty` clause is the actual guard, not a PHP read-then-write. The loser waits on `FOR UPDATE`, then sees the reduced stock and fails. Cart add/update uses the same product lock plus reservation math so two guests cannot reserve more than on-hand.

### Overlapping pay / cancel / admin PATCH

`CapturePaymentService`, `CancelOrderService`, and `AdvanceOrderStatusService` lock the **order** row first. A second pay after the first committed sees `paid` and returns `200` without writing history again. Cancel uses a conditional `UPDATE … WHERE status IN (pending, paid)` so a concurrent fulfil cannot both ship and restore stock. Expiry only cancels `pending`; a customer who paid inside the window is ignored.

### Guest cart merge on login

User cart id and guest cart id are locked in sorted order, then product rows in id order, then lines are merged up to remaining `available` (on-hand minus other carts’ reservations).

### Idempotent checkout under retry

Two identical `Idempotency-Key` requests can both pass the pre-insert select. One insert wins the unique `(scope, key)` constraint; the other catches `UniqueConstraintViolationException`, rolls back (stock untouched), and returns the winner’s order as `200`.

PHPUnit locks this with `CheckoutConcurrencyTest`. The smoke script exercises the same path against MySQL.

---

## Important trade-offs

These are deliberate for a small shop / this assessment, not unfinished wiring.

| Choice | Why | Cost |
|---|---|---|
| Stock drops at **checkout**, not at pay | Pay is a stub; holding stock only after money would leave the catalog overselling until a processor exists. Reservations already cover the browse/cart window. | Unpaid `pending` holds inventory until the customer pays, cancels, or `payment_due_at` elapses (`orders:expire-pending`). |
| Reservation TTL (15 min) instead of decrement-on-add | Shoppers can abandon carts without permanently locking the warehouse. Storefront `available_quantity` still hides held units. | Two customers can both see “in stock”, then one loses at checkout (`409`). That is accepted over blocking browse. |
| No guest checkout | Orders need a `user_id` for ownership, pay, cancel, and per-customer promo limits. Guest **carts** stay. | Extra register/login step before place. |
| `expected_total` confirmation, not a cart price lock | Cart stays live-priced. Checkout refuses a silent catalog/promo change. | Client copies `GET /cart` `data.total`; stale UI gets `409` and retries. |
| Shipping columns on `orders`, no address book | One snapshot per order, trivial prefill from the latest row, no extra table. | `POST /checkout` always sends a full address; there is no “saved addresses” CRUD. |
| Admin cannot cancel via `PATCH` | Cancel restores stock and promo usage; fulfilment PATCH must not. | Two endpoints to learn; `422` if they send `status: cancelled`. |
| Forward-only fulfilment, no skip | Matches a small warehouse (paid → pack → ship → deliver). | No “mark delivered from paid” shortcut. |
| Payment stub | Scope is inventory + idempotency, not a card processor. | No webhook, no failed-charge, no refunds after `delivered`. |
| Queued **logs**, not mail | Checkout/pay stay in-process; a later mail listener can replace `LogOrderPlaced` / `LogOrderStatusChanged`. | Local `QUEUE_CONNECTION=database` needs `queue:work` or logs wait in `jobs`. |
| Listing cache without Redis tags | Version integer works on `database`/`array` cache. | Any catalog write invalidates every listing key, not one SKU. Fine at this size. |
| SQLite in tests, MySQL in runtime | Fast, isolated PHPUnit. Row-lock behaviour is also covered by the MySQL smoke script. | A test-only pass is not a substitute for one smoke run against InnoDB. |

What a live store would still add (not missing from this design): card processor, tax and shipping rates, password-reset API (table exists), returns after `delivered`, product variants.

---

## Layout

```
app/Http/          Controllers, requests, resources, policies
app/Services/      Cart, Checkout, Orders, Promotions, Catalog, Inventory
app/Exceptions/    Stable API error codes
docs/              Checkout and order lifecycle
routes/api.php     HTTP surface
database/          Migrations and seeders
tests/             PHPUnit (SQLite)
scripts/api-smoke-test.ps1
dgt-assessment.postman_collection.json
```
