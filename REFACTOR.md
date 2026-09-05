# Refactor plan

Reviewed against the working tree on 2026-09-05. Provider protocols and external failures need coverage before form cleanup. Preserve payment contracts and successful `Continuation` payloads.

## 1. Priority: high — define external response handling at each provider boundary

The BePaid and WebPay clients call `json()` and consume nested fields without a consistent HTTP/JSON failure path. ERIP casts the decoded response to an array and can turn a missing transaction into `Pending`. Existing tests cover payment calculations and instruments, not these HTTP exchanges or notification handlers.

- Use HTTP fakes to characterize successful, failed, pending, and redirect responses, non-2xx responses, invalid JSON, and missing fields actually required by each operation.
- Distinguish a valid pending payment from a transport/protocol failure. Define the caller-visible failure before changing it; this is a behavior fix, not just a response-reader extraction.
- Validate genuinely external payloads once at the provider adapter and pass precise state downstream. Preserve raw responses where the public contract requires them. Do not revalidate developer settings or already constructed domain objects.
- Cover WebPay signature rejection before processing and keep signature input representation intact. Confirm where BePaid/ERIP notification authenticity is enforced in the integration before changing their handlers.

Acceptance: supported provider results keep their decisions and metadata; malformed exchanges cannot silently become ordinary pending results. No live payment requests are needed for verification.

## 2. Priority: medium — separate provider card mapping from card persistence

`BePaid::persistCard()` combines BePaid field names/date conversion with `CreditCard::firstOrNew()` and lifecycle persistence. There is no existing credit-card domain service to move it into.

Keep BePaid payload translation at the adapter. If extraction remains useful after boundary validation, let `CreditCard` own token lookup and updating its persisted fields through a typed operation. Preserve method/customer/token identity, the intentional no-token result, model events, default/sort behavior, and continuation metadata.

Acceptance: immediate success and callback success reuse the same saved card for the same method/payer/token, while another method or payer remains separate. Any concurrency guarantee requiring a new constraint is a separate migration decision.

## Deferred

- A generic nested response reader across unrelated provider protocols may obscure required/optional fields; start with concrete provider mappings.
- Shared credential builders on `Payments/Base`: BePaid's numeric shop ID and WebPay's password field differ. The two return/cancel fields are too small to justify extending every provider's base class on their own.
