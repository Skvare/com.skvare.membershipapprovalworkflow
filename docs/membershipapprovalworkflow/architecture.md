# Architecture: hooks and control flow

## Design principle: never write to the database directly

Every status/date change in `Utils.php` goes through the standard
`Membership.create` API (via `civicrm_api3('Membership', 'create', ...)`),
never a raw DAO write. This matters because CiviCRM core's own
related-membership propagation
(`CRM_Member_BAO_Membership::createRelatedMemberships()`, called
unconditionally at the end of `BAO::create()`) only runs when you go
through `create()`. Writing directly to the `civicrm_membership` table
would silently break organization -> individual membership sync.

## The `$workflowUpdateDepth` guard

`Utils::runWorkflowUpdate()` wraps every `Membership.create` call this
extension makes (from `applyAction()` and `markCurrentOnPayment()`) with a
depth counter (`$workflowUpdateDepth`). While that counter is above zero,
`preserveWorkflowStatusOnEdit()` (the `hook_civicrm_pre` guard, below)
does nothing - it assumes any edit happening while the workflow itself is
mid-update is authorized and shouldn't be second-guessed. Without this,
the workflow's own status-setting calls would immediately be reverted by
its own guard hook. It's a counter rather than a boolean because core
recursively creates/updates inherited memberships within the same API
call (`createRelatedMemberships()`), so a simple "in progress" flag set
and cleared once wouldn't survive re-entrancy correctly.

## Hook-by-hook reference

All hooks are implemented in `membershipapprovalworkflow.php` and delegate
to `CRM_Membershipapprovalworkflow_Utils`.

### `hook_civicrm_install` / `hook_civicrm_uninstall`

Deactivates the core "New" membership status on install (reactivates on
uninstall). CiviCRM's status calculator only considers `is_active = 1`
statuses, so this removes "New" from the calculator, the status picker,
and the renewal path entirely - anything that would have landed on New
now lands on Current instead (the next-highest-weight matching status).

### `hook_civicrm_links`

Adds the "Membership Approval" row-action link on the contact's Membership
tab (`membership.selector.row`, `membership.tab.row` regions), pointing at
`civicrm/membership/approve`. Suppressed when:

- the membership is inherited (`owner_membership_id` set) - only the
  primary membership gets the link;
- `Utils::getAllowedActions()` returns nothing for the membership's
  current status (i.e. the workflow is finished for this membership).

### `hook_civicrm_pre` (Membership, `create`)

Calls `Utils::forcePendingOnCreate()`. Forces every new, non-inherited
membership to `status_id` = Pending with empty `start_date`/`end_date`,
regardless of how it was submitted (pay-later, immediate payment, back
office). Inherited memberships (`owner_membership_id` set) are skipped -
core's own `createRelatedMemberships()` manages those.

### `hook_civicrm_pre` (Membership, `edit`)

Calls `Utils::preserveWorkflowStatusOnEdit()`. If the membership's
*observed* status (see `renewal-edge-cases.md` for what "observed" means
and why it's not a plain database read) is one of the four
workflow-owned statuses (`Utils::protectedStatusNames()`: Pending, Pending
Approval/Payment Received, Under Review, Approved/Pending Payment),
this pins `status_id` back to that value and forces `skipStatusCal =
TRUE`, `is_override = 0` - i.e. nothing except this extension's own
`applyAction()`/`markCurrentOnPayment()`/`markPendingApprovalPaymentReceived()`
(which bypass this via the `$workflowUpdateDepth` guard) can move a
membership out of those four statuses. Current and Grace are **not**
workflow-owned, so an edit against a Current/Grace membership (a normal
renewal extending `end_date`) passes through untouched.

### `hook_civicrm_pre` (Contribution)

Stashes the contribution's *previous* `contribution_status_id` in
`Civi::$statics['membershipapprovalworkflow']['prevContributionStatus']`,
keyed by contribution ID. This is read back in `hook_civicrm_post` to tell
a genuine Pending -> Completed transition apart from a contribution that
was already Completed being saved again (e.g. an unrelated field edit).

### `hook_civicrm_post` (Contribution)

If the contribution's new status is Completed, and it *wasn't* already
Completed before this save (per the stashed value above), calls
`Utils::handleContributionCompleted()`. That finds every membership linked
to the contribution via `MembershipPayment` and, depending on that
membership's current status:

- `Approved/Pending Payment` -> `Utils::markCurrentOnPayment()` moves it to
  Current with `start_date` = the contribution's `receive_date`.
- `Pending` (still unreviewed) -> `Utils::markPendingApprovalPaymentReceived()`
  moves it to `Pending Approval/Payment Received` (status only, no dates
  yet - it still hasn't been approved).

### `hook_civicrm_buildForm`

On the core `CRM_Member_Form_Membership` form only: freezes the
`status_id`, `is_override`, and `status_override_end_date` fields if
present. Membership status is controlled exclusively by the approval
screen and the payment-completion hook above - never by editing a
membership directly.

### `hook_civicrm_alterCalculatedMembershipStatus`

Calls `Utils::preserveProtectedStatus()`. Two jobs - see
`renewal-edge-cases.md` for the full story on why job 2 exists:

1. Keeps Pending/Under Review/Approved-Pending-Payment memberships from
   ever being reassigned by CiviCRM's date-based status calculator (the
   nightly cron job, membership create/edit, or the renewal/order-complete
   flow all funnel through this calculator).
2. Blocks the calculator from ever demoting an already Current/Grace
   membership down to Pending - a defensive fix for a specific core
   renewal code path that bypasses `hook_civicrm_pre` entirely.

### `hook_civicrm_enable`

Standard civix boilerplate; no custom logic.

## The approval screen

`civicrm/membership/approve` (registered in
`xml/Menu/membershipapprovalworkflow.xml`, requires the `edit memberships`
permission) is handled by `CRM_Membershipapprovalworkflow_Form_Approve`:

- `preProcess()` loads the membership, computes `hasReceivedPayment()`
  once, and passes it into both `getAllowedActions()` (for its current
  status) and `statusSequence()` (for the workflow-sequence help text) so
  the dropdown and the help text agree on whether payment is already in -
  see `workflow-states.md`.
- `buildQuickForm()` adds the `approval_action` select (only if there are
  allowed actions) and a Back link.
- `postProcess()` calls `Utils::applyAction($membershipId,
  $values['approval_action'])`, which validates the action is still legal
  for the membership's current status, builds the appropriate `status_id`
  (and, for "Approved", the recalculated dates), and applies it via
  `Membership.create`. If the transition is Under Review -> (Approved or
  Approved/Pending Payment), it also sends the approval notification
  email - see `email-notifications.md`.
