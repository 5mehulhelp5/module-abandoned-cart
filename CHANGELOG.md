# Changelog

## Unreleased

- Use Consent's shared withdrawal-link API and confirmation page; retain a redirect for legacy quote-based email links, and keep cart cleanup in the withdrawal observer.
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
