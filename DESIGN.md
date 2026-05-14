# D Express WooCommerce — Admin UI Design System

> Version 1.0 · 2026-05-13
> This document is the authoritative specification for all admin-side CSS in this plugin. When writing new CSS or refactoring existing CSS, refer here first.

---

## Design Philosophy

The admin UI targets WooCommerce store owners in Serbia — a mix of technical and non-technical users. The design must feel trustworthy and professional without being intimidating. Goals:

- **Clean and light.** White surfaces, generous whitespace, clear hierarchy. No dark theme.
- **Modern SaaS, WP-native feel.** Inspired by interfaces like Stripe Dashboard and Linear — confident typography, structured layout — but using the WordPress font stack so it feels at home in WP admin rather than like a foreign app.
- **Approachable.** Non-technical users should understand at a glance what each page does. Labels, badges, and status indicators must communicate state clearly without requiring domain knowledge.
- **No dashicons.** All icons are inline SVG (`currentColor`, `strokeWidth 1.5`, `24×24` viewBox). This eliminates the dashicons dependency and gives us full control over icon weight and color.
- **Consistent prefix.** Every plugin class starts with `dex-`. No exceptions. This prevents collisions with WordPress core, WooCommerce, and third-party plugins.

---

## Token System

All tokens are CSS custom properties defined on `:root` in `admin.css`. Every CSS file in this plugin consumes these tokens — no hard-coded color, size, or shadow values anywhere except in the token definitions themselves.

### Color Tokens

```css
:root {
  /* Brand */
  --dex-red:          #E31E25;   /* Primary — D-Express brand red */
  --dex-red-hover:    #C41920;   /* Darker red for hover/active states */
  --dex-red-light:    #FFF0F0;   /* Tinted red background (badges, highlights) */

  /* Semantic — Success */
  --dex-green:        #1A9E5C;
  --dex-green-light:  #EDF7F2;

  /* Semantic — Warning */
  --dex-amber:        #D97706;
  --dex-amber-light:  #FFFBEB;

  /* Semantic — Info */
  --dex-blue:         #1D6FA4;
  --dex-blue-light:   #EFF6FF;

  /* Neutral scale */
  --dex-gray-50:      #F9FAFB;
  --dex-gray-100:     #F3F4F6;
  --dex-gray-200:     #E5E7EB;
  --dex-gray-300:     #D1D5DB;
  --dex-gray-500:     #6B7280;
  --dex-gray-700:     #374151;
  --dex-gray-900:     #111827;
  --dex-white:        #FFFFFF;

  /* Surface & text aliases (use these in components, not the raw scale) */
  --dex-bg:           #F9FAFB;   /* Page background */
  --dex-border:       #E5E7EB;   /* Default border color */
  --dex-text:         #111827;   /* Primary text */
  --dex-text-muted:   #6B7280;   /* Secondary / label text */
}
```

**Semantic intent:**
| Token | Used for |
|---|---|
| `--dex-red` | Primary CTAs, active states, brand accents |
| `--dex-red-light` | Button danger hover bg, highlight cards, badge bg |
| `--dex-green` | Success states, "sent" status, positive counts |
| `--dex-amber` | Warnings, "pending" status, non-critical alerts |
| `--dex-blue` | Informational text, links, info badges |
| `--dex-gray-50` | Alternate row bg, panel bg, card header bg |
| `--dex-gray-100` | Table header bg, input track bg, stepper inactive |
| `--dex-gray-200` | Borders, dividers, inactive connectors |
| `--dex-gray-500` | Label text, placeholder text, secondary icons |
| `--dex-gray-700` | Body text, table cell text |
| `--dex-gray-900` | Headings, strong values, active text |

---

### Typography Tokens

```css
:root {
  --dex-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans,
              Ubuntu, Cantarell, "Helvetica Neue", sans-serif;

  --dex-text-xs:   11px;
  --dex-text-sm:   12px;
  --dex-text-base: 14px;   /* Default body size in WP admin */
  --dex-text-lg:   16px;
  --dex-text-xl:   20px;
  --dex-text-2xl:  24px;
}
```

**Size usage guide:**
| Token | Used for |
|---|---|
| `--dex-text-xs` | Badge text, table header labels (ALL CAPS), meta info |
| `--dex-text-sm` | Secondary labels, field hints, timestamps |
| `--dex-text-base` | Body text, form inputs, table cells |
| `--dex-text-lg` | Card titles, modal headings |
| `--dex-text-xl` | Page titles, section headings |
| `--dex-text-2xl` | KPI/stat numbers |

**No Google Fonts.** No external font requests. The WP font stack renders well on all operating systems and loads instantly.

---

### Spacing Tokens

4px base grid. All margins, paddings, and gaps must use a token — never raw pixel values in component CSS.

```css
:root {
  --dex-space-1:  4px;
  --dex-space-2:  8px;
  --dex-space-3:  12px;
  --dex-space-4:  16px;
  --dex-space-5:  20px;
  --dex-space-6:  24px;
  --dex-space-8:  32px;
  --dex-space-10: 40px;
  --dex-space-12: 48px;
}
```

---

### Border & Radius Tokens

```css
:root {
  --dex-radius-sm: 4px;
  --dex-radius:    8px;
  --dex-radius-lg: 12px;
  --dex-radius-xl: 16px;

  --dex-border:       1px solid #E5E7EB;   /* Full shorthand — use in border: declarations */
  --dex-border-color: #E5E7EB;             /* Color only — use in border-color: declarations */
}
```

