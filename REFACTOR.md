# Refactor

Only local, behavior-preserving cleanup is listed here. Public API changes and package-wide redesigns are intentionally excluded.

## 1. Reuse repeated provider fields

Move only the credential and return/cancel URL fields shared by `BePaid` and `WebPay` into protected builders on `Base`, keeping provider-specific form layout in each provider.

## 2. Move saved-card persistence out of `BePaid`

Extract `BePaid::persistCard()` into the credit-card domain service so the provider class translates notifications while one place owns token lookup and persistence.

## 3. Validate provider responses in one place

Introduce a small internal response reader for required nested fields and reuse it in BePaid, ERIP, and WebPay handlers instead of indexing untrusted response arrays throughout payment code.
