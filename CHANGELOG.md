# Changelog

## [Unreleased]

### Fixed
- A `CarrierException` no longer carries the client secret, the licence key and the JWT. The message
  quoted the HTTP client's (the whole URL), the previous was the client's own exception, and every
  trace of the chain kept the arguments of the calls under it (the request's options, the `Api` with
  its credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them.
  The message masks them as `***`, the previous is a copy of the client's exception (the same class
  and code), and no exception `Api` throws keeps its trace's arguments (`Omnibus\Http\Redaction`).
- An "undelivered" (or "not delivered") scan is read `EXCEPTION`, not `DELIVERED`, whatever its
  scan type: the text "delivered" was looked for first, so only the type `UD` saved it. The scan
  type now decides first (`DL`, `UD`, `RT`), then the words, the failed deliveries before
  "delivered".
- `rate()` no longer rates a product 0 INR when the shipment's `prices` option gives it no price:
  such a product is neither asked about nor rated (Blue Dart's Transit API quotes no price). Without
  `prices` (or configured `rates`), `rate()` answers no rate.
- Rating sends the `sub_product` option as the transit query's sub-product, as booking does; it
  always sent `P`.
