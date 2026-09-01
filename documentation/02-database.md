# Database

Netkit uses **MySQL/MariaDB** with the Eloquent ORM. The schema is managed entirely through timestamped migrations in `database/migrations/`, which have grown incrementally from January 2026 onwards. All changes are additive — new columns and new tables only, no destructive migrations in production.

---

## Users & Authentication

The `users` table is the primary user account store. Alongside the standard name/email/password columns it carries Google OAuth columns (`google_id`, `avatar`), 2FA columns (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `two_factor_channel`, `phone`), and a `credit_balance` decimal that tracks the user's prepaid AI credit in USD.

The `admins` table is a separate account store for the Filament admin panel. Admins do not share rows with `users`. Both tables have a `credit_balance` column so admins can also hold credits.

Supporting tables: `sessions` (database-backed session storage), `password_reset_tokens` (Fortify password reset), `personal_access_tokens` (Sanctum API tokens), `cache` / `cache_locks` (Laravel cache), `jobs` / `failed_jobs` (queue tables).

---

## Documents

The `documents` table is the central record for every uploaded or generated PDF. Each row stores the file path, original filename, MIME type, file size in bytes, and a first-page preview thumbnail. The `mode` column records how the document was created (`upload`, `ai`, `template`, `invoice`, `guided`). Template-based documents also store `template_type`, `template_slug`, and a JSON `form_data` blob of the user's field values.

Documents can be owned by a `user_id`, an `admin_id`, or neither (session-scoped anonymous documents). The `original_backup_path` column stores a copy of the file before any edits are applied, enabling the "restore original" action.

---

## PDF Editor State

### `pdf_state`

This is the annotation layer. Each row represents one saved state for a single page of a document, scoped to a particular user and browser session. The core payload is `annotation_data` (JSON array of annotation objects). Additional columns track the linked extraction run (`pdf_extraction_fitz_id`), page number, alignment metadata, and a QA flag system (`flagged`, `flag_reason`, `flag_images`, `annotation_debug`).

### `pdf_acro_form`

Stores interactive AcroForm (PDF form field) values separately from the annotation overlay, so form fills and text annotations are kept independent.

### `document_notes`

Sticky-note pins attached to a document that do not appear in the exported PDF. Each note stores a page + position `anchor` (JSON), a `pin_style` (one of 6 colours × 6 icons), and a freetext body.

---

## PDF Extraction

When a PDF is uploaded, PyMuPDF analyses its content and the results are stored in a set of related tables:

- **`pdf_extractions_fitz`** — one row per extraction run, keyed by `document_id` + `user_email` + `session_id`.
- **`pdf_extraction_pages`** — one row per page within an extraction.
- **`pdf_extraction_blocks`** — grouped text blocks within a page.
- **`pdf_extraction_spans`** — individual character runs, each carrying font name, size, colour, and bounding box coordinates.

This hierarchical structure (extraction → page → block → span) powers the "promoted annotation" feature: clicking a text area in the editor lifts the underlying span data into an editable overlay annotation.

---

## AI Features

### Requests, Responses, and Price Log

Every AI API call is logged in `ai_requests` and `ai_responses` for debugging and billing. The `ai_price_log` table provides granular per-request cost tracking: model name, input/output token counts, image count, session ID, and estimated USD cost.

### AI Document Generation

`ai_documents` is the top-level record for a generated document. It links to `ai_sections` — individual content blocks (text, image placeholders) with position and dimension data — and `ai_images` for any images produced during generation.

---

## Logo Generator

The `ai_logo_requests` table stores every generation request. Key columns include the user prompt, the AI model used (`dalle3`, `flux`, `recraftv4_vector`, etc.), output format (`raster` or `vector`), a seed number for reproducibility, and a `result_data` JSON array of generated image paths/URLs. The `is_favourited` and `is_showcase` flags control personal favourites and the public `/browse-logos` gallery respectively.

`ai_logo_prices` and `ai_rates` store per-model cost configuration. `saved_logo_palettes` lets users persist colour palettes for reuse. `vector_editor_states` stores SVG editor state for post-generation vector editing.

---

## Domain Search

`ai_domain_requests` tracks AI-powered domain name generation jobs, including the status (`pending`, `processing`, `done`) and the candidate + availability results. `saved_domains` stores domains a user or admin has bookmarked, with a last-checked availability status.

---

## Credits & Subscriptions

`credit_transactions` is the ledger of all credit movements — every top-up, AI cost deduction, or manual adjustment. Each row has an `amount` (positive for additions, negative for deductions), a `type`, a description, and an optional `stripe_payment_intent_id` for traceability.

`monthly_plans` defines the subscription tiers with Stripe Price IDs, pricing, and a JSON `features` blob. `user_subscriptions` links users to plans and tracks the Stripe subscription ID, billing period dates, and cancellation timestamp.

`user_pdf_monthly_usages` enforces free-tier limits: 100 uploads and 1,000 editor actions per user per month.

`fal_balance_ledger` and `openai_balance_ledger` track the platform's own API account balances with Fal.ai and OpenAI — separate from user credits, surfaced in the admin panel for spend monitoring.

---

## Key Relationships

```
User
 ├── has many CreditTransaction
 ├── has many UserSubscription → MonthlyPlan
 ├── has many Document
 ├── has many AiLogoRequest
 └── has many SavedDomain

Document
 ├── belongs to User (nullable)
 ├── belongs to Admin (nullable)
 ├── has many PdfState
 ├── has many PdfExtractionFitz → PdfExtractionPage → PdfExtractionBlock → PdfExtractionSpan
 └── has many DocumentNote

PdfState
 ├── belongs to Document
 ├── belongs to PdfExtractionFitz
 ├── belongs to User
 └── belongs to Admin
```
