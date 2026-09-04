# Email notifications

## What triggers it

`Utils::applyAction()` sends one notification email for each of the three
status-changing actions on the approval screen:

| Action (from -> to) | Template `workflow_name` | Gated by setting |
|---|---|---|
| Pending or Pending Approval/Payment Received -> Under Review | `membershipapprovalworkflow_under_review` | `membershipapprovalworkflow_notify_under_review` |
| Under Review -> Approved/Pending Payment | `membershipapprovalworkflow_under_review_approved` | `membershipapprovalworkflow_notify_approved_pending_payment` |
| Under Review -> Approved (Current) | `membershipapprovalworkflow_under_review_approved` (same template as above - branches on `$newStatusName`) | `membershipapprovalworkflow_notify_approved` |

Each setting is checked at the top of the corresponding `Utils::send*()`
method (`Civi::settings()->get($settingName)`) - if disabled, the method
returns immediately without looking up the contact's email or touching the
message template at all. All three default to enabled. An administrator
toggles them from **Administer > CiviMember > Membership Approval Workflow
Notifications** (`civicrm/admin/membershipapprovalworkflow` -
`CRM_Membershipapprovalworkflow_Form_Settings`, backed entirely by the
`settings_pages` metadata in
`settings/MembershipApprovalWorkflow.setting.php` via core's
`CRM_Admin_Form_Setting`).

It does *not* fire for:

- the automatic Approved/Pending Payment -> Approved transition on
  contribution completion (`markCurrentOnPayment()` does not call either
  notification method),
- the automatic Pending -> Pending Approval/Payment Received transition, or
  the Expired-renewal fast path straight to Current (same reason - see
  `signup-scenarios.md`),
- any transition made outside `applyAction()` (there isn't one, currently
  - see `architecture.md`).

The two Under-Review-exit outcomes (Approved/Pending Payment and Approved)
share **one** message template; the template branches on which outcome it
was to word the copy differently. They are still gated by two *separate*
settings, decided in code (`sendUnderReviewApprovedNotification()` picks
the setting name from `$newStatusName`) before the shared template is even
looked up - so an admin can, for example, keep the "approved and active"
email on while turning off the "approved, pending payment" one.

## The message templates

### Under Review (`membershipapprovalworkflow_under_review`)

Registered as a managed entity in `managed/MessageTemplate_UnderReview.mgd.php`.
Content lives in:

- `under_review_subject.tpl`
- `under_review_html.tpl`
- `under_review_text.tpl`

### Approved / Approved-Pending-Payment (`membershipapprovalworkflow_under_review_approved`)

Registered as a managed entity in
`managed/MessageTemplate_UnderReviewApproved.mgd.php`. Content lives in
three companion files in the same directory:

- `under_review_approved_subject.tpl`
- `under_review_approved_html.tpl`
- `under_review_approved_text.tpl`

Both templates follow the same reserved/editable pair pattern CiviCRM core
uses for its own system workflow templates (e.g.
`ext/standaloneusers/managed/MessageTemplate_PasswordReset.mgd.php`): two
rows are created per template:

- **Reserved copy** (`is_reserved = TRUE`, `is_default = FALSE`) - kept in
  sync with the `.tpl` files on every extension upgrade (`'update' =>
  'always'`). This is the "restore to original" source.
- **Editable copy** (`is_reserved = FALSE`, `is_default = TRUE`) - the one
  actually used to send mail (`is_default = TRUE`). Never overwritten by
  an upgrade (`'update' => 'never'`), so an admin's customizations from
  **Administer > Communications > Message Templates** survive.

### 1.0.2 payment-link migration

Version 1.0.2 includes an upgrader for existing editable copies created by
older releases. It replaces only the exact legacy NAATP dashboard URL with a
portable `{crmURL}` token in `msg_html` and `msg_text`; all other customized
template content is retained. Editable templates that do not contain that
exact legacy URL are left untouched.

After changing the `.tpl` files, **you must clear caches** (`cv flush` or
Administer > System Status > "Clear caches") for CiviCRM to pick up the
change - a managed entity is only synced on cache rebuild, not on file
save.

## Tokens available in the templates

Passed via `tplParams`:

`Utils::sendUnderReviewNotification()`:

| Token | Value |
|---|---|
| `{$membershipTypeName}` | The membership type's name. |

`Utils::sendUnderReviewApprovedNotification()`:

| Token | Value |
|---|---|
| `{$membershipTypeName}` | The membership type's name. |
| `{$newStatusName}` | Either `Approved/Pending Payment` or `Current` (the raw status name) - used with `{if $newStatusName eq 'Approved/Pending Payment'}` to branch the copy, and to pick which setting gates the send (see above). |
| `{$membershipStartDate}` | The post-update `start_date` (only meaningful for the "Approved" outcome; typically empty for Approved/Pending Payment). Use with the `|crmDate` modifier. |
| `{$membershipEndDate}` | The post-update `end_date`, same caveat. |

Standard CiviCRM contact/domain tokens are also available in both (each
method passes `contactId`, which populates `tokenContext`), e.g.
`{contact.email_greeting_display}`, `{domain.name}`.

## Recipient resolution

Both `send*()` methods look up the contact's **primary** email via
`CRM_Contact_BAO_Contact::getPrimaryEmail($contactId, TRUE)` - the `TRUE`
(`$polite`) argument means a contact with `do_not_email` set, or whose
primary email is `on_hold`, is skipped rather than emailed. If no
deliverable email is found, the notification is silently skipped and
logged at `info` level (`Civi::log()`) - this does **not** fail the
approval action.

## Failure handling

Both methods wrap `CRM_Core_BAO_MessageTemplate::sendTemplate()` in a
`try`/`catch (CRM_Core_Exception $e)`. A send failure (e.g. the template
row is somehow missing, or the mail transport errors) is logged at
`error` level and otherwise swallowed - **the membership status change
has already been committed by the time this runs**, so a notification
failure never rolls back or blocks the approval itself.

## Turning a notification off

**Administer > CiviMember > Membership Approval Workflow Notifications**
(`civicrm/admin/membershipapprovalworkflow`) has one checkbox per
notification. Unchecking it does not touch the message template or the
underlying status change - it only stops that specific `send*()` call from
running, checked via `Civi::settings()->get()` before anything else in the
method happens (no email lookup, no template render). The three settings
(`membershipapprovalworkflow_notify_under_review`,
`membershipapprovalworkflow_notify_approved_pending_payment`,
`membershipapprovalworkflow_notify_approved`) are declared in
`settings/MembershipApprovalWorkflow.setting.php` and default to enabled.

## Customizing the email

For a one-off wording change: edit the row directly from **Administer >
Communications > Message Templates** in the CiviCRM UI (the editable copy,
`is_default = TRUE`) - no extension changes needed, and it survives
upgrades.

For a change that should ship with the extension: edit the `.tpl` files in
`managed/`, then clear caches. Because the reserved copy is `'update' =>
'always'`, it will be overwritten; the editable copy will **not** be
touched automatically (by design) - if the intent is to push the new
wording to sites that have never customized theirs, note that an admin's
already-customized editable copy won't be overwritten by an upgrade.
