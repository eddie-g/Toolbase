# Inline edit and move regressions

The PDF upload tests tab at `/admin/run-pdf-tests` contains four retained
fixtures named `Inline regressions - ...` for the source documents' admin
owner (admin 1). Original documents and credentials are unchanged.

| Original document / annotation | Case | Test ID |
| --- | --- | --- |
| 7341 / `pdfjs_7341_0_0:24` | Replace 3 with 5; preserve spacing | da11637b-14cc-4f54-9390-1f4320dccbce |
| 7341 / `pdfjs_7341_0_0:29` | Replace 6 with 5; preserve spacing | 2df76918-9314-453c-8a4e-2e5e116704c9 |
| 7341 / `promoted_2_6` | Enter, move, reopen; preserve bold | 8b86b5b9-5f01-48ef-954f-eb5d9d17e067 |
| 7339 / `pdfjs_7339_0_0:129` | Replace income with taxes; preserve gaps and fonts | d7881bd2-7ac8-4bfd-a058-95ccaaa11d2d |
| 7339 / `pdfjs_7339_0_0:24` | Replace 2a with 44 without moving; no old-glyph stripe | b451bea1-407f-4f55-b730-31533cf18be6 |
| 7151 / `promoted_1_1` | Move configuration block; no source residue | f881eb41-7dd5-4631-b32e-7efacfeb6132 |
| 5294 / `pdfjs_5294_4_4:128` | Move 18; preserve gray cell and erase old digits | e3cf0056-dc5d-4457-a064-7b0ba40f01ae |
| 5294 / `promoted_3_4` | Unequal-length paragraph edits; preserve captured rows and hanging indents | ef87a57b-888d-443f-80e4-1375278bee87 |
| 5294 / `promoted_3_8` | Resize, Enter, and selected-word font changes; preserve paragraph layout | 69b3cd15-7cef-402a-b05e-16b119218ccd |

The first seven cases passed through `PdfTestController::runSingleTest`; reports
673-679 were saved on the initial run. The list endpoint was checked under
admin 1. The interactive owner browser session was not used.

The eighth case, `promoted_3_4`, passed through the admin controller as report
680. It tests `agency` to `abcd`, `agency` to `office`, and `reason` to
`explanation`, including commit, saved payload, and zoom/reopen. The generic
production path retains captured rows when text edits preserve row structure,
and persists that intent with `pdfjsPreserveSourceRows`. Structural, style,
and size changes may reflow text but must retain paragraph spacing. Existing already-reflowed
user edits are not automatically rewritten. Tests use the retained snapshot
because the original document may be edited concurrently.

The paragraph action matrix now runs on both `promoted_3_4` and `promoted_3_8`:
drag the right edge 90px narrower, insert Enter in the middle, and change only
one word to Georgia. Each action starts from the retained snapshot and checks
original marker/body insets, the new line's inherited hanging indent, unchanged
text and unaffected fonts, deselection, save payload, and glyph positions after
zoom/reopen. The expanded case 51 passed six checks in report 681; the new case
52 passed three checks in report 682. Screenshots are written under
`/tmp/promoted_3_*-{resize,enter,font}-{edited,deselected}.png`.

The shared conversion retains internal separators and converts uniform source
indents into a hanging indent. Commit no longer clears naturalized insets.
`pdfjsParagraphLayout` stores insets and line pitch in PDF points for rebuilds;
unchanged source runs retain their font identity after a selected-word style
change. These are generic paragraph rules, not annotation-specific fixes.

## Execution

```sh
docker compose exec -T laravel.test php artisan pdf-tests:register-inline-regressions --dry-run
docker compose exec -T laravel.test php artisan pdf-tests:register-inline-regressions
npm run test:pdf-inline-stability
```

Registration is idempotent and does not overwrite existing case comments.
Fixture document IDs differ from original IDs; scenario resolution ignores
the document-ID prefix but checks the annotation suffix, page, and explicit
`[inline-regression:...]` marker. Keep that marker when editing comments.

The tracked browser runner reads retained PDF bytes and copied extraction
state into an isolated browser fixture. Every non-GET/HEAD request is
intercepted, including shared overwrite routes. Save checks inspect the
serialized browser payload; they do not exercise backend persistence or
export. These limitations appear in the admin report warnings.

The gray-cell test compares the dominant neutral fill, excludes glyph
antialiasing and editor shadows, and checks old-digit removal. The
preformatted-block test checks zero source residue and unchanged moved text
at 120% and 190% zoom. Unit tests cover neighbor protection and real rules.

## Asana Status

All nine tickets were created through the authenticated Asana MCP connection
in **Netkit / BUGS**, open and unassigned pending review:

- [7341 :24 digit spacing](https://app.asana.com/0/1213292543986752/1218354520616411)
- [7341 :29 digit spacing](https://app.asana.com/0/1213292543986752/1218354489590050)
- [7341 promoted_2_6 paragraph bold](https://app.asana.com/0/1213292543986752/1218354489361720)
- [7339 :129 income-to-taxes spacing](https://app.asana.com/0/1213292543986752/1218354592220199)
- [7339 :24 stationary label stripe](https://app.asana.com/0/1213292543986752/1218358511032207)
- [7151 promoted_1_1 moved-block residue](https://app.asana.com/0/1213292543986752/1218355568635906)
- [5294 :128 gray-cell background](https://app.asana.com/0/1213292543986752/1218354536054377)
- [5294 promoted_3_4 paragraph row spacing](https://app.asana.com/0/1213292543986752/1218355218533068)
- [5294 promoted_3_8 paragraph action layout](https://app.asana.com/0/1213292543986752/1218355429030532)

Each ticket includes reproduction steps, acceptance criteria, the admin test
ID, passing local validation, and the backend save/export coverage limitation.
The earlier [CSV drafts](inline-regression-asana-import.csv) are retained for
reference only; do not import them again, as that would create duplicates.