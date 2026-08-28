# Email notifications

## What triggers it

`Utils::applyAction()` sends one notification email: when a membership
moves out of **Under Review** into either **Approved/Pending Payment** or
**Approved** (Current) via the approval screen. It does *not* fire for:

- Pending -> Under Review (no notification defined for this step),
- the automatic Approved/Pending Payment -> Approved transition on
  contribution completion (`markCurrentOnPayment()` does not call the
  notification method),
- any transition made outside `applyAction()` (there isn't one, currently
  - see `architecture.md`).

Both Under-Review outcomes (Approved/Pending Payment and Approved) share
**one** message template; the template branches on which outcome it was to
word the copy differently.

## The message template

Registered as a managed entity in
`managed/MessageTemplate_UnderReviewApproved.mgd.php`, `workflow_name`
`membershipapprovalworkflow_under_review_approved`. Content lives in three
companion files in the same directory:

- `under_review_approved_subject.tpl`
- `under_review_approved_html.tpl`
- `under_review_approved_text.tpl`

Following the same reserved/editable pair pattern CiviCRM core uses for
its own system workflow templates (e.g.
`ext/standaloneusers/managed/MessageTemplate_PasswordReset.mgd.php`), two
rows are created:

- **Reserved copy** (`is_reserved = TRUE`, `is_default = FALSE`) - kept in
  sync with the `.tpl` files on every extension upgrade (`'update' =>
  'always'`). This is the "restore to original" source.
- **Editable copy** (`is_reserved = FALSE`, `is_default = TRUE`) - the one
  actually used to send mail (`is_default = TRUE`). Never overwritten by
  an upgrade (`'update' => 'never'`), so an admin's customizations from
  **Administer > Communications > Message Templates** survive.

After changing the `.tpl` files, **you must clear caches** (`cv flush` or
Administer > System Status > "Clear caches") for CiviCRM to pick up the
change - a managed entity is only synced on cache rebuild, not on file
save.

## Tokens available in the template

Passed via `tplParams` in `Utils::sendUnderReviewApprovedNotification()`:

| Token | Value |
|---|---|
| `{$membershipTypeName}` | The membership type's name. |
| `{$newStatusName}` | Either `Approved/Pending Payment` or `Current` (the raw status name) - used with `{if $newStatusName eq 'Approved/Pending Payment'}` to branch the copy. |
| `{$membershipStartDate}` | The post-update `start_date` (only meaningful for the "Approved" outcome; typically empty for Approved/Pending Payment). Use with the `|crmDate` modifier. |
| `{$membershipEndDate}` | The post-update `end_date`, same caveat. |

Standard CiviCRM contact/domain tokens are also available (the template
passes `contactId`, which populates `tokenContext`), e.g.
`{contact.email_greeting_display}`, `{domain.name}`.

## Recipient resolution

`sendUnderReviewApprovedNotification()` looks up the contact's **primary**
email via `CRM_Contact_BAO_Contact::getPrimaryEmail($contactId, TRUE)` -
the `TRUE` (`$polite`) argument means a contact with `do_not_email` set,
or whose primary email is `on_hold`, is skipped rather than emailed. If no
deliverable email is found, the notification is silently skipped and
logged at `info` level (`Civi::log()`) - this does **not** fail the
approval action.

## Failure handling

`CRM_Core_BAO_MessageTemplate::sendTemplate()` is wrapped in a
`try`/`catch (CRM_Core_Exception $e)`. A send failure (e.g. the template
row is somehow missing, or the mail transport errors) is logged at
`error` level and otherwise swallowed - **the membership status change
has already been committed by the time this runs**, so a notification
failure never rolls back or blocks the approval itself.

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
