# AGENTS.md

## Project Context

This repository contains a WordPress and WooCommerce plugin.

The plugin may interact with:

* WooCommerce orders and customers
* HPOS order storage
* Checkout Blocks and the Store API
* Payment gateways and external APIs
* Webhooks and asynchronous callbacks
* Action Scheduler and WP-Cron
* WordPress administration screens
* Customer personal information

Backward compatibility, payment safety, data integrity, and WordPress security are more important than code-style preferences.

## Repository Expectations

* Keep changes focused on the stated pull request purpose.
* Do not modify unrelated behavior.
* Do not introduce new production dependencies unless clearly justified.
* Preserve compatibility with the supported PHP, WordPress, WooCommerce, and WooCommerce Blocks versions.
* Prefer established WordPress and WooCommerce APIs over custom implementations.
* Do not commit generated files unless they are intentionally tracked by this repository.
* Do not assume that the classic checkout and Checkout Blocks behave identically.
* Do not assume that WooCommerce orders are stored as WordPress posts.

## Code Review Rules

### Review objective

Review the pull request as an independent senior engineer.

Report only defects that are:

* introduced or exposed by the pull request;
* reproducible from a concrete execution path;
* likely to affect production behavior, security, compatibility, data integrity, payment processing, or maintainability in a significant way;
* actionable by the pull request author.

Prioritize findings over summaries or praise.

Do not report speculative concerns without identifying a concrete failure scenario.

### Finding requirements

For every finding:

* Identify the affected file and the smallest relevant changed line range.
* Explain the concrete condition that triggers the problem.
* Explain the resulting user-facing or system impact.
* State why existing surrounding code does not already prevent the problem.
* Suggest the smallest safe correction when practical.
* Do not combine unrelated problems into one finding.

Do not report an issue when the pull request already contains an effective guard, validation, test, or fallback for it.

### Severity

Use the following severity model:

* P0: Causes widespread data loss, unrecoverable corruption, credential exposure, arbitrary code execution, or uncontrolled payment processing.
* P1: Causes incorrect charges, missed or duplicated order processing, authorization bypass, serious information disclosure, fatal errors on supported environments, or major production regressions.
* P2: Causes limited functional failures, compatibility problems, incorrect edge-case behavior, or meaningful maintainability risks that are likely to create defects.
* P3: Minor improvements, defensive enhancements, readability concerns, or optional refactoring.

For GitHub review comments, focus primarily on P0 and P1 findings. Report P2 only when it represents a concrete defect with a realistic production path.

Do not report P3 findings as review defects.

### Pull request scope

* Confirm that the implementation matches the pull request description.
* Flag behavior that contradicts the stated requirements.
* Flag unrelated behavior changes that increase regression risk.
* Flag incomplete migrations where only one of multiple code paths was updated.
* Flag changes that silently alter public hooks, filters, REST responses, stored metadata, or external integration behavior.
* Do not require unrelated cleanup as part of the pull request.

### WordPress security

Flag changed code that:

* performs a privileged action without an appropriate capability check;
* processes a state-changing browser request without nonce or equivalent CSRF protection;
* trusts unsanitized request, option, metadata, webhook, or API input;
* renders dynamic content without context-appropriate escaping;
* constructs SQL without `$wpdb->prepare()` or another safe query mechanism;
* allows unsafe file paths, uploads, redirects, deserialization, or dynamic code execution;
* exposes secrets, tokens, personal information, payment data, or full sensitive API responses in logs;
* uses authorization checks based only on hidden fields, URLs, JavaScript, or UI visibility.

Do not request nonce validation for authenticated server-to-server webhooks. For webhooks, require the provider's signature, secret, token, or equivalent authenticity verification.

### WooCommerce order handling

Flag changed code that:

* directly reads or writes order post fields or post meta when WooCommerce CRUD methods should be used;
* assumes orders always use the `shop_order` post type or the `wp_posts` table;
* bypasses WooCommerce APIs in a way that breaks HPOS compatibility;
* changes an order status without considering associated hooks and side effects;
* updates an order but fails to save it when required;
* performs duplicate stock reduction, stock restoration, email delivery, or status transitions;
* confuses an order ID with a subscription ID, renewal order ID, parent order ID, customer ID, or product ID;
* relies on mutable order data after an asynchronous boundary without reloading or validating it.

The safe path is to use WooCommerce CRUD objects and documented WooCommerce APIs unless the repository explicitly implements an approved compatibility layer.

### Payment processing

Flag any changed path that can:

* create duplicate charges or duplicate payment requests;
* mark an order paid without verified successful payment;
* process the same callback, webhook, retry, or browser return more than once;
* fail after the provider accepted payment but before the local order was updated;
* trust payment amount, currency, order ID, or status from an unverified client request;
* accept a webhook without authenticating its origin;
* expose secret keys, access tokens, cardholder data, or sensitive provider responses;
* incorrectly treat pending, authorized, captured, failed, cancelled, expired, and refunded states as equivalent;
* retry an unsafe operation without an idempotency mechanism;
* acknowledge a webhook successfully before required durable processing has occurred;
* allow browser refreshes or duplicate submissions to execute payment twice.

Where retries or repeated callbacks are possible, require idempotent processing based on a durable provider transaction identifier or equivalent state check.

### Checkout Blocks and Store API

Flag changed checkout code that:

* supports only the shortcode-based classic checkout when Checkout Blocks support is required;
* uses classic checkout JavaScript events as though they also fire in Blocks;
* registers a payment method incompletely or with inconsistent frontend and backend availability checks;
* fails to return the response shape required by the Store API or Blocks payment lifecycle;
* trusts browser-provided payment or order data that must be validated server-side;
* fails to handle payment setup, processing, redirect, retry, or failure states;
* causes different totals, fees, validation, or availability rules between classic checkout and Checkout Blocks.

Do not require Blocks support for code explicitly documented as classic-checkout-only.

### External APIs and webhooks

Flag changed code that:

* does not handle timeouts, connection errors, malformed responses, or non-success HTTP status codes;
* treats a network error as a successful business operation;
* retries non-idempotent requests automatically;
* has no protection against duplicate or out-of-order webhook delivery;
* assumes webhook delivery happens only once;
* logs complete sensitive request or response bodies;
* lacks signature verification where the provider supports it;
* accepts stale events that can overwrite a newer local state;
* has an unbounded retry or polling loop.

A webhook should be safe to process repeatedly unless the provider contract guarantees otherwise.

### Data integrity and persistence

Flag changed code that:

* writes partial state before an operation can safely complete;
* can leave order, subscription, payment, inventory, or customer data inconsistent after failure;
* overwrites existing metadata with an empty or invalid value;
* changes the type or meaning of persisted data without migration or backward compatibility;
* performs destructive updates without confirming the target object and ownership;
* depends on a transient, cache, or session as the only source of durable business state;
* performs bulk operations without bounded processing or recovery behavior.

### Authentication and authorization

Flag changed code that:

* confuses authentication with authorization;
* allows a customer to access another customer's order, subscription, address, download, or payment data;
* accepts an object ID from a request without checking ownership or permissions;
* exposes administrative operations through public AJAX or REST endpoints;
* uses a broad capability where a narrower established capability is required;
* changes REST API permissions without a valid `permission_callback`.

### Scheduled and asynchronous processing

Flag changed code that:

* schedules duplicate events without an intentional deduplication strategy;
* assumes WP-Cron or Action Scheduler runs at an exact time;
* cannot safely resume after interruption;
* repeats a side effect when a job is retried;
* loses required context before the asynchronous task executes;
* stores secrets or sensitive personal information in job arguments unnecessarily;
* marks a task complete before its durable side effects succeed.

### PHP compatibility

Flag changed code that:

* uses syntax or standard-library functionality unavailable in the minimum supported PHP version;
* introduces a fatal error when an optional class, plugin, extension, or function is absent;
* calls a method or hook unavailable in a supported WordPress or WooCommerce version without a compatibility check;
* changes method signatures incompatibly with parent classes, interfaces, WordPress hooks, or WooCommerce callbacks;
* causes PHP warnings, notices, or type errors on realistic inputs.

### JavaScript and React

Flag changed code that:

* mutates shared state in a way that prevents React updates;
* causes effects, subscriptions, timers, or event handlers to accumulate;
* captures stale state in asynchronous callbacks;
* allows a checkout action to run repeatedly while already processing;
* exposes secrets or relies on client-side validation for security;
* uses APIs unavailable in the repository's supported WordPress or WooCommerce package versions;
* leaves checkout state blocked after an error;
* handles success but not rejection, cancellation, timeout, or retry paths.

### Tests

Flag missing tests only when the pull request changes consequential behavior and a realistic regression would not be detected by existing tests.

Pay particular attention to tests for:

* payment success, failure, pending, cancellation, and retry;
* duplicate callbacks and duplicate submissions;
* webhook authentication and replay;
* HPOS compatibility;
* classic checkout and Checkout Blocks behavior;
* authorization and ownership checks;
* order and subscription status transitions;
* refunds and partial refunds;
* API timeout and malformed-response handling;
* supported PHP, WordPress, and WooCommerce compatibility boundaries.

Do not request tests that only duplicate deterministic lint or formatting checks.

### Error handling

Flag changed code that:

* silently ignores an error that changes business state;
* catches an exception and then continues as if the operation succeeded;
* displays sensitive internal errors to customers;
* returns success before the operation is actually complete;
* leaves checkout, order, or payment state irrecoverably stuck;
* converts a retryable failure into a permanent failure without justification;
* reports only a generic error while discarding information required for server-side diagnosis.

Customer-facing errors should be safe and understandable. Diagnostic details should be logged only when they do not expose sensitive information.

### Internationalization

Flag newly added customer-facing or administrator-facing strings that cannot be translated using the repository's established WordPress internationalization pattern.

Do not report wording preferences or translation quality as code defects.

### Public compatibility

Flag changed code that breaks an existing public contract without an explicit migration plan, including:

* action and filter names;
* callback argument counts;
* public PHP methods or classes;
* option names;
* metadata keys;
* REST routes and response structures;
* JavaScript handles;
* payment method identifiers;
* scheduled action names;
* documented constants or configuration values.

Do not treat undocumented internal implementation details as public contracts unless existing code clearly depends on them.

### Review exclusions

Do not report:

* formatting, spacing, import ordering, or other issues enforced by automated tools;
* personal style preferences;
* requests to rename variables solely for taste;
* generic recommendations without a concrete defect;
* pre-existing problems unchanged by the pull request;
* hypothetical scaling problems without a realistic repository-specific path;
* missing comments for self-explanatory code;
* optional refactoring that does not correct a defect;
* broad architectural rewrites when a focused correction is available;
* test failures already clearly reported by required CI checks, unless the code review identifies the underlying defect;
* intentional behavior explicitly documented in the pull request and consistent with repository requirements.

## Verification Commands

Use the commands actually available in this repository.

Before concluding that a change is safe, inspect the relevant configuration files to identify the correct commands.

Typical checks may include:

```bash
composer install
composer test
composer run phpcs
composer run phpstan
npm ci
npm test
npm run lint
npm run build
```

Do not claim that a check passed unless it was actually run successfully.

If a check cannot be run, state:

* which check was not run;
* why it could not be run;
* which risks therefore remain unverified.

## Review Completion

Before completing a review:

1. Read the pull request description.
2. Inspect the complete diff, not only isolated changed lines.
3. Inspect surrounding code needed to validate each finding.
4. Check whether tests or guards already cover the suspected issue.
5. Remove findings that are speculative, stylistic, duplicated, or unrelated to the pull request.
6. Rank remaining findings by severity.
7. Prefer no findings over low-confidence findings.
