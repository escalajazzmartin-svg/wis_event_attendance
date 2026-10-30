# UI Guidelines

Derived from the CSS in `assets/css/`. Items marked **Confirm** were not verified visually.

## Typography
- Body/UI: **Roboto** (400/500/700). Brand/headings: **Cinzel** 700 (sidebar "WEAMMS", page `h1` in `style.css`). Both loaded by the Google Fonts link in every layout head; keep that link in any new layout/page head.
- Do not use Inter or Arial as the first font. Form controls use `font: inherit`.

## Colors (actual values)
- Brand tokens in `style.css :root`: `--wis-maroon #8B1E1E`, `--wis-maroon-dark #641414`, `--wis-maroon-light #B43737`, `--wis-gold #F2C300`, `--wis-gold-dark #D6A900`, `--wis-background #F5F5F5`, `--wis-border #B8C1CC`, `--wis-black #171717`, `--wis-light-blue #E8F0FE`.
- Admin/department-head shell (`admin.css`): sidebar `#651515`, accent gold `#f2c41d`, page background `#f3f0ed`, text `#1a1a1a`, borders `#efebe7`/`#d9d4d0`. Pills: `ad-pill-yellow|green|blue|red|gray`.

## Layout
- Sidebar 249px fixed (`.admin-sidebar`), collapses to a 72px icon-only rail at ≤760px (labels hidden). Keep this behavior. Main area `.admin-main` (admin/shell) or `.dashboard-main` (older layout, offset 249px).
- Other breakpoints seen: 1100px (split grids stack), 1050, 640/600/480.
- Radius: 6px inputs/buttons, 8px cards/nav items, 10px panels, 50% avatars. Shadows are subtle (`0 1px 3px rgb(0 0 0 / 6%)`).

## Shared components
- Sidebar: department head `includes/department_head_sidebar.php` + `department-head-sidebar.css`; admin `includes/admin_layout_start.php` + `admin.css`; student `includes/sidebar.php` + `student/student.css`. Footer: user name and role, then the logout button — same look on all three roles: full-width row, icon + "Logout", 8px radius, white background with maroon text on hover (`.admin-logout` in admin/department-head, `.logout-link` in student). Icon-only at ≤760px.
- Page shell: `.ad-page`, `.ad-head`, `.ad-card`, `.ad-table-wrap` + `.ad-table`, `.ad-btn` (`ad-btn-solid`, `sm`), `.ad-note`, `.ad-notice`/`.ad-notice-error`, `.ad-pill-*`, `.ad-fields`, `.ad-time`, `.ad-foot` pager (`dhListFooter`).
- Department-head-only additions: `assets/css/department-head-shell.css`. Charts: Chart.js with `assets/js/attendance-charts.js`.
- Icons: inline SVG (`dhIcon`, `adminIcon`) in admin/department head; Font Awesome in student sidebar.

