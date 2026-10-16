# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5 and Luma.
The Composer PHP range is a dependency constraint, not a claim that every Magento/PHP
combination has passed runtime testing.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.5 | Passed locally: Composer, PHP lint, DI compilation, PHPStan level 5, unit/JavaScript tests and quote integration tests |
| Other Magento releases | 8.1–8.4 | Unverified; use a matching Magento dependency set and services |

The shared sandbox also checks consent mapping against temporary database tables,
the JavaScript lifecycle, and native Vanilla consent choices, guest checkout capture, local
reminder delivery, confirmed restore/merge and unsubscribe in a browser. Quote integration
tests use Magento demo data and cover rollback, a merged quantity above stock and concurrent restores.
Reminder SQL regressions also cover same-address cooldown and parallel recipient claims, store-specific quote lifetime, late delivery and restore expiry.
Endpoint regressions also cover website isolation, strict form keys, malformed input, HEAD safety and link replay. Its database is MariaDB 11.8. Hyvä: the storefront parts run in Hyvä shops that use Hyvä's Luma checkout fallback (the checkout page is
Magento's own); a real Hyvä sandbox check is in progress. Hyvä Checkout (Magewire) is not supported: it does not
capture the guest email. These checks do not certify other themes, third-party
tracking scripts, checkout customizations or Adobe Commerce-specific features.

The sandbox provides 30 isolated browser scenarios for native SQL (`Magento_MysqlMq`, connection
`db`) and RabbitMQ 4.3 (`amqp`) on Magento 2.4.9 / PHP 8.5 / MariaDB 11.8. A complete rerun on
both transports remains a release gate after restoring recipient cooldown. Checks include native publication and
consumer dispatch, duplicate protection, acknowledgement/rejection status, failed-send retry,
restore/withdrawal and cleanup of run-owned queue rows. Selection without external broker
configuration is checked through Magento's default connection provider. This does not prove
throughput under load, other database versions or automatic migration of queued messages.

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5 and Git archive contents.
Local syntax checks passed on PHP 8.1.34, 8.2.30, 8.3.30, 8.4.26 and 8.5.11.
The workflow does not install Magento or run runtime tests. Latest results are available in
[GitHub Actions](https://github.com/commercekings/module-abandoned-cart/actions).

For runtime tests, install the source checkout in a compatible Magento development project
and run the suites in `Test/`. Distribution archives omit `Test/`; source checkouts retain it.
Keep package constraints and the verified matrix aligned before each release.

Supported Magento service/version combinations are listed in
[Adobe's system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements).
