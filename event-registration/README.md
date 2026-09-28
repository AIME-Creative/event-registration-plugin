# Event Registration

WordPress event registration plugin with embedded Stripe checkout, Supabase membership-tier discounts, and GoHighLevel pipeline sync.

## Install

1. Zip the `event-registration` folder (or use the provided zip) and upload via **Plugins → Add New → Upload Plugin**, then activate.
2. Two database tables are created automatically (`wp_evr_events`, `wp_evr_registrations`). **No new Supabase table is needed** — registrations are stored in WordPress; Supabase is only read for membership lookups.

## Setup (one time)

Go to **Event Registration → Settings**:

**Stripe** — paste your test and live publishable/secret keys. The Mode toggle sets the global default (keys, product catalog, checkout) — no code changes ever. Each event can also override this individually via its "Stripe account" setting, so one event can run live while another tests in the sandbox. A colored badge on every admin page shows the current mode, and the front-end shows a "Test mode" banner when in sandbox.

**Webhooks** — in the Stripe Dashboard (do this in both test and live mode), add a webhook endpoint pointing at the URL shown on the Settings page (`/wp-json/evr/v1/stripe-webhook`) with events `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded` — and, if you use **payment plans**, also `invoice.paid`, `invoice.payment_failed`, `invoice.marked_uncollectible`. Paste each signing secret into Settings. Registrations are only marked **confirmed** when Stripe confirms payment; refunds in Stripe automatically mark the registration **refunded**.

**Supabase** — project URL, service role key (kept server-side), table/column names. Tier values are matched case-insensitively and "Processor" variants map to the base tier automatically (e.g. "Elite Processor" → Elite discount).

**GoHighLevel** — your default Private Integration token. Events in other sub-accounts can override the token per event.

**Appearance** — set the form's font and colors (Register button background and text, body/label text, input border, and accent used for input focus). These are global defaults for every form; leave a color blank to inherit your theme. Each event can override any of them under its own **Appearance (this event)** section in the editor, so one event can look different from another with no code changes.

## Creating an event

**Event Registration → Add Event**:

