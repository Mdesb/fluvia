# Domain event catalog — v0

The first-class events published on the bus by the core and the modules. An event is a **past fact**
(named in the past tense). Modules subscribe to events; nobody calls anybody directly.

> **Naming decision (D5, 19/08/2026): all technical identifiers are in ENGLISH** — event names,
> entities, tables, columns, enum values, API/DTO fields, permission codes. User-facing strings are
> **never** hardcoded: they are translation keys resolved by the i18n layer (French default).

## Common envelope (every event)
```
{
  "name": "payment.failed",
  "occurredAt": "2026-08-19T10:00:00Z",
  "tenant":  { "establishmentId": "…" },      // scope — always present
  "actor":   { "userId": "…" | null },         // who triggered it (null = system)
  "subject": { "type": "Payment", "id": "…" }, // the entity concerned
  "payload": { … }                             // event-specific data (ids + minimum, no secrets/PII)
}
```

## v0 events by domain
| Event | Emitted by | Key payload | Likely consumers |
|---|---|---|---|
| `sale.completed` | Sale/POS | amount, lines, customer? | Reporting, Revenue Recovery |
| `sale.cancelled` | Sale/POS | reason, amount | Accounting, Authorization |
| `cart.abandoned` | Shop | amount, customer | Revenue Recovery |
| `payment.succeeded` | Payment | amount, method | Accounting, Invoicing |
| `payment.failed` | Payment / SEPA | amount, cause | **Revenue Recovery**, dunning |
| `payment.incident_reopened` | Dunning | amount | **Revenue Recovery**, dunning |
| `refund.issued` | POS/Invoicing | amount, credit_note? | Accounting |
| `invoice.issued` | Invoicing | number, total_incl_tax | Accounting, Communication |
| `invoice.overdue` | Invoicing | amount, days_late | **Revenue Recovery** |
| `invoice.paid` | Invoicing | amount, date | Accounting |
| `credit_note.issued` | Invoicing | amount | Accounting |
| `booking.created` | Reservation | slot, resource | Smart Flow |
| `booking.cancelled` | Reservation | slot, lead_time | **Smart Flow**, Revenue Recovery |
| `booking.no_show` | Reservation | customer, amount_at_risk | **Revenue Recovery**, Smart Flow |
| `booking.completed` | Reservation | duration | Reporting |
| `slot.released` | Smart Flow | slot, resource | **Smart Flow** (slot recovery), waitlist |
| `subscription.created` | SEPA/Subscription | recurring_amount | Accounting |
| `subscription.suspended` | SEPA/Subscription | reason | Revenue Recovery |
| `access.recorded` | Access control | door, credential | Reporting, Smart Flow (footfall) |
| `access.denied` | Access control | reason | Supervision |
| `quote.sent` | Quote | amount, due_date | Revenue Recovery |
| `quote.expired` | Quote | amount | **Revenue Recovery** |
| `quote.accepted` | Quote | amount | Invoicing |
| `customer.inactive` | CRM | last_contact | Revenue Recovery (win-back) |
| `customer.came_of_age` | CRM | channels_to_renew | Communication, CRM (consent renewal) |
| `lead.unanswered` | CRM/Sales | channel, delay | Revenue Recovery (lead recovery) |
| `supplier_invoice.recorded` | Finance | supplier, amount, ocr? | Accounting, Treasury |
| `expense_report.submitted` | Expenses | employee, amount | Authorization, Accounting |
| `feasibility.assessed` | Pre-sales | outcome (go/no-go) | Quote, Sales |
| `intervention.scheduled` | Field service | technician, site | Personnel, Calendar |
| `intervention.validated` | Field service | signed_report, photos | DMS, Invoicing |
| `tender.analyzed` | Tender | requirements | Sales |
| `tender.draft_generated` | Tender | draft_ref | Sales (human review required) |
| `training.completed` | Training | employee, course | Personnel |

## Naming rules
- `domain.fact_past_tense`, lowercase, `snake_case` for the fact.
- English only. Domain nouns in English (Invoice, Ledger, Booking, ExpenseReport…).
- An event carries **references** (ids) + the minimum — never a secret or unnecessary PII.
- Adding an event = 1 row here + declare it in the emitting module's manifest.
