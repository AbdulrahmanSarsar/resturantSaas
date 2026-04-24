# MenuPro — End-to-End Testing Checklist

> Run this before deploy. Cover every role × every branch.
> آخر تحديث: 23 أبريل 2026

---

## 0. Pre-deploy

- [ ] Run migration `migrations/2026_04_20_recommended_indexes.sql` on production DB
- [ ] Verify `assets/uploads/.htaccess` uploaded (defense-in-depth)
- [ ] PHP version ≥ 8.0 on server (for `finfo_file` / random_bytes)
- [ ] `fileinfo` extension enabled (`php -m | grep fileinfo`)
- [ ] Session save path writable

---

## 1. Customer Flow (main branch)

- [ ] Open `https://menu.almanarsoft.com/menu/flame-bites/main`
  - [ ] Menu renders (dishes, categories, prices in branch currency)
  - [ ] Sold-out dishes greyed out (per-branch override)
  - [ ] Welcome message appears
- [ ] Click dish → detail page
  - [ ] Options render (radio/checkbox)
  - [ ] AR button shows on dishes with `has_model3d=1`
- [ ] Add to cart → `/cart`
  - [ ] Table number auto-fills if QR has `?table=X`
  - [ ] Coupon code input works (valid → discount applied)
  - [ ] Tax breakdown shows per-branch taxes
- [ ] Submit order
  - [ ] Success redirect to `/order/{id}`
  - [ ] Order row in DB has correct `branch_id` (not 8, not NULL)
- [ ] Track order: status polling updates without reload
- [ ] Deliver → rating page → submit stars for order + waiter
- [ ] Invoice page: pay with cash / shamcash
- [ ] Repeat for **second branch** (`/menu/flame-bites/فرع-المزة`) — confirm:
  - [ ] Different currency (if configured)
  - [ ] Different sold-out markers
  - [ ] Different tax rates (if configured)
  - [ ] Order lands in correct branch (not the main one)

---

## 2. Staff Pages (kitchen / waiter / cashier)

For **each** active branch staff:

- [ ] Login with staff credentials → lands on role page
- [ ] Sees **only** this branch's orders (not the other branch)
- [ ] Kitchen:
  - [ ] Confirm / preparing / ready transitions update in real-time
  - [ ] Sold-out toggle writes to `branch_dish_overrides` (verify in DB — per-branch row)
  - [ ] AJAX actions work after re-login (CSRF token refreshed per session)
- [ ] Waiter:
  - [ ] Ready orders list populates
  - [ ] Mark delivered → status updates, sound plays on next new "ready"
  - [ ] Notification beeps for new ready order
- [ ] Cashier:
  - [ ] Unpaid delivered orders list
  - [ ] Select cash / card / shamcash → confirm → row marked paid
  - [ ] Today's stats (branch-filtered) match expected
- [ ] Logout via `?logout=1` → redirects to login

---

## 3. Owner Dashboard

- [ ] Login as owner
- [ ] Branch switcher in sidebar works
  - [ ] Switch to branch A → dashboards filter accordingly
  - [ ] Switch to branch B → new numbers appear