**Radius usage guide:**
| Token | Used for |
|---|---|
| `--dex-radius-sm` | Badges, pills, small inputs, table overflow clips |
| `--dex-radius` | Buttons, inputs, cards, dropdowns |
| `--dex-radius-lg` | Modal dialogs, larger cards, profile cards |
| `--dex-radius-xl` | Full-page centered panels (onboarding) |

---

### Shadow Tokens

```css
:root {
  --dex-shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.05);
  --dex-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
  --dex-shadow:    0 4px 6px rgba(0, 0, 0, 0.05), 0 2px 4px rgba(0, 0, 0, 0.04);
  --dex-shadow-lg: 0 10px 24px rgba(0, 0, 0, 0.08);
}
```

**Shadow usage guide:**
| Token | Used for |
|---|---|
| `--dex-shadow-xs` | Subtle depth on inputs, tight list items |
| `--dex-shadow-sm` | Cards, stat tiles, profile cards |
| `--dex-shadow` | Floating dropdowns, autocomplete lists |
| `--dex-shadow-lg` | Modals, dialogs, overlays |

---

### Transition Tokens

```css
:root {
  --dex-transition:      150ms ease;   /* Micro interactions: hover, focus, toggle */
  --dex-transition-slow: 250ms ease;   /* Larger state changes: panels sliding, progress bar */
}
```

---

## Naming Convention

**Prefix:** `dex-` on every class without exception.

**Methodology:** BEM — `.dex-{block}__{element}--{modifier}`

Rules:
- Block names are short nouns: `card`, `btn`, `badge`, `modal`, `table`, `field`, `stepper`
- Element names describe the part's role: `header`, `title`, `body`, `footer`, `icon`, `label`
- Modifier names describe state or variant: `primary`, `secondary`, `success`, `error`, `open`, `active`, `disabled`
- State classes (toggled by JS) use `is-` prefix: `is-open`, `is-active`, `is-loading`, `is-error`, `is-selected`
- JS hook attributes use `data-dex-*` — never target bare CSS class names from JS for behavioural hooks

**Examples:**

```
.dex-card
.dex-card__header
.dex-card__title
.dex-card__body
.dex-card--highlight

.dex-badge
.dex-badge--success
.dex-badge--error
.dex-badge--warning
.dex-badge--info
.dex-badge--neutral

.dex-btn
.dex-btn--primary
.dex-btn--secondary
.dex-btn--danger
.dex-btn--sm
.dex-btn--lg

.dex-table
.dex-table__header
.dex-table__row
.dex-table__row--error

.dex-modal
.dex-modal__backdrop
.dex-modal__dialog
.dex-modal__header
.dex-modal__body
.dex-modal__footer
.dex-modal__close
```

---

## SVG Icon Set

All icons are inline SVG, `24×24` viewBox, `currentColor` stroke, `strokeWidth="1.5"`, `fill="none"`, `stroke-linecap="round"`, `stroke-linejoin="round"`. Size is controlled by CSS `width`/`height` on the `<svg>` element, not by attributes.

Icons must be defined once in a PHP helper or template partial and referenced by name — no copy-pasting SVG paths into multiple templates.

| Icon name | Used in |
|---|---|
| `package` | Shipment count, package cards, metabox header |
| `truck` | Dashboard KPI, bulk shipment send action |
| `location-pin` | Package shop destination, sender location |
| `clock` | Pending/awaiting status, timestamps |
| `check-circle` | Success state, sent status, completed steps |
| `x-circle` | Error state, failed status |
| `warning-triangle` | Warning notices, paketomat constraints |
| `arrow-right` | Quick actions nav, step navigation |
| `chevron-down` | Dropdowns, expandable sections |
| `eye` | Password visibility toggle (show) |
| `eye-off` | Password visibility toggle (hide) |
| `copy` | Copy-to-clipboard buttons |
| `refresh` | Sync/reload actions, retry buttons |
| `plus` | Add package, add profile, add location |
| `trash` | Delete actions |
| `edit-pencil` | Edit actions |
| `send` | Send to D-Express (primary metabox action) |
| `label` / `tag` | Tracking code, TT number display |
| `settings-gear` | Settings links, configuration |
| `chart-bar` | Dashboard, shipment analytics |
| `user` | Customer name, recipient |
| `phone` | Phone number fields |
| `building` | Sender location, company |

**Sizing convention:**
- Inline with text: `width: 14px; height: 14px` (or `--dex-text-base`)
- Button icon: `width: 16px; height: 16px`
- Card/panel header icon: `width: 18px; height: 18px`
- Stat card icon: `width: 20px; height: 20px`
- Empty state illustration: `width: 64px; height: 64px`

---

## Shared Components

All shared components are defined in `admin.css` (together with the token `:root` block). Page-specific CSS files only contain components and layouts unique to that page — they never redefine a shared component.

---

### 1. Page Header — `.dex-page-header`

**Purpose:** Top of every plugin admin page. Consistent landmark.

**Visual spec:**
- White background, `1px solid var(--dex-border-color)` bottom border
- `var(--dex-space-4)` padding top/bottom, `0` padding left/right (inherits WP `.wrap` horizontal padding)
- Flex row, `space-between` alignment, `var(--dex-space-4)` gap
- **Left side:** SVG icon (20×20, `--dex-red`) + page title (`--dex-text-xl`, `font-weight: 700`, `--dex-text`) + optional subtitle beneath title (`--dex-text-sm`, `--dex-text-muted`)
- **Right side:** Primary action button (optional) — `.dex-btn--primary`
- No bottom margin on the header itself — the page content below provides its own top spacing