- **Tickets & Add-ons** — click "Load Stripe products" to pick products from your catalog (price auto-fills from the product's default price; this is the regular price). A simple registration fee = one Main ticket; the front-end then shows no ticket picker at all. Add multiple Main tickets for ticket choices; Add-ons appear only after a main ticket is selected. Optional per-ticket capacity (sold out automatically).
- **Pricing Periods** — any number of time-based prices per ticket, e.g. Early Bird 1 until March 1, Early Bird 2 until April 1, then regular price. Membership discounts are always calculated off the regular price, and by default the registrant automatically gets whichever saving is larger — the period price or their member discount — never both. Tick **"While an early-bird pricing period is active, ignore membership tier discounts"** to instead have the active period price always win (member discounts are skipped while a period is running); promo codes still apply. The toggle is per-event and off by default.
- **Tier discounts** — fixed $ or % off the main ticket per tier, shown in the order summary as e.g. "Premium member discount (10% off)" (Premium / Elite / VIP, Processor variants included).
- **Promo codes** — fixed or %, with optional max uses and expiry.
- **GoHighLevel** — per-event Location ID, then "Load pipelines" to pick the pipeline and stage (or paste IDs manually). Confirmed registrants are upserted as contacts (with tags) and dropped into the pipeline as an opportunity with the amount paid as monetary value. Optionally set an **Abandoned cart stage** (same pipeline): a registration left unpaid for 24 hours is pushed to that stage by an hourly background job so you can follow up; when the person later completes payment, the same opportunity automatically moves to your registered stage (no duplicate). The 24-hour delay can be changed via the `evr_abandoned_cart_delay` filter. For events with a **Payment Plan** (below), you can also set a **Payment plan: Outstanding stage** and a **Payment plan: Fully paid stage** (same pipeline): registrants who choose the plan land in the Outstanding stage on their first payment instead of the registered stage, and the same opportunity moves automatically to the Fully paid stage once the final installment is collected. Leave the Outstanding stage blank to use the normal registered stage; leave Fully paid blank to keep them in Outstanding.
- **Custom Form Fields** — add any questions and map each to a GHL field. Each field can be shown conditionally via **Show when**: pick "Show when ALL/ANY match", then add rules like `Attending = Yes`. Controlling fields must be a Dropdown or Checkbox (for a checkbox, match the value `Yes` or use "is not empty / checked"); operators are equals, does not equal, is one of (comma-separated), and is not empty/checked. Conditions can chain (a conditional field can control another), and hidden fields are never required and aren't submitted. Reorder fields by dragging the handle. The picker includes standard contact fields (company name, address, website, etc.) and opportunity source out of the box; "Load GHL fields" pulls the location's contact and opportunity custom fields too. Mapped opportunity fields are written onto the opportunity; everything else goes on the contact record.
- **UTM Tracking** — `utm_source/medium/campaign/term/content` on the visitor's link are captured automatically (and survive payment redirects), saved with each registration, exported in the CSV, and can be mapped per event to any GHL field — ideal for tracking whose affiliate link drove a signup.
- **Payment Plan** — optionally let registrants split the total into a fixed number of automatic payments (e.g. two). Set the **number of payments**, the **interval** (every N months or days), an optional **minimum total** below which the plan isn't offered, and a **"Final payment due by"** date. The total is split evenly (any rounding cent goes onto the first payment), including add-ons and discounts. If the schedule couldn't finish before the "final payment due by" date — e.g. the event is now too close — the plan option **disappears from the form automatically** and full payment is required (also enforced server-side).

  **How installments are collected:** the **first payment** is taken on the page today via the Payment Element and the card is saved to a Stripe **Customer** (set as their default). Each **later installment** is issued on its due date (by a daily background job) as a **Stripe invoice** with `collection_method=charge_automatically`, so Stripe auto-charges the saved card, emails a receipt, hosts an invoice page, and — on a decline — runs its own **retry/dunning** and emails the customer a link to update their card. Only `invoice.marked_uncollectible` (Stripe giving up) marks the plan **defaulted** in the plugin. `evr_plan_completed` fires once every payment is collected; `evr_installment_failed` fires on terminal failure.

  **Customers can change the card** used for upcoming installments via the **Stripe Customer Portal** (or the link in Stripe's dunning email); because invoices bill the customer's *current default* card, a change is picked up automatically. **Your team** can assist from the Stripe dashboard, or from the registrations list: each plan registration shows the full installment schedule (amount, status, hosted-invoice link), a **"Manage card"** button that generates a single-use portal link to send/use on a call, and a **"View in Stripe"** link. The CSV adds Plan Status / Paid / Installments columns.

  **Paying off early:** on a plan registration, the team can click **"Send invoice for remaining balance"** — this emails the customer a single Stripe invoice (hosted pay page, any card) for everything still owed. While it's outstanding the scheduled auto-charges **pause**; when the customer pays it, all remaining installments are marked paid, the plan **completes**, and the GHL "Fully paid" move runs. Any auto-charge invoice already mid-collection is voided first so nothing double-charges. If the customer never pays the payoff invoice (it's voided/expires), the normal auto-charge schedule simply resumes. The due window is filterable via `evr_payoff_days_until_due` (default 7 days).

  **Required Stripe setup for payment plans** (in both test and live): add the webhook events **`invoice.paid`**, **`invoice.payment_failed`**, and **`invoice.marked_uncollectible`** to your endpoint (alongside the existing `payment_intent.*` / `charge.refunded`); **activate the Customer Portal** (Settings → Billing → Customer portal) with payment-method updates enabled; and turn on Stripe's customer receipt + failed-payment emails and retry schedule (Billing settings).
- **Registration window** — open/close dates; outside the window the form shows a closed message.
- **Waitlist** — optionally show the same form as a waitlist (no payment) before registration opens and/or when all main tickets hit capacity. Waitlist signups sync to GHL into their own stage — usually in the same pipeline, or a different pipeline if you choose. Entries appear with **waitlist** status in the registrations list and CSV.

Publish by setting Status to **Active**, then place the form anywhere with the shortcode shown on the Events list:

```
[event_registration id="123"]
```

Checkout happens entirely on your page via the Stripe Payment Element (cards, Apple Pay/Google Pay where enabled in Stripe). If the total is $0 (free event or fully discounted), payment is skipped.

## Registrations

**Event Registration → Registrations** — per-event list with payment status, membership tier, GHL sync status (with one-click **Retry** on failures), and a **Download CSV** button. Custom field answers are flattened into their own CSV columns. Test-mode registrations are flagged so they never mix with live data.

Duplicate confirmed registrations per email are blocked by default (toggle per event).

### Adding someone manually (no payment collected)

For registrants you invoice separately, comp, or who paid some other way, open **"+ Add a registrant manually"** on the Registrations screen. It creates a confirmed registration without touching Stripe — they count on the roster, in the CSV and against ticket capacity, and they sync to GoHighLevel like anyone else. Sold-out tickets and a closed registration window are ignored here.

Each manual add picks how it's being handled for money:

| Payment handling | Amount recorded | GoHighLevel stage |
| --- | --- | --- |
| Invoiced separately — not yet paid | owed (nothing collected) | the event's **Outstanding payment** stage, if one is set |
| Already paid (outside the site) | collected in full | the event's normal registered stage |
| Comped — no charge | $0 | the event's normal registered stage |

The ticket is priced exactly as the checkout would price it — membership tier lookup, pricing periods and promo codes all apply — or you can type an amount to override it. An invoiced registration gets a **Mark paid** button that records the money and moves the GHL opportunity out of the Outstanding stage.

### A staff page your team can use (no WordPress accounts)

Put this shortcode on a page and set that page to **Password protected** (Page → Visibility), then share the password with your team:

```
[event_registration_staff]
```

The form **lists every event straight from the database**, so events you create in future need no setup — they simply appear in the dropdown. Pick the event, fill in the registrant, choose how the money is being handled, and submit; it then clears itself ready for the next person while staying on the same event.

It records who added each registration (a required "Your name" field), shown in the admin list and the CSV.

The form **refuses to render on a page with no password**, so it can't be published wide open by mistake — an admin viewing such a page sees an explanation instead. Every submission re-checks the gate server-side, and submissions are rate-limited.

| Attribute | Default | What it does |
| --- | --- | --- |
| `require_login` | `no` | `yes` gates on a WordPress login instead of the page password |
| `capability` | `edit_posts` | With `require_login="yes"`, which users qualify |
| `status` | *(all)* | e.g. `active` to list only active events |
| `title` | Add a registration | The heading above the form |

### Letting a GoHighLevel form add registrants

**Settings → Manual registrations** exposes the same thing as a REST endpoint, so a GHL form's workflow can create registrations directly with a **Custom Webhook** action. Note the trade-off: a GHL workflow has to be told which `event_id` to use, so it needs updating for every new event — the staff page above avoids that entirely.

```
POST /wp-json/evr/v1/manual-registration
X-EVR-Key: <the shared key from Settings>

{
  "event_id": 12,
  "email": "someone@example.com",
  "first_name": "Jane",
  "last_name": "Doe",
  "phone": "555-0100",
  "ticket_key": "tk_abc123",
  "payment_state": "invoiced",
  "note": "Invoice #1042"
}
```

Everything except `event_id`, `email`, `first_name` and `last_name` is optional; the full payload is documented on the settings screen. A success returns HTTP 201 with the new `registration_id`; a duplicate email returns 409. **Leave the key blank to switch the endpoint off entirely.**

## Notes

- Prices are always recomputed server-side at payment time; the browser is never trusted for amounts, tiers, or promo validity.
- An `evr_registration_confirmed` action fires after each confirmed registration if you want to hook in confirmation emails or other integrations. Manual adds fire it too, plus their own `evr_manual_registration_added`.
- The Stripe webhook is the authoritative confirmation path; a client-side fallback (verified against Stripe's API) covers webhook delays.