## Refreshed admin pages (Attendance, Reports, Security)
- Stylesheet `assets/css/admin-refresh.css`, scoped to `.admin-refresh`; enable with `$adminRefreshCss = true;` before requiring `admin_layout_start.php`. Does not style the sidebar.
- Look: eyebrow label + 30px title with divider; summary cards with tinted icon chips (`admin-icon-green|gold|red|blue`; used on Reports and Security — the Attendance page now uses the status overview below); panels with 14px radius (no yellow accent bar before panel titles - removed on all admin pages 2026-10-30; do not re-add); tinted filter strip (`#faf7f4`); uppercase table headers, zebra rows; status badges with a dot; pagination footer strip; student avatar initials (`.admin-person`).
- **Status overview** (Attendance page, `admin/attendance/index.php`): a section `.admin-status-overview` placed at the bottom of the "Attendance records" panel (below the table and pager, separated by a top border; no card chrome of its own) with a proportion bar (reuses `.admin-bar-track` + `.bar-present|late|absent|excused`) and a `nav.admin-status-list` of four `.admin-status-item` links (dot, count, percent). Each link filters the table by `?status=` while keeping the other filters; the active one has `.is-active` and `aria-current`; selecting it again clears the status filter. Counts are system-wide totals (same query as before), not filtered by the form. 4 columns, 2 columns at ≤700px. Styles in `admin-refresh.css`. Reason for the change: `.admin-summary-grid` in `admin.css` is `repeat(4)`, so five cards always left "Excused" alone on a second row. Reports and Security use `.admin-summary-grid` with 4 cards or fewer, so they do not wrap; keep new card rows at 4 or fewer, or use the status-overview pattern.
- **Attendance records panel** (`admin/attendance/index.php`, classes in `admin-refresh.css`): `.admin-records-panel` (no maroon top border, stronger shadow, `.admin-records-count` pill in the heading). The table wrapper has `.admin-records-scroll`: fixed max height `max(320px, calc(100vh - 480px))` (about 5 rows minimum, more on tall screens), vertical scroll, sticky header, tighter cell padding — the panel no longer grows as records are added (still 50 rows per page, then pagination). The 480px offset approximates the space used by the page heading, panel heading, filters and the status overview; adjust it if those change.
- **Compact filter bar** (Attendance page only): `.admin-filters-compact` on the form (34px controls, `box-sizing: border-box`, flex widths so search + 3 selects + 2 dates + Filter + Clear stay on one row at 761px and wider (`flex-wrap: nowrap`); wraps below 761px using the original mobile rules) and `admin-filter-button-sm` on the button. Reports and Security keep the default filter size.
- **Reports analytics layout** (`admin/reports/index.php`, scoped `.admin-reports-page` in `admin-refresh.css`). Order top to bottom, following common dashboard guidance (filters, KPIs, main chart, supporting chart, detail table): heading with Export CSV / Print buttons on the right -> one controls panel (report-type tabs + year/semester/department filters) -> `.admin-summary-grid` of 4 cards (each has a `<small>` context line, e.g. "65% of records") -> `.admin-insights` two-column row (`.admin-insight-main` ranked bars `.admin-rank` / stacked mix bars for the Departments report; `.admin-insight-side` status-mix donut `.admin-donut` + `.admin-donut-legend`) -> detail table panel. Charts use no library: the donut is a `conic-gradient` set inline, bars are width percentages; all numbers come from the rows already queried for the table (no extra SQL). Rate bars: green >= 85%, gold >= 60%, red below. Collapses to one column at <=1100px; buttons hide on print.
- **Security layout** (`admin/security/index.php`, `.admin-security-page`): KPI cards with context lines -> `.admin-security-grid` (flagged accounts table with avatars and a count pill on the left, sign-in protection as an icon rule list `.admin-rule-list` on the right) -> activity panel with compact filter strip and `.admin-action-chip` action labels. Queries, filters and pagination unchanged. Stacks at <=1100px.
- Components: `.admin-bars` / `.admin-bar-row` / `.bar-present|late|excused|absent` (CSS bar chart), `.admin-kv` (key/value list), `.admin-two-col`.
- Other admin pages still use the original `admin.css` look; extend the refresh to them only when asked.

## Admin event view page (`admin/events/view.php`, `admin-event-view.css`)
- No top bar: the search box, notification bell, user menu/logout and the divider line under them were removed on 2026-10-30. The page starts with the "Back to events" link. Logout remains in the sidebar. Do not re-add `.ev-topbar` unless asked.

## Shared look (`assets/css/ui-refresh.css`, added 2026-10-30)
Loaded last by every layout. Tokens `--ui-bg #f3f0ed`, `--ui-border #ebe5e0`, `--ui-tint #faf7f4`, `--ui-radius 14px`, `--ui-shadow`. Applies to `.ad-*` (admin + dept-head shell), `body.department-head-dashboard-page` (older dept-head layout/dashboard) and `body.student-dashboard-page`. No top bar on any portal (`includes/header.php` is unused); the account name lives in each sidebar above Logout. Student sidebar is 249px (72px rail at <=760px). Add cross-role visual changes here, not in per-role files.

## Rules
- Reuse existing classes; add new styles to the matching shared stylesheet, not inline.
- Keep department head and admin sidebars visually consistent.
- Mockups/design references: none found in the repo. Design tokens beyond the above: needs confirmation.
- **Attendance records table look** (matches the approved preview `attendance-records-preview.png`): single-line rows (`white-space: nowrap`, table `min-width: 960px`, horizontal scroll inside `.admin-records-scroll`; normal wrapping at <=760px), no dot inside the status badges of this table, timestamp shown as `Oct 30, 8:01 AM` (record source moved into the cell's `title` tooltip), History link text is `View` (black, no underline until hover).
- **Gotcha**: `style.css` has a global `button { display: block; width: 100% }`. Any button inside a flex row (e.g. `admin-filter-button`) must set `width: auto`, otherwise it takes the whole row and forces the next item to wrap.
- Attendance records table shows `department_code` only in the Department column (full name in a `title` tooltip); the Department filter dropdown and the integrity table still use names.