- [ ] Each page works for each active branch:
  - [ ] `index.php` — home stats
  - [ ] `orders.php` — branch-filtered + LIMIT 300 (doesn't load all history)
  - [ ] `stats.php` / `reports.php` — daily / monthly / compare tabs
  - [ ] `chain.php` — cross-branch view
  - [ ] `dishes.php` — add/edit/delete + options CRUD + offers
  - [ ] `categories.php` — CRUD
  - [ ] `branches.php` — add/edit/toggle/delete + slug auto-generation
  - [ ] `branch_settings.php` — currency / shamcash / notifications / social
  - [ ] `coupons.php` — add / toggle / delete + branch selector
  - [ ] `offers.php` — bxgy + combo + image upload
  - [ ] `taxes.php` — per-branch CRUD
  - [ ] `ratings.php` — LIMIT 500 applied
  - [ ] `profile.php` — restaurant info + staff CRUD with branch dropdown
  - [ ] `qr.php` — branch-aware QR codes

---

## 4. Security Tests

### CSRF
- [ ] POST to `/restaurant/dashboard/orders.php` **without** `_csrf_token` → 403
- [ ] POST to `/restaurant/staff/cashier.php` without header → 403 JSON
- [ ] Submit any form after tampering with hidden `_csrf_token` → rejected
- [ ] Normal form submit works after page load (token round-trip)

### File Uploads (`src/Helpers/FileUploader.php`)
- [ ] Upload JPG → succeeds, filename = random hex, chmod 0644
- [ ] Rename `evil.php` → `evil.jpg` (PHP content) → **rejected** (finfo MIME check)
- [ ] Upload empty file → rejected (0 bytes)
- [ ] Upload file > max size → rejected
- [ ] Upload `model.glb` without glTF magic bytes → rejected
- [ ] Upload `.usdz` without PK zip signature → rejected
- [ ] Try path traversal via subfolder `../../` → sanitized (regex strips it)
- [ ] `assets/uploads/.htaccess` exists → tries to execute `.php` in uploads → 403

### SQL Injection
- [ ] Search input with `' OR 1=1 --` → no leak (prepared statements)
- [ ] Menu slug with special chars → 404 not 500
- [ ] All input bound as parameters (grep `"\\.\\s*\\$"` should show no concat)

### Session / Auth
- [ ] Access `/restaurant/dashboard/*.php` without login → redirect to login
- [ ] Access `/restaurant/staff/kitchen.php` as waiter → redirect (role mismatch)
- [ ] Access `/admin/*.php` without admin session → redirect

### Plan Guard
- [ ] Basic plan user tries to access `coupons.php` → upgrade screen
- [ ] Advanced plan user tries `staff` feature → upgrade screen
- [ ] Premium plan user → everything works

---

## 5. Performance Spot-Checks

Load Chrome DevTools → Network:

- [ ] `restaurant/staff/kitchen.php` — initial request < 500ms
- [ ] `restaurant/staff/waiter.php` — initial request < 500ms
- [ ] `restaurant/dashboard/orders.php` — loads without blocking when orders > 300
- [ ] `menu/order_track.php` polling request < 200ms (constant queries, not N×items)
- [ ] `restaurant/dashboard/ratings.php` — loads with LIMIT 500
- [ ] Slow query log empty for these pages

---

## 6. Data Integrity

Run these SQL checks after smoke-test:

```sql
-- No orders should have NULL branch_id
SELECT COUNT(*) FROM orders WHERE restaurant_id = 5 AND branch_id IS NULL;

-- branch_id must match a real branch of that restaurant
SELECT o.id FROM orders o
LEFT JOIN branches b ON b.id = o.branch_id AND b.restaurant_id = o.restaurant_id
WHERE o.restaurant_id = 5 AND b.id IS NULL;

-- No staff without branch
SELECT COUNT(*) FROM restaurant_staff WHERE branch_id IS NULL AND is_active = 1;

-- branch_dish_overrides unique on (branch_id, dish_id)
SELECT branch_id, dish_id, COUNT(*) c
FROM branch_dish_overrides GROUP BY branch_id, dish_id HAVING c > 1;

-- All expected indexes present
SHOW INDEX FROM orders;
SHOW INDEX FROM order_items;
SHOW INDEX FROM branch_dish_overrides;
```

---

## 7. Deploy Steps

1. [ ] `git push origin claude/brave-diffie-df9abe`
2. [ ] Merge PR to `main`
3. [ ] SSH → pull on production (`cd public_html && git pull`)
4. [ ] Run migration on production DB via phpMyAdmin or CLI
5. [ ] Verify `config/csrf.php` present
6. [ ] Verify `assets/uploads/.htaccess` present (not overwritten by deploy)
7. [ ] Hit live demo: https://menu.almanarsoft.com/menu/flame-bites
8. [ ] Smoke-test: 1 order end-to-end in each branch
9. [ ] Monitor error log for 1 hour post-deploy

---

## Rollback

If anything breaks:

```bash
git revert HEAD --no-edit
git push origin main
# Pull on server + hard refresh browsers
```

DB changes from the migration are **additive only** (new indexes) — safe, no rollback needed.
