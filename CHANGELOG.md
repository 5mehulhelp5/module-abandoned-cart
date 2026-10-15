# Changelog

## Unreleased

- Restore recipient cooldown with a configurable seven-day default, native keyed hashes, atomic owned claims, daily pruning and concurrent-worker regressions. Keep one reminder per cart.
- Optionally add RFC 8058 headers to reminder emails without replacing their confirmation link or changing withdrawal cleanup.

- Keep the delivery claim after SMTP returns successfully even if subsequent environment cleanup fails, preventing another reminder from a post-send exception. Add concurrent finder/consumer and interrupted-worker regressions.

- Remove the development-only opt-out redirect, obsolete route fixtures/documentation and retired recipient-table migration metadata. Email opt-out belongs exclusively to Consent.

- Register reminder cleanup through Consent's typed withdrawal pool instead of an event observer. Share the same field cleanup for guests and customers, preserve native Magento emails and delivery history, and allow durable retries against the original withdrawn consent IDs.

- Keep captured guest reminder email and its change timestamp in dedicated quote columns. Preserve Magento checkout email during capture and withdrawal, resolve guest/customer recipients through a replaceable API, and include contact activity in the abandonment delay without extending quote lifetime or resetting delivery history.

- Validate guest email through a global before plugin on `GuestEmailCaptureInterface`, using Magento's native `stringTrim` filter and `EmailAddress` / `StringLength` validators with the length limit in DI. Aggregate input errors without echoing email values; retain the single V1 endpoint, flat payload, explicit clearing and service authorization checks.

- Refresh the mini-cart cookie using Magento's configured cookie lifetime and domain, with session cookies at zero lifetime; preserve Magento customer-data's root path.

- Use Magento SQL expressions for queue/send claims and withdrawal cleanup; use Magento's JSON serializer for mini-cart cookie updates and tolerate malformed cookie values after restore.

- Generate and verify recovery signatures exclusively through Magento's native encryptor, without a legacy raw-key fallback; test encoded/current keys, invalid keys, forged hashes and signer failures.

- Document Magento's native SQL queue setup alongside RabbitMQ, remove RabbitMQ-only package/admin wording, and add transport-selectable sandbox delivery checks with failed-send retry and scoped SQL cleanup.

- Reject forged recovery signatures before loading a quote; refuse signing with missing, empty or invalid deployment keys and reject validation safely.

- Finalize the new policy API around typed state and SQL contexts before first publication, allowing additive state and history-backed policies without changing existing method signatures later.

- Keep one reminder per cart through a replaceable policy API; retain a separate recipient cooldown without changing this policy contract. Inherit Magento quote lifetime per store, show read-only admin previews for lifetime and delivery frequency, and recheck delay/expiry during delivery and restore.

- Authorize capture by quote website/store, retain scope in atomic writes and clears, verify customer acknowledgements, reject mismatched queue consents, and retire unsafe quote-only opt-out links.

- Use Consent's shared withdrawal-link API and confirmation page, with module-owned cart cleanup.
- Clarify reminder email wording without promising a saved cart snapshot, and distinguish an unavailable reminder link from the current cart.
- Serialize guest email capture, acknowledge saved data, retry transient failures and clear stale guest capture on invalid or empty input.
- Require confirmation and a form-key POST for guest restores; lock and reload carts, preserve destination details, validate stock and save merges atomically.
- Add guest capture JavaScript tests and real quote integration tests for merging, concurrent requests and rollback.
- Consent-based cart reminders, guarded queue processing and signed restore/opt-out links.
- Prepare Composer metadata, Magento dependency bounds and source/distribution packaging for the first public release.
- Adapt unit tests to PHPUnit 12 while retaining older data-provider annotations.
- Use English UI defaults, remove bundled locale translations and replace fixture/example text with English; correct the documented greeting behavior.
- Add PHP syntax/metadata/archive CI checks and explicit compatibility notes.
- Align reminder rendering with Magento quote models, load masked ids through the resource model, and use declared product-type APIs for PHPStan level 5.

No public release has been tagged or published yet.