---

### 2. Card — `.dex-card`

**Purpose:** Primary surface container. Used for settings sections, list panels, stat groupings.

**Visual spec:**
- Background: `--dex-white`
- Border: `var(--dex-border)`
- Border-radius: `var(--dex-radius)` (8px)
- Shadow: `var(--dex-shadow-sm)`
- No padding on the card itself — padding lives in `__header`, `__body`, `__footer`

**Elements:**
- `.dex-card__header` — `var(--dex-space-3)` vertical, `var(--dex-space-4)` horizontal; `background: var(--dex-gray-50)`; `border-bottom: var(--dex-border)`; flex row for title + optional right action
- `.dex-card__title` — `--dex-text-sm`, `font-weight: 700`, `--dex-text-muted`, `text-transform: uppercase`, `letter-spacing: 0.06em` — section label style
- `.dex-card__body` — `var(--dex-space-4)` padding all sides
- `.dex-card__footer` — `var(--dex-space-3)` vertical, `var(--dex-space-4)` horizontal; `background: var(--dex-gray-50)`; `border-top: var(--dex-border)`; flex row for actions

**Modifiers:**
- `.dex-card--highlight` — `border-color: var(--dex-blue)`; `box-shadow: 0 0 0 1px var(--dex-blue), var(--dex-shadow-sm)` — used to draw attention to a key settings section

---

### 3. Stat Card — `.dex-stat-card`

**Purpose:** Dashboard KPI tiles. Shows a large number with a label. Used in a 4-column grid.

**Visual spec:**
- Extends `.dex-card` (same bg, border, radius, shadow)
- Internal layout: flex row, `var(--dex-space-3)` gap, vertically centered
- **Icon bubble** (`.dex-stat-card__icon`): `44×44px` square, `var(--dex-radius)` radius, tinted background at 8% opacity of the semantic color; SVG icon 20×20 centered inside
- **Body** (`.dex-stat-card__body`): flex column
  - **Value** (`.dex-stat-card__value`): `--dex-text-2xl`, `font-weight: 700`, `line-height: 1`, `--dex-text`, tabular-nums
  - **Label** (`.dex-stat-card__label`): `--dex-text-xs`, `font-weight: 700`, `--dex-text-muted`, `text-transform: uppercase`, `letter-spacing: 0.06em`

**Modifiers on `.dex-stat-card__value`:**
- `--success` → `color: var(--dex-green)`
- `--warning` → `color: var(--dex-amber)`
- `--error` → `color: var(--dex-red)`
- `--info` → `color: var(--dex-blue)`

**Icon bubble modifiers on `.dex-stat-card__icon`:**
- `--red` → `background: rgba(227,30,37,0.08)`, icon `color: var(--dex-red)`
- `--green` → `background: rgba(26,158,92,0.08)`, icon `color: var(--dex-green)`
- `--blue` → `background: rgba(29,111,164,0.08)`, icon `color: var(--dex-blue)`
- `--amber` → `background: rgba(217,119,6,0.08)`, icon `color: var(--dex-amber)`

---

### 4. Button — `.dex-btn`

**Purpose:** All interactive triggers. Replaces WordPress `.button`, `.button-primary`, `.button-link-delete` within plugin UI.

**Base spec:**
- `display: inline-flex; align-items: center; justify-content: center; gap: var(--dex-space-2)`
- Height: `32px` (standard), `26px` (small `.dex-btn--sm`), `40px` (large `.dex-btn--lg`)
- Padding: `0 var(--dex-space-4)` (standard), `0 var(--dex-space-3)` (small)
- Font: `var(--dex-font)`, `var(--dex-text-sm)`, `font-weight: 600`
- Border-radius: `var(--dex-radius)`
- Transition: `background var(--dex-transition), border-color var(--dex-transition), box-shadow var(--dex-transition), color var(--dex-transition)`
- Cursor: `pointer`
- White-space: `nowrap`
- SVG icon inside: `16×16px`

**`.dex-btn--primary`**
- Background: `var(--dex-red)`
- Border: `1px solid var(--dex-red-hover)`
- Color: `var(--dex-white)`
- Hover: background `var(--dex-red-hover)`, `box-shadow: 0 2px 8px rgba(227,30,37,0.28)`
- Disabled: `opacity: 0.5; cursor: not-allowed`

**`.dex-btn--secondary`**
- Background: `var(--dex-white)`
- Border: `var(--dex-border)`
- Color: `var(--dex-gray-700)`
- Hover: `background: var(--dex-gray-50)`, `border-color: var(--dex-gray-300)`
- Disabled: `opacity: 0.5; cursor: not-allowed`

**`.dex-btn--danger`**
- Background: `transparent`
- Border: `1px solid var(--dex-red)`
- Color: `var(--dex-red)`
- Hover: `background: var(--dex-red-light)`
- Used for destructive actions (delete, purge) — distinct from primary so users pause before clicking

**`.dex-btn--ghost`**
- Background: `transparent`
- Border: `none`
- Color: `var(--dex-gray-500)`
- Hover: `color: var(--dex-gray-900); background: var(--dex-gray-100)`
- Used for close buttons, low-priority icon actions

