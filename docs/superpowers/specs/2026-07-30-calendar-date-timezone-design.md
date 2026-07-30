# Calendar Date Timezone Design

## Problem

Album dates are calendar dates, but the public web formatter parses their ISO values as instants. In UTC−3, midnight UTC on `2026-04-30` becomes `2026-04-29 21:00`, so the displayed day is incorrect.

## Design

Keep the existing API contract and format album calendar dates in UTC in the shared web date formatter. This preserves the `YYYY-MM-DD` portion sent by the API regardless of the visitor's timezone and fixes both single dates and date ranges.

## Testing

Add a regression assertion for `2026-04-30T00:00:00.000Z`, run under `America/Sao_Paulo`, and verify that it displays 30 April rather than 29 April.
