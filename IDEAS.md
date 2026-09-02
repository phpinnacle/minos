# Ideas

Only small, additive features are listed here. Refactors and package-wide redesigns are intentionally excluded.

## 1. Idempotent payment attempts

Persist an idempotency key, provider reference, status, and failure reason for each charge attempt so retries cannot accidentally create duplicate payments.

## 2. Refunds

Add full and partial refund operations behind provider capability checks, with a small result object that records the provider reference and final status.

## 3. Stored-card management

Provide Filament controls for listing saved cards, choosing a default, identifying expired cards, and removing a card through the owning provider.