**Loading state** (`.is-loading`): shows a CSS spinner (16px rotating circle) in place of or alongside the label; button remains `disabled`

---

### 5. Badge — `.dex-badge`

**Purpose:** Inline status indicators. Pill shape, small text. Used in tables, card headers, order rows.

**Base spec:**
- `display: inline-flex; align-items: center; gap: var(--dex-space-1)`
- Padding: `2px var(--dex-space-2)`
- Border-radius: `var(--dex-radius-sm)` (4px) — slightly rectangular, not a full pill
- Font: `--dex-text-xs`, `font-weight: 700`, `text-transform: uppercase`, `letter-spacing: 0.05em`
- `white-space: nowrap`

**Variants:**
| Modifier | Background | Text color | Border |
|---|---|---|---|
| `--success` | `--dex-green-light` | `--dex-green` | `1px solid rgba(26,158,92,0.2)` |
| `--error` | `--dex-red-light` | `--dex-red` | `1px solid rgba(227,30,37,0.2)` |
| `--warning` | `--dex-amber-light` | `--dex-amber` | `1px solid rgba(217,119,6,0.2)` |
| `--info` | `--dex-blue-light` | `--dex-blue` | `1px solid rgba(29,111,164,0.2)` |
| `--neutral` | `--dex-gray-100` | `--dex-gray-500` | `1px solid var(--dex-gray-200)` |

Optional dot (`.dex-badge__dot`): `6px` circle, `background: currentColor`, used to signal live status alongside text.

---

### 6. Status Dot — `.dex-status-dot`

**Purpose:** Standalone colored dot — simpler than a badge, used in dense list rows or next to short labels.

**Spec:**
- `display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0`
- Colors map directly to badge variants: `--ok` / `--warn` / `--error` / `--info` / `--neutral`
- Pulsing animation (`animation: dex-pulse 2.4s ease infinite`) on `--ok` to indicate live/active state

---

### 7. Table — `.dex-table`

**Purpose:** Shipments list, sync status rows, item allocation, bulk shipment order rows.

**Spec:**
- `width: 100%; border-collapse: collapse; font-size: var(--dex-text-sm)`
- No outer border on the `<table>` element — the containing `.dex-card` provides the border
- **Header row** (`thead th`): `background: var(--dex-gray-50)`, `border-bottom: var(--dex-border)`, `--dex-text-xs`, `font-weight: 700`, `--dex-text-muted`, `text-transform: uppercase`, `letter-spacing: 0.06em`, `padding: var(--dex-space-2) var(--dex-space-3)`, `white-space: nowrap`
- **Body cells** (`tbody td`): `padding: var(--dex-space-2) var(--dex-space-3)`, `border-bottom: var(--dex-border)`, `--dex-text-base`, `--dex-gray-700`, `vertical-align: middle`
- Last row has no bottom border (`tr:last-child td { border-bottom: 0 }`)
- **Row hover**: `tbody tr:hover td { background: var(--dex-gray-50) }`
- **Error row** (`.dex-table__row--error`): `background: var(--dex-red-light) !important`, border-color `var(--dex-red)`
- Responsive: wrap in `.dex-table-wrap { overflow-x: auto }` for horizontal scroll on small screens

---

### 8. Modal — `.dex-modal`

**Purpose:** Add/edit location, add/edit package profile, any confirmation dialogs.

**Spec:**
- Root element: `position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center`
- Hidden by default via `[hidden]` or `display: none`; toggled to `display: flex` with `.is-open`
- `body.dex-modal-open { overflow: hidden }` to prevent background scroll
- **Backdrop** (`.dex-modal__backdrop`): `position: absolute; inset: 0; background: rgba(0,0,0,0.45)`
- **Dialog** (`.dex-modal__dialog`): `position: relative; width: min(600px, calc(100vw - 32px)); max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: var(--dex-white); border-radius: var(--dex-radius-lg); box-shadow: var(--dex-shadow-lg); overflow: hidden`
- **Header** (`.dex-modal__header`): `flex-shrink: 0; padding: var(--dex-space-4) var(--dex-space-6); border-bottom: var(--dex-border); border-top: 3px solid var(--dex-red); border-radius: var(--dex-radius-lg) var(--dex-radius-lg) 0 0; display: flex; align-items: center; justify-content: space-between; gap: var(--dex-space-3); background: var(--dex-white)`
  - **Title** (`.dex-modal__title`): `--dex-text-lg`, `font-weight: 700`, `--dex-text`, `margin: 0`
  - **Close button** (`.dex-modal__close`): `.dex-btn--ghost`, 28×28px, `×` character or `x-circle` SVG
- **Body** (`.dex-modal__body`): `flex: 1; overflow-y: auto; padding: var(--dex-space-6)`
- **Footer** (`.dex-modal__footer`): `flex-shrink: 0; padding: var(--dex-space-4) var(--dex-space-6); border-top: var(--dex-border); background: var(--dex-gray-50); display: flex; align-items: center; gap: var(--dex-space-3)`

**Entrance animation:** `opacity` from `0` → `1` with `var(--dex-transition-slow)` when `.is-open` is added.

---

### 9. Form Field — `.dex-field`

**Purpose:** Consistent label + input pairing everywhere a user enters data (settings, modal forms, metabox).

**Spec:**
- Container: `display: flex; flex-direction: column; gap: var(--dex-space-1)`
- **Label** (`.dex-field__label`): `display: block; font-size: var(--dex-text-sm); font-weight: 600; color: var(--dex-gray-700)`
  - Required marker (`.dex-field__required`): `color: var(--dex-red); margin-left: 2px`
