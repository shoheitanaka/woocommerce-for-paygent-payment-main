# Payment Gateway Review Guidance

## Code Review Rules

### Payment state integrity

* Flag any path that can mark an order paid before the payment provider's successful result has been authenticated and verified.
* Flag any path where a failed local update after a successful remote charge can cause a second charge on retry.
* Require repeated browser returns, API retries, and webhook deliveries to be idempotent.
* Use the provider's immutable transaction identifier when one is available.

### Webhook processing

* Require webhook authenticity verification using the provider's documented signature, secret, token, or equivalent mechanism.
* Do not rely on source IP alone unless the provider explicitly guarantees and documents it.
* Flag processing that is unsafe when the same webhook is delivered more than once.
* Flag stale or out-of-order events that can overwrite a newer payment or order state.
* Do not log complete webhook payloads when they may contain personal or payment information.

### Amount and order validation

* Verify the expected order, amount, currency, merchant account, and transaction relationship using trusted server-side data.
* Do not trust values supplied only by the browser or an unauthenticated callback.
* Flag amount comparisons that can fail because of currency precision, rounding, tax, or minor-unit conversion.
* Flag callbacks that accept a valid transaction belonging to a different order.

### Refunds and cancellations

* Distinguish authorization cancellation, capture cancellation, full refund, partial refund, expiration, and payment failure.
* Ensure local WooCommerce state is updated only after the provider confirms the corresponding remote operation.
* Flag retries that can issue duplicate refunds.
* Preserve the provider transaction and refund identifiers needed for reconciliation.

### WooCommerce lifecycle

* Check whether calls to `payment_complete()`, `update_status()`, stock methods, and order notes cause duplicate side effects.
* Flag code that manually reproduces behavior already handled by `payment_complete()`.
* Verify that asynchronous callbacks reload the current order state before applying changes.
* Do not assume the order is still in the state observed when the payment request was created.
