# Shared workspace forms and layout

The client portal and internal operational workspace load `public/css/forms.css` after their base layout styles. New forms should use the same components rather than relying on browser-default inline controls.

- Forms use two columns on desktop and one on narrow screens. Wrap an input/select in its label or use an associated `for`/`id`; include a concise label and optional `.help-text`.
- Use `.full-width` for long fields and `.form-actions` for submission buttons and supporting copy. `.form-grid-three` supports short three-field forms and collapses on smaller screens.
- Group related choices in `fieldset`/`legend` with `.choice-grid`; retain native checkbox/radio semantics. Give each option a short label and useful explanation.
- Use `.inline-form` for compact table/list actions and `.button.secondary` or `.button.danger` for their visual priority. Server-side permissions remain authoritative.
- Use `.stack` for consistent section spacing, `.section-heading` for card headings and `.empty-state` for actionable empty states.
- Use `.page-tabs` for content navigation. Sidebar selectors stay scoped to `.portal-sidebar` so they cannot change content tabs or pagination.
- Client-user administration uses `.member-grid`/`.member-card` rather than multiple editing forms squeezed into a table row. Less frequent security actions are grouped in native details/summary elements.
- Page layouts own the client H1. Reusable content templates should not repeat it. Each client route must highlight one sidebar item, including recurring campaign routes.

The recurring, integrations, client-user and profile screens were reviewed with isolated demo data at 1440 px and 390 px widths, plus a dark-mode preview. Browser checks cover page overflow, headings, navigation selection and control heights. No account records or feature flags are changed by these presentation components.