- **Input / Select / Textarea**: `height: 36px; border: var(--dex-border); border-radius: var(--dex-radius); font: var(--dex-text-base) var(--dex-font); color: var(--dex-text); background: var(--dex-white); padding: 0 var(--dex-space-3); transition: border-color var(--dex-transition), box-shadow var(--dex-transition)`
  - Focus: `border-color: var(--dex-red); box-shadow: 0 0 0 3px rgba(227,30,37,0.12); outline: none`
  - Disabled/readonly: `background: var(--dex-gray-50); color: var(--dex-gray-500); cursor: not-allowed`
  - `<textarea>`: `height: auto; min-height: 80px; resize: vertical; padding: var(--dex-space-2) var(--dex-space-3)`
- **Hint text** (`.dex-field__hint`): `font-size: var(--dex-text-xs); color: var(--dex-text-muted); margin-top: var(--dex-space-1)`
- **Inline error** (`.dex-field__error`): `font-size: var(--dex-text-xs); color: var(--dex-red); margin-top: var(--dex-space-1); display: none`; show with `.is-visible`
- **Error state**: add `.is-error` to container → `border-color: var(--dex-red)` on input, show `.dex-field__error`

---

### 10. Inline Result — `.dex-inline-result`

**Purpose:** Single-line feedback text that appears inline after AJAX actions (test connection, sync, save).

**Spec:**
- `display: inline-block; font-size: var(--dex-text-sm); font-weight: 600; margin-left: var(--dex-space-3)`
- `--success`: `color: var(--dex-green)`
- `--error`: `color: var(--dex-red)`
- Typically appears immediately after the triggering button in the DOM

---

### 11. Tab Bar — `.dex-tabs`

**Purpose:** Settings page tabs (API, Shipment, Webhook, Sender Locations, Checkout, Email, Logging, Šifarnici, Simulation).

**Spec:**
- Container (`.dex-tabs`): flex row, `border-bottom: var(--dex-border)`, `gap: 0`, `background: var(--dex-white)`, `margin-bottom: 0`
- **Tab item** (`.dex-tabs__item`): `display: inline-flex; align-items: center; gap: var(--dex-space-1); padding: var(--dex-space-3) var(--dex-space-4); font-size: var(--dex-text-sm); font-weight: 600; color: var(--dex-text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; text-decoration: none; transition: color var(--dex-transition), border-color var(--dex-transition), background var(--dex-transition)`
  - Hover: `color: var(--dex-text); background: var(--dex-gray-50)`
  - Active (`.is-active`): `color: var(--dex-red); border-bottom-color: var(--dex-red); background: var(--dex-white)`
- Tab content panel (`.dex-tabs__panel`): `background: var(--dex-white); border: var(--dex-border); border-top: none; padding: var(--dex-space-5)`

---

### 12. Progress Bar — `.dex-progress`

**Purpose:** Bulk shipment wizard (Step 3 send phase), onboarding completion.

**Spec:**
- Track (`.dex-progress`): `height: 6px; background: var(--dex-gray-200); border-radius: 99px; overflow: hidden`
- Fill (`.dex-progress__fill`): `height: 100%; background: var(--dex-red); border-radius: 99px; transition: width var(--dex-transition-slow)`
- Width driven by inline `style="width: X%"` set by JS

---

### 13. Stepper — `.dex-stepper`

**Purpose:** Bulk shipment 4-step wizard, onboarding step indicator.

**Spec:**
- Container: `display: flex; align-items: center`
- **Step** (`.dex-stepper__step`): `display: flex; align-items: center; gap: var(--dex-space-2); opacity: 0.45; transition: opacity var(--dex-transition)`
  - Active (`--active`): `opacity: 1`
  - Done (`--done`): `opacity: 0.75`
- **Step number circle** (`.dex-stepper__num`): `width: 28px; height: 28px; border-radius: 50%; background: var(--dex-gray-200); color: var(--dex-gray-700); display: flex; align-items: center; justify-content: center; font-size: var(--dex-text-sm); font-weight: 700`
  - Active: `background: var(--dex-red); color: var(--dex-white)`
  - Done: `background: var(--dex-green); color: var(--dex-white)` (optionally replace number with check SVG)
- **Step label** (`.dex-stepper__label`): `font-size: var(--dex-text-sm); font-weight: 600; color: var(--dex-gray-700); white-space: nowrap`
- **Connector line** (`.dex-stepper__line`): `flex: 1; height: 2px; background: var(--dex-gray-200); margin: 0 var(--dex-space-3)`

---

### 14. Empty State — `.dex-empty`

**Purpose:** Package profiles list when empty, shipments list when no results, locations list when none added.

**Spec:**
- Container: `display: flex; flex-direction: column; align-items: center; text-align: center; padding: var(--dex-space-12) var(--dex-space-6)`
- **Icon / Illustration slot** (`.dex-empty__icon`): `width: 64px; height: 64px; margin-bottom: var(--dex-space-4); color: var(--dex-gray-300)` — SVG icon at this size
- **Title** (`.dex-empty__title`): `font-size: var(--dex-text-xl); font-weight: 700; color: var(--dex-text); margin: 0 0 var(--dex-space-2); letter-spacing: -0.01em`
- **Description** (`.dex-empty__desc`): `font-size: var(--dex-text-base); color: var(--dex-text-muted); max-width: 360px; margin: 0 0 var(--dex-space-6); line-height: 1.65`
- **CTA** (optional): `.dex-btn--primary`

