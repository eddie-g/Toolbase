# Logo Generator

The Logo Generator at `/logo-generator` lets users create AI-powered logos by describing their brand, choosing a colour palette and style, and selecting an AI model. Results can be raster PNGs or scalable SVG vector files. Generated logos are stored, can be favourited, and select ones are promoted to a public showcase gallery.

---

## Supported AI Models

| Model | Provider | Output |
|---|---|---|
| `dalle3` | OpenAI (DALL-E 3) | Raster PNG |
| `gpt-image-1` | OpenAI (GPT-Image-1) | Raster PNG |
| `flux` | Fal.ai (Flux) | Raster PNG |
| `recraft` / `recraftv3` | Recraft | Raster PNG |
| `recraftv4_vector` | Recraft | SVG (vector) |

Vector output (`recraftv4_vector`) is the default for users who need a scalable, print-ready file. Raster output from DALL-E 3 or GPT-Image-1 tends to produce the highest-quality illustrated or photorealistic logos.

---

## Generation Flow

```
1. User fills in the form: brand name, description, colours, style, model.
2. POST /domain-search/generate-logo  (DomainSearchController@generateLogo)
3. A prompt builder constructs the final API prompt:
     DALL-E  →  DallePromptBuilder
     Flux    →  FluxPromptBuilder
     Recraft →  RecraftPromptBuilder
4. The platform checks the user's credit balance. Insufficient balance = rejected.
5. API call is made to the relevant provider.
6. Image(s) returned → stored under storage/app/public/logos/.
7. AiLogoRequest record created with result_data containing image paths/URLs.
8. Credits deducted and logged in ai_price_log + credit_transactions.
9. Image URLs returned to the client.
```

---

## Prompt Building

Each model has a dedicated prompt builder service that reads its template config from `config/`:

- `config/dalle_prompts.json` → `DallePromptBuilder`
- `config/flux_raster_prompts.json` → `FluxPromptBuilder`
- `config/recraft_raster_prompts.json` and `config/recraft_vector_prompts.json` → `RecraftPromptBuilder`

Templates use placeholder variables — `{subject}`, `{brand}`, `{colors}`, `{bg}`, `{shape_block}`, `{no_text}` — which are substituted at build time. The user's raw description is normalised first: action words ("generate", "create", "design") and generic logo phrases ("a logo for") are stripped so they don't waste prompt context.

Prompt wording can be updated by editing the JSON config files without touching any PHP.

---

## Pricing

Costs are stored in the `ai_rates` and `ai_logo_prices` database tables and can be adjusted without a deploy. Approximate current rates:

| Model | Approximate cost |
|---|---|
| DALL-E 3 / GPT-Image-1 | ~$0.04–$0.08 per image |
| Flux (raster) | ~$0.04 per image |
| Recraft v3/v4 raster | ~$0.04–$0.06 per image |
| Recraft v4 vector (SVG) | ~$0.08 base; ~$0.12 with app markup |

The `RecraftPricing` service handles cost lookup by model key. All deductions are applied after a successful generation — if the API call fails, no credits are consumed.

---

## Output Storage and Multiple Variants

Generated images land in `storage/app/public/logos/`. The `result_data` field on `ai_logo_requests` is a JSON array, one entry per variant (up to 4 depending on the model), each containing the storage path and public URL.

For SVG output, the file is saved locally and its `width`/`height` attributes are normalised to a maximum of `512px` while the `viewBox` is preserved for infinite scalability.

---

## Favourites and Showcase

Users can mark any generated logo as a favourite (`is_favourited = true`) for easy retrieval. Admins can promote a logo to the public `/browse-logos` gallery by setting `is_showcase = true` and optionally specifying which variants to display via `showcase_image_indexes`.

The `/browse-logos` page is served by `BrowseLogosController` and requires no authentication. It paginates all showcase logos.

---

## Saved Colour Palettes

Users can save colour palettes for reuse in future generations. Palettes are stored in `saved_logo_palettes` with a name and a JSON array of hex codes. The logo generator UI loads the user's saved palettes and allows one-click reapplication.

---

## Vector Editor

After generating an SVG logo, users can open it in a built-in vector editor. Editor state is persisted in `vector_editor_states`, which stores the current SVG markup and a JSON state blob (layer order, selected elements, etc.) linked back to the parent `ai_logo_request`.

---

## Logo Generator Settings

Admins can configure generation settings per user (or globally if `user_id` is null) via `logo_generator_settings`. This can override the default model, restrict which models are available to a user, and set a per-day generation cap.
