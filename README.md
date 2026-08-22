# CEA Plugin

CEA Plugin provides provider-neutral SMTP delivery, a schema-driven WordPress form builder, and private response storage.

## Requirements

- WordPress 6.3 or later
- PHP 7.4 or later
- Node.js and npm (to build theme blocks — see [Theme blocks](#theme-blocks))

## Forms

Administrators can manage forms under **CEA > Forms**. A form must contain at least one labeled field and one enabled, valid action before it can be published.

To reorder fields or actions, press and hold the four-arrow drag control, move the item vertically, and release it in the new position. The adjacent Move up and Move down buttons provide click and keyboard alternatives. The displayed order is saved with the form.

Supported fields:

- Text
- Email
- Telephone
- Textarea
- Select
- Radio buttons
- Checkbox

Embed a published form with:

```text
[cea_form id="123"]
```

The form builder displays the exact shortcode for each form. Forms are intentionally private WordPress records and do not have public archive or single URLs.

### Field keys and templates

Every field receives a stable key when it is first saved. Labels can change without changing that key.

Email templates support:

- `{{all_fields}}`
- `{{field.FIELD_KEY}}`
- `{{form.id}}`
- `{{form.title}}`
- `{{site.name}}`
- `{{site.url}}`
- `{{submitted_at}}`

Unknown tokens are replaced with an empty string.

### Actions

Multiple actions can be added to a form. Enabled actions execute independently after the complete submission has passed server-side validation.

#### Email notification

Email notifications use WordPress's `wp_mail()` function. When CEA SMTP is enabled, form notifications use the same configured SMTP transport and fail-closed behavior as other WordPress email.

#### Webhook

Webhooks send a JSON payload with the form ID/title, submission time, and normalized field values. URLs must use HTTPS unless the `cea_form_allow_insecure_webhook` filter explicitly opts into HTTP.

An optional signing secret adds this header:

```text
X-CEA-Signature: sha256=<HMAC-SHA256 of the raw JSON body>
```

The saved secret is never redisplayed in the form builder.

#### Mailchimp

Mailchimp actions add or update a consenting visitor in a Mailchimp Marketing audience. Configure the site-level connection under **CEA > Mailchimp**, test it to cache the account's audiences, and then add a Mailchimp action to a form.

Each action requires:

- An audience ID
- A mapped Email field
- A mapped Checkbox field for consent
- A new-contact opt-in mode

New contacts use double opt-in (`pending`) by default. Immediate subscription (`subscribed`) is available for consent processes that permit it. The action sends `status_if_new` and intentionally does not change an existing contact's subscription status, so an unsubscribed or cleaned contact is not silently resubscribed.

First name, last name, and static Mailchimp tags are optional. The default name merge tags are `FNAME` and `LNAME`, and can be changed per action to match the selected audience. If the visitor does not check the mapped consent field, Mailchimp is skipped while the form's other actions continue.

The Marketing API key is stored separately from SMTP or Mailchimp Transactional credentials and is never redisplayed. It can instead be supplied in `wp-config.php`:

```php
define( 'CEA_MAILCHIMP_MARKETING_API_KEY', 'your-api-key-us21' );
define( 'CEA_MAILCHIMP_SERVER_PREFIX', 'us21' ); // Optional when the key suffix is present.
```

The audience cache refreshes when the administrator tests the connection. A manual audience ID can be entered when cached choices are unavailable.

If every enabled action fails, the visitor receives a generic retry message and the response remains stored with a failed status. A deliberate retry after the post/redirect/get cycle receives a new token and creates a separate delivery attempt. Recent failure summaries also appear when the form is next edited; submission values and secrets are excluded from those summaries.

## Stored responses

Every valid response is saved to the WordPress database before Email, Webhook, Mailchimp, or custom actions run. Invalid, expired, rate-limited, and honeypot submissions are not stored. The saved snapshot contains the form ID and title, submission time, normalized field keys, labels, types and values, plus safe delivery outcomes. It does not contain action settings or credentials.

Administrators with the configured submissions capability can review responses under **CEA > Submissions** or follow **View responses** from the Forms list. Responses can be filtered by form, delivery status, review state, or date; marked reviewed; and permanently deleted. Delivery states mean:

- `processing`: the response was saved but final action outcomes have not been recorded.
- `completed`: every enabled action completed.
- `partial_failure`: at least one action completed and at least one failed.
- `failed`: every enabled action failed.

Responses are retained for 90 days by default. The Submissions screen can change retention to 30, 90, 180, or 365 days, retain responses until manual deletion, and run a protected bounded purge. Daily cleanup removes at most 500 expired responses per run. Deactivation unschedules cleanup without deleting responses.

## Confirmation behavior

A form can show an inline success message or redirect to a URL on the same WordPress site. External success redirects are rejected during configuration normalization.

Validation errors use a post/redirect/get flow and are stored in short-lived, one-time transients. Sanitized values are redisplayed so visitors can correct the highlighted fields.

## Security and privacy

Public submissions include:

- A form-specific WordPress nonce
- A honeypot field
- A minimum-fill-time check
- A per-form/client rate limit
- A server-rendered idempotency token with a browser fallback
- Schema-based input allowlisting
- Type-specific sanitization and validation

Only published forms accept submissions. Unexpected fields are discarded. Email recipients and headers are validated, webhook requests use `wp_safe_remote_post()`, Mailchimp requests use `wp_safe_remote_request()` against a validated Mailchimp data-center host, and only same-site confirmation redirects are accepted.

Mailchimp requests include only the mapped email, optional mapped name values, and configured tags. API errors stored for administrators exclude request bodies, credentials, and subscriber email addresses.

Validated submission entries are stored in the plugin's private submissions table. The plugin does not store visitor IP addresses, browser user agents, referrers, nonces, or honeypot values with responses. Short-lived transients may temporarily contain sanitized values after a validation error and are deleted when consumed or expire after five minutes. WordPress personal-data export and erasure tools include or delete responses matched through normalized Email fields. Deactivation and uninstall do not automatically delete stored responses.

Public-page caches must not retain form markup beyond the WordPress nonce lifetime. Test nonce behavior whenever full-page caching is enabled.

## Extension hooks

Action integrations:

- `cea_form_register_actions`
- `cea_form_action_types`
- `cea_form_before_actions`
- `cea_form_action_executed`
- `cea_form_action_failed`
- `cea_form_after_actions`

Rendering and validation:

- `cea_form_before`
- `cea_form_after`
- `cea_form_field_html`
- `cea_form_field_types`
- `cea_form_sanitized_field_value`
- `cea_form_validated_submission`

Submission storage:

- `cea_form_submission_stored`
- `cea_form_submission_storage_failed`
- `cea_form_submission_updated`
- `cea_form_submission_deleted`
- `cea_form_submission_capability`

Limits and transport:

- `cea_form_max_fields`
- `cea_form_max_actions`
- `cea_form_rate_limit`
- `cea_form_allow_insecure_webhook`

Custom actions register a definition with `CEA_Form_Action_Registry::register()`. Each definition supplies a label plus sanitize, validate, render, and execute callbacks. Execute callbacks must return `true` or `WP_Error`.

## Theme blocks

CEA Plugin ships a block framework so admin-editable content can be added to a live theme from the Gutenberg editor or Elementor, through one shared PHP render path per block type. See [docs/BLOCKS-PLAN.md](docs/BLOCKS-PLAN.md) for the design and rollout plan.

### CEA Form block

Insert **CEA Form** from the Gutenberg block inserter (under the **CEA Plugin** category), or drag the **CEA Form** widget from Elementor's **CEA Plugin** category onto an Elementor-built page. Choose a published form from the dropdown; the preview is the real rendered form in both editors, not a mockup. Anyone who can edit posts/pages can insert and configure it — placing an already-published form is a lighter permission than building one, which still requires the Forms admin capability. If the selected form is later unpublished or deleted, editors see an inline explanation; front-end visitors simply see nothing, matching how the `[cea_form]` shortcode already behaves for a missing form. Elementor is entirely optional: the widget only registers when Elementor is active, and the Gutenberg block works fully without it.

Block source lives in `src/blocks/` and is built with `@wordpress/scripts` into `build/`, which is not committed to version control. Run `npm ci && npm run build` before packaging or deploying the plugin — `register_block_type()` itself no-ops safely (no fatal, no warning) when a block's built `block.json` is missing, so an unbuilt checkout won't break the site, but blocks won't appear in the editor either.

```bash
npm ci
npm run build
```

During development, `npm run start` rebuilds on file changes, and `npm run lint:js` / `npm run lint:css` lint the block source (scoped to `src/`; the plugin's pre-existing hand-written JS/CSS in `assets/` and `tests/` predates this tooling and is intentionally not linted by it).

Requires WordPress 6.3+ (bumped from 5.7) since block registration relies on `register_block_type()` reading a built `block.json` directory.

## Verification

Run PHP syntax checks:

```bash
find . -type f -name '*.php' -not -path './.git/*' -not -path './node_modules/*' -not -path './build/*' -print0 | xargs -0 -n1 php -l
```

Run JavaScript syntax checks:

```bash
node --check assets/admin/forms.js
node --check assets/public/forms.js
node tests/forms-admin-js-smoke.js
node tests/forms-js-smoke.js
```

Run the non-mutating WordPress smoke tests with the plugin active:

```bash
wp eval-file wp-content/plugins/cea-plugin/tests/smoke.php --path=/path/to/wordpress
```

Run the database integration tests. Test responses are permanently deleted in a cleanup block:

```bash
wp eval-file wp-content/plugins/cea-plugin/tests/submissions-integration.php --path=/path/to/wordpress
```

Run the non-mutating theme blocks smoke tests (requires `npm run build` first, since it asserts `cea/form` is registered):

```bash
wp eval-file wp-content/plugins/cea-plugin/tests/blocks-smoke.php --path=/path/to/wordpress
```

Run the theme blocks integration tests. Test forms and test users are permanently deleted in a cleanup block:

```bash
wp eval-file wp-content/plugins/cea-plugin/tests/blocks-integration.php --path=/path/to/wordpress
```

## Changelog

### 0.5.0

- Added durable storage for validated form responses before delivery actions run.
- Added delivery outcome tracking and database-backed duplicate protection.
- Added private response review, filtering, retention, and permanent-deletion controls under CEA > Submissions.
- Added WordPress personal-data export, erasure, and suggested privacy-policy integration.

### 0.4.0

- Added a site-level Mailchimp Marketing API connection and audience refresh.
- Added a consent-aware Mailchimp form action with double opt-in, name mappings, and tags.
- Extended action rendering and validation with current form-field context.