---

### 15. Alert / Notice — `.dex-notice`

**Purpose:** Informational blocks within page content (not WP admin notices). Used for webhook IP warning, API range warnings, constraint notices.

**Spec:**
- `display: flex; gap: var(--dex-space-3); padding: var(--dex-space-3) var(--dex-space-4); border-radius: var(--dex-radius); border-left: 4px solid`
- SVG icon (16×16) on the left, content (title + optional body text) on the right
- **`--info`**: `background: var(--dex-blue-light)`, `border-color: var(--dex-blue)`, icon/title `color: var(--dex-blue)`
- **`--success`**: `background: var(--dex-green-light)`, `border-color: var(--dex-green)`, icon/title `color: var(--dex-green)`
- **`--warning`**: `background: var(--dex-amber-light)`, `border-color: var(--dex-amber)`, icon/title `color: var(--dex-amber)`
- **`--error`**: `background: var(--dex-red-light)`, `border-color: var(--dex-red)`, icon/title `color: var(--dex-red)`
- Title (`.dex-notice__title`): `font-size: var(--dex-text-sm); font-weight: 700; margin: 0 0 4px`
- Body (`.dex-notice__body`): `font-size: var(--dex-text-sm); color: var(--dex-gray-700); margin: 0`

---

### 16. Autocomplete Dropdown — `.dex-dropdown`

**Purpose:** Town and street field autocomplete (admin sender location modal, checkout).

**Spec:**
- `position: absolute; top: 100%; left: 0; right: 0; z-index: 9999; background: var(--dex-white); border: var(--dex-border); border-top: none; border-radius: 0 0 var(--dex-radius) var(--dex-radius); max-height: 240px; overflow-y: auto; box-shadow: var(--dex-shadow)`
- Matches the width of the triggering input
- **Item** (`.dex-dropdown__item`): `padding: var(--dex-space-2) var(--dex-space-3); font-size: var(--dex-text-base); color: var(--dex-gray-700); cursor: pointer; border-bottom: 1px solid var(--dex-gray-100); transition: background var(--dex-transition), color var(--dex-transition)`
  - Hover/focus: `background: var(--dex-blue-light); color: var(--dex-blue)`
  - Last item: no border-bottom
- **Empty state item** (`.dex-dropdown__empty`): `padding: var(--dex-space-2) var(--dex-space-3); font-size: var(--dex-text-sm); color: var(--dex-text-muted); font-style: italic; cursor: default`
- Hidden by default (`display: none`), shown by JS when results exist

---

### 17. Copy Field — `.dex-copy-field`

**Purpose:** Webhook URL display (Settings → Webhook tab).

**Spec:**
- Flex row with `gap: var(--dex-space-2)`, `align-items: center`
- **Code element** (`.dex-copy-field__code`): `display: inline-block; padding: var(--dex-space-1) var(--dex-space-3); background: var(--dex-gray-50); border: var(--dex-border); border-radius: var(--dex-radius); font-family: ui-monospace, "SFMono-Regular", Consolas, monospace; font-size: var(--dex-text-sm); color: var(--dex-text); word-break: break-all`
- **Copy button** (`.dex-copy-field__btn`): `.dex-btn--secondary` at small size, `copy` SVG icon, changes to `check` SVG on success for 1.5s

---

### 18. Sync Row — `.dex-sync-row`

**Purpose:** Šifarnici tab — one row per data type (towns, streets, dispensers, etc.) showing last sync date and a manual trigger button.

**Spec:**
- `display: flex; align-items: center; gap: var(--dex-space-3); padding: var(--dex-space-2) 0; border-bottom: var(--dex-border)`
- Last row: no border-bottom
- **Label** (`.dex-sync-row__label`): `flex: 1; font-size: var(--dex-text-base); color: var(--dex-text)`
- **Status** (`.dex-sync-row__status`): status dot + date text in `--dex-text-muted`, `--dex-text-xs`
- **Action** (`.dex-sync-row__action`): `.dex-btn--secondary` small, `refresh` SVG icon
- **Result** (`.dex-sync-row__result`): `.dex-inline-result`, shown after AJAX completes

---

## Page-by-Page Notes

### `admin.css`

Contains:
1. The full `:root` token block (all tokens above)
2. All 18 shared components above
3. Utility classes: `.dex-sr-only` (screen reader only), `.dex-monospace`, `.dex-text-muted`, `.dex-text-danger`
4. WordPress `.form-table` overrides scoped to `.dex-settings-wrap` — `th` gets `--dex-text-xs` uppercase label treatment, `td` gets `--dex-text-base`

Does NOT contain any page-specific layout.

---

### `admin-dashboard.css`

Unique components not in `admin.css`:

**KPI grid layout** (`.dex-kpi-grid`): `display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: var(--dex-space-5)` — uses `.dex-stat-card` tiles. Responsive: 2 columns below 1280px, 1 column below 600px.

**Body grid** (`.dex-db-body`): Two-column `1fr 300px` — main content (recent shipments + chart) left, quick actions + system info right. Single column below 960px.

**Bar chart** (`.dex-db-chart`): Flex row of columns, each with a `<div>` bar whose height is set by a `--bar-h` CSS custom property from PHP. Bars use `var(--dex-red)` gradient. Zero bars use `var(--dex-gray-200)`. Count label floats above the bar; day label below. 160px total chart height.

