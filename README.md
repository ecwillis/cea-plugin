# CEA Plugin

CEA Plugin provides provider-neutral SMTP delivery and a small, schema-driven WordPress form builder.

## Requirements

- WordPress 5.7 or later
- PHP 7.4 or later

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

If every enabled action fails, the visitor receives a generic retry message and the idempotency token is released. Recent failure summaries appear to administrators when the form is next edited; submission values and secrets are excluded from those summaries.

## Confirmation behavior

A form can show an inline success message or redirect to a URL on the same WordPress site. External success redirects are rejected during configuration normalization.

Validation errors use a post/redirect/get flow and are stored in short-lived, one-time transients. Sanitized values are redisplayed so visitors can correct the highlighted fields.

## Security and privacy

Public submissions include:

- A form-specific WordPress nonce
- A honeypot field
- A minimum-fill-time check
- A per-form/client rate limit
- A browser-generated idempotency token
- Schema-based input allowlisting
- Type-specific sanitization and validation

Only published forms accept submissions. Unexpected fields are discarded. Email recipients and headers are validated, webhook requests use `wp_safe_remote_post()`, Mailchimp requests use `wp_safe_remote_request()` against a validated Mailchimp data-center host, and only same-site confirmation redirects are accepted.

Mailchimp requests include only the mapped email, optional mapped name values, and configured tags. API errors stored for administrators exclude request bodies, credentials, and subscriber email addresses.

Submission entries are not stored. Short-lived transients may temporarily contain sanitized values after a validation error and are deleted when consumed or expire after five minutes. WordPress options retain SMTP and Mailchimp settings, and form definitions remain as private posts if the plugin is deactivated or removed; no destructive uninstall routine runs automatically.

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

Limits and transport:

- `cea_form_max_fields`
- `cea_form_max_actions`
- `cea_form_rate_limit`
- `cea_form_allow_insecure_webhook`

Custom actions register a definition with `CEA_Form_Action_Registry::register()`. Each definition supplies a label plus sanitize, validate, render, and execute callbacks. Execute callbacks must return `true` or `WP_Error`.

## Verification

Run PHP syntax checks:

```bash
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
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

## Changelog

### 0.4.0

- Added a site-level Mailchimp Marketing API connection and audience refresh.
- Added a consent-aware Mailchimp form action with double opt-in, name mappings, and tags.
- Extended action rendering and validation with current form-field context.
