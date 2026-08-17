# UI conventions

Not invented here. This is a description of what this plugin already does, written down after the owner
had to point out the same class of mistake more than once. **Read it before adding any control.** A new
control that does not look like the ones beside it reads as broken even when it works.

Every rule below has a "why it was written" line, because a rule whose reason is lost gets dropped.

---

## Settings screens

**1. Every field must live INSIDE a section — between a `title` and its `sectionend`.**
A field placed after a `sectionend` and before the next `title` renders **outside the `form-table`**, and
that single mistake breaks four things at once: no (i) bubble, the description prints as a wall of text,
the label loses its column, and a checkbox stays a raw checkbox instead of becoming a toggle. All of it
is keyed off `.form-table td`.
*Why:* exactly this happened to `bgcouriers_speedy_declared_value` and `_return_voucher`, and the owner
reported all four symptoms as separate faults. They were one placement error.

**2. Descriptions become (i) bubbles automatically — so write them to fit one.**
`assets/js/bgc-settings-admin.js` lifts `<span class="description">` out of the value cell into a small
`(i)` next to the label. Two or three sentences at most. If it needs a paragraph, the setting is doing
too much or is named badly.
*Why:* the owner's words - "чому так багато тексту". A control whose meaning needs a paragraph will be
misread whatever the paragraph says.

**3. Never write markup or a link into a description.**
Descriptions containing a link or `<code>` are deliberately left inline by the same JS - which is why
one turns into a wall of text while its neighbours are tidy. Put the detail in the docs and keep the
description a sentence.

**4. Checkboxes are toggles, and that is automatic.**
`input[type=checkbox]` inside `.bgc-settings .form-table td` is restyled into a green/red switch by CSS.
Do not build a switch by hand, and do not use a select with Yes/No where a checkbox belongs.

**5. A choice of two states is a checkbox; three or more is a select.**
"Declared value: no / from the COD amount" could have been a checkbox and is a select because a third
option is plausible. Do not use a select for an on/off.

**6. Default OFF for anything the courier charges for.**
Insurance, declared value, return waybills. A plugin that silently enables a paid service is spending
the merchant's money for them.
*Why:* the owner asked for exactly this on обявена стойност, having checked with Speedy that a payout
needs documents most shops cannot produce.

**7. Say what a setting costs, not only what it does.**
"Speedy charges a premium for it" belongs in the description. The merchant is deciding about money.

---

## Anything customer-facing

**8. One line, and it must be true of THIS order.** The interactive map used to print a nationwide
reference price on every point; the checkout then charged something else. A number the interface
advertises and the checkout contradicts is worse than no number.

**9. Show a price as an estimate only when it is one.** The `~` prefix means estimate. If the real
figure can be had - and once a town is chosen it can - fetch it.

**10. Never render a control that does nothing.** The parcels/insurance row hides for couriers that do
not honour it. A box that accepts "3" and ships one parcel is worse than no box.

**11. Disabled beats absent, and a title says why.** A control that disappears leaves the merchant
hunting. One that is dimmed with a reason teaches them something.

---

## Both

**12. New user-facing strings get their Bulgarian in the same change.** `.pot` → `msgmerge` → fill →
`msgfmt`. `bin/preflight` fails the release if the catalogue is short or the `.mo` is stale.

**13. Match the neighbours before inventing anything.** Open the screen the control will live on and
copy the shape already there. Nearly every UI complaint on this project has been a control that was
fine in isolation and wrong beside its neighbours.