**Quick actions list** (`.dex-db-actions`): Full-width list of nav links inside a `.dex-card`. Each item (`.dex-db-actions__item`): flex row with a tinted icon bubble, label, and chevron-right. Hover: `background: var(--dex-gray-50)`, chevron shifts `translateX(2px)`.

**System info table** (`.dex-db-sysinfo`): Compact `dt`/`dd` grid inside a `.dex-card`. Labels are `--dex-text-xs` uppercase, values are `--dex-text-sm`.

---

### `admin-metabox.css`

The metabox has three mutually exclusive states — each rendered in PHP, shown/hidden by JS via the `[data-state]` attribute on `#dex-shipment-root`.

**State A — Wizard** (`data-state="wizard"`):
- 3-column step indicator at top using `.dex-stepper` (compact variant)
- Package cards grid: `repeat(auto-fill, minmax(200px, 1fr))` — collapses to 1 column in sidebar
- Package card (`.dex-pkg-card`): `.dex-card` variant with `package` SVG in header, mass + dimension inputs in body
- Dimension inputs: 3-column grid (`dx`, `dy`, `dz`), `width: 64px` each
- Package shop destination notice: left-bordered `notice--info` with `location-pin` SVG before text
- Navigation: flex row, `send` button primary, back/next secondary

**State B — Pending send** (`data-state="pending_send"`):
- Warning banner: `.dex-notice--warning` explaining the shipment is saved but not yet sent to D-Express
- "Pošalji u D-Express" button: `.dex-btn--primary` full-width or prominent, `send` SVG icon

**State C — Created** (`data-state="sent"`):
- Success banner: `check-circle` SVG, green background strip
- Sent details layout: `1fr 1fr` grid of party info blocks (sender / recipient), packages list below
- Status timeline (`.dex-status-timeline`): vertical list with connector lines and dots. Dot states: default gray, `.is-completed` green with check, `.is-current` red with pulse ring
- Actions row: reprint, edit (secondary), copy tracking code (ghost + copy SVG)

**Package shop info metabox** (separate metabox): simple `.dex-card` with location icon, `<dl>` of location details.

---

### `admin-bulk-shipment.css`

Unique components:

**Stepper**: 4-step variant of `.dex-stepper`, max-width 640px, centered.

**Step panels**: each step (`#dex-bulk-step1` through `#dex-bulk-step4`) is a `div[hidden]` shown by JS. No extra card wrapping needed — steps live inside the page container.

**Profile cards row**: horizontal scrollable flex row of `.dex-profile-card` tiles (compact `.dex-card` variant without header, selectable with `.is-active` → `border-color: var(--dex-red); background: var(--dex-red-light)`).

**Defaults card**: `.dex-card` containing the bulk defaults form (location, payment type, etc.) as a `form-table`.

**Orders table** (Step 2): `.dex-table` with additional per-row inline inputs. Fixed column widths: order (200px), weight (110px), dims (200px), content + note fluid. Dim inputs `50px` each inside a flex cell with `×` separators.

**Status badges in results table**: `.dex-badge` variants used inline — `--neutral` (pending), `--info` (saving/sending), `--success` (saved/sent), `--error`. Error rows get `.dex-table__row--error` (red-light background).

**Summary card** (Step 4): `.dex-card` with colored header (`--success` green / `--partial` amber), stats row of `.dex-badge` counts, tracking codes `<textarea>` in monospace, and action buttons.

---

### `admin-package-profiles.css`

Unique components:

**Two-column layout**: main card grid (left, `flex: 1`) + sidebar rules panel (right, `340px`). Single column below 1200px.

**Profile card grid**: `repeat(auto-fill, minmax(220px, 1fr))`. Each `.dex-pp-card` is a `.dex-card` with a 3px red gradient accent top stripe (via `::before`), icon bubble, name, `<dl>` meta grid, description, and action bar at bottom.

**Add-new card** (`.dex-pp-card--add`): dashed border, centered `+` icon and label, fills to same height as other cards. Hover turns dashed border red.

**Modal form**: `.dex-modal`, 2-column grid inside body (`1fr 1fr`), dimensions row as flex with `÷` separators and individual axis labels.

**Sidebar rules card**: tabbed panel (Standard / Paketomat), each tab has a `<dl>` of constraints. Tab bar underline style, matching `.dex-tabs` pattern.

---

### `admin-onboarding.css`

Unique components:

**Full-page centered layout** (`.dex-ob-wrap`): `max-width: 760px; margin: var(--dex-space-8) auto`. No WP sidebar influence — this page hides the sidebar.

**Header** (`.dex-ob-header`): centered, D-Express logo SVG (48px), `h1`, subtitle.

**Progress bar**: `.dex-progress` at 4px height (thinner variant).

**Step dots nav** (`.dex-ob-steps-nav`): horizontal flex of step items, each with a numbered circle + label below. Uses `.dex-stepper__num` styling but laid out vertically per dot.

**Main card** (`.dex-ob-card`): `.dex-card` with `--dex-radius-xl`, stronger `--dex-shadow-lg`.

**Step panels**: each panel is `padding: var(--dex-space-6)`. Panel icon: 48px bubble with `var(--dex-red-light)` bg.

**Feature list**: `ul` with check-circle SVG before each item, `--dex-green` icon color.

