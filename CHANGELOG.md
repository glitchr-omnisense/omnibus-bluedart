# Changelog

## [Unreleased]

### Fixed
- An "undelivered" (or "not delivered") scan is read `EXCEPTION`, not `DELIVERED`, whatever its
  scan type: the text "delivered" was looked for first, so only the type `UD` saved it. The scan
  type now decides first (`DL`, `UD`, `RT`), then the words, the failed deliveries before
  "delivered".
- `rate()` no longer rates a product 0 INR when the shipment's `prices` option gives it no price:
  such a product is neither asked about nor rated (Blue Dart's Transit API quotes no price). Without
  `prices` (or configured `rates`), `rate()` answers no rate.
- Rating sends the `sub_product` option as the transit query's sub-product, as booking does; it
  always sent `P`.
