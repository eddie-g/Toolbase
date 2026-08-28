# Netkit — Application Overview

Netkit is a multi-tool SaaS platform built on **Laravel 11**. It bundles several distinct productivity products under one login and credit system: a PDF editor, an AI logo generator, a domain name search tool, an AI document generator, and an internal compliance test suite.

---

## Tools at a Glance

**PDF Editor** — Upload any PDF, place text and image annotations over it, fill AcroForm fields, redact content, and download the modified file. The original file is never altered in the browser; all edits are stored as a JSON overlay and stamped server-side using PyMuPDF.

**Logo Generator** — Generate AI-powered logos using DALL-E, GPT-Image-1, Flux, and Recraft. Supports both raster PNG and vector SVG output. Generated logos can be edited in a built-in SVG vector editor.

**Domain Search** — Suggest domain name ideas and check live availability against Namecheap and GoDaddy APIs. Includes an AI-powered name generator and a saved-domains list.

**AI Document Generation** — Use Gemini + GPT-4 to generate complete documents (newsletters, invoices, guided templates). Content sections are arranged on a PDF canvas and can include AI-generated images.

**Compliance Testing** — An internal visual parity test suite that compares PDF rendering output across editor versions. Developer-facing only.

---

## Tech Stack

### Backend

The backend is **Laravel 11 / PHP 8.x** with **Filament v3** for both the admin and user portal panels. Queues are powered by **Laravel Horizon** on Redis. Authentication is handled by **Laravel Fortify** (email/password + 2FA) and **Laravel Sanctum** (API tokens), with Google OAuth via Socialite. Payments go through **Stripe Checkout** for both one-time credit top-ups and monthly subscriptions.

### Frontend

The frontend is built and bundled with **Vite**. Styles use **Tailwind CSS**. The PDF viewer is a customised fork of **PDF.js**. The main overlay editor (`edit-new`) is plain TypeScript/JavaScript with a canvas rendering layer and a separate HTML rich-text layer — no Vue or React, intentionally framework-free for performance.

### Python Services

All server-side PDF manipulation runs through **PyMuPDF (fitz)** Python scripts in `python/pdf-editor/`. These handle annotation stamping, font extraction, redaction, format conversion (PDF/A, Word, Excel), encryption, and page management. The PHP controllers shell out to these scripts via `Process::run()`.

### Storage and Database

Storage uses Laravel's local disk with a public symlink (`storage/app/public/` → `public/storage/`). The database is MySQL/MariaDB with Eloquent ORM. All schema changes live in timestamped migrations under `database/migrations/`.

---

## Folder Structure

```
app/
  Http/Controllers/   — One controller per feature area
  Models/             — Eloquent models
  Services/           — API clients and utility services
  Jobs/               — Queued jobs (domain AI, document processing)
  Filament/           — Admin panel resources and pages
  UserPortal/         — User-facing Filament panel
  Actions/            — Single-purpose action classes
  Support/            — Helper and utility classes
  Traits/             — Reusable trait mix-ins

resources/views/
  auth/               — Login, register, password reset
  user/               — User dashboard
  user-portal/        — Filament user portal pages
  documents/          — PDF editor views (edit, edit-new, edit-pdfjs)

database/migrations/  — All schema migrations (chronological)
python/pdf-editor/    — PyMuPDF annotation and conversion scripts
python/fonts/         — Bundled open-source fonts for PDF export
```

---

## URL Entry Points

| Path | Audience |
|---|---|
| `/` | Public marketing home |
| `/portal` | Authenticated user dashboard |
| `/admin` | Filament admin panel |
| `/pdf-editor` | Public PDF editor (no login required) |
| `/logo-generator` | AI logo generation |
| `/domain-search` | Domain name search |
| `/browse-logos` | Public showcase of generated logos |

---

## Key Config Files

- **`config/services.php`** — API keys for OpenAI, Gemini, Fal.ai, Recraft, Stripe, Namecheap, GoDaddy.
- **`config/pdf_editor.php`** — PDF editor-specific settings.
- **`config/font_substitutes.json`** — Maps embedded PDF font names to safe open-source equivalents used at export time.
- **`config/dalle_prompts.json`**, **`flux_raster_prompts.json`**, **`recraft_vector_prompts.json`** — Prompt templates for logo generation, editable without touching PHP.
- **`config/horizon.php`** — Queue worker configuration.

---

## Deployment

The application runs in a Docker container (`compose.yaml`). `docker/supervisord.conf` manages PHP-FPM, the queue workers, and any other long-running processes inside the container.