**Method selection** (Step 5): list of selectable shipping method rows, each a card with a custom checkbox. Row hover → red border. `:has(input:checked)` highlights the selected row.

**Navigation bar**: flex row at panel bottom, `border-top: var(--dex-border)`. "Back" is `.dex-btn--secondary`, "Next"/"Finish" is `.dex-btn--primary`.

---

### `admin-diagnostics.css`

**Light theme.** Uses the standard `--dex-*` tokens from `admin.css`. No dark backgrounds, no external fonts.

Terminal aesthetic is achieved through:
- Monospace font (use `ui-monospace, "SFMono-Regular", Consolas, monospace` — already available, no import)
- Green text `--dex-green` on log textarea
- Dark `#050710` background only inside the log `<textarea>` itself — everything outside is light
- Row entrance animations (fade + slide) remain

Unique components:

**Hero panel** (`.dex-diag-hero`): `.dex-card` with `--dex-radius-lg`, larger padding, page title in display font (Consolas fallback), subtitle, stats bar. Red top accent bar via `::after`. No dark background.

**Stats bar** (`.dex-diag-stats`): Flex row of stat items, dividers between them. Numbers in large display size, `--dex-text-2xl`. Semantic colors for OK/warn states.

**Status rows** (`.dex-diag-row`): Flex row with status dot, row label, value, optional action button. Entrance animation: fade in + slide from left, staggered by `nth-child`. Dots pulse on `is-ok`.

**API panel**: `.dex-card` with description text and a `.dex-btn--primary` test connection button.

**Log textarea** (`.dex-diag-log__textarea`): `background: #050710; color: var(--dex-green); font-family: ui-monospace, ...; font-size: 10.5px; border: none; border-top: var(--dex-border)`. This is the only element with a dark background on the page.

**Scanline sweep**: CSS `::after` animation on the log container, subtle green gradient moving top-to-bottom. Purely decorative.

---

### `admin-shipments.css`

**DELETE this file.** It is empty. The Pošiljke page uses WordPress core `WP_List_Table` classes entirely and requires no plugin CSS. Remove the file and remove its enqueue registration from `AdminMenu.php`.

---

## Migration / Refactor Notes

These decisions are made — apply them during the CSS refactor:

### 1. Token consolidation
`admin-design-system.css` is merged into `admin.css`. Only one file registers the `:root` token block. No other file defines `:root` tokens. `admin-diagnostics.css` drops its own `--term-*` / `--dx-*` token block and consumes `--dex-*` tokens directly.

### 2. Prefix migration
All `dexpress-` prefixed classes (legacy) become `dex-` prefixed. This is a mechanical find-and-replace across PHP templates and JS files — do it in a single pass per file to avoid half-migrated states.

The mapping is straightforward:
- `dexpress-modal` → `dex-modal`
- `dexpress-inline-result` → `dex-inline-result`
- `dexpress-badge` → `dex-badge`
- `dexpress-field-error` → `dex-field__error`
- etc.

JS selectors in `admin-settings.js` and `admin-metabox.js` must be updated simultaneously.

### 3. Remove duplicated password-toggle rules
`admin-onboarding.css` duplicates `.dexpress-password-wrap` and `.dexpress-pw-toggle` from `admin.css`. After merging into `.dex-field` (password variant) in `admin.css`, remove the duplicate block from `admin-onboarding.css`.

### 4. Remove Google Fonts import
Delete the `@import url('https://fonts.googleapis.com/...')` line from `admin-diagnostics.css`. Replace `'Bebas Neue'` with `Impact, Arial Narrow, sans-serif` for the hero title (display font fallback). Replace `'JetBrains Mono'` with `ui-monospace, "SFMono-Regular", Consolas, monospace`.

### 5. No more `!important` in component CSS
Current code uses `!important` extensively to override WP core styles on buttons (see `admin-metabox.css` `#dexpress-send-shipment`). After migration, scope overrides properly with `#dex-shipment-root .dex-btn--primary` specificity rather than `!important`.

### 6. Remove inline styles from PHP templates
Several templates add `style="margin:0 0 16px;"` and similar. After the component system is in place, replace with the appropriate utility class or component modifier.

---

## File Map (final state after refactor)

| File | Purpose | Loaded on |
|---|---|---|
| `admin.css` | `:root` tokens + all 18 shared components + WP overrides | Every D-Express admin page |
| `admin-dashboard.css` | KPI grid, bar chart, quick actions, sys info | Dashboard only |
| `admin-metabox.css` | 3-state shipment metabox, status timeline, PS info metabox | Order edit page |
| `admin-bulk-shipment.css` | 4-step bulk wizard, profile cards, summary card | Bulk Shipment page |
| `admin-package-profiles.css` | Card grid, PP modal, sidebar rules | Package Profiles page |
| `admin-onboarding.css` | Centered layout, step dots, feature list, method selection | Onboarding page |
| `admin-diagnostics.css` | Hero panel, stat rows, log textarea (light theme) | Diagnostics page |
| `admin-package-shop-shipping.css` | Textarea size overrides for shipping method settings | WC → Settings → Shipping |
| `checkout.css` | Autocomplete dropdown, disabled field states | Checkout page (frontend) |
| `package-shop-checkout.css` | Package Shop modal, browser layout, info panel | Checkout page (frontend) |
| ~~`admin-design-system.css`~~ | **Merged into `admin.css`** | — |
| ~~`admin-shipments.css`~~ | **DELETE — empty file** | — |
