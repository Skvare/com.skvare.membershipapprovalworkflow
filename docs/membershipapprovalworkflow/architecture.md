# Architecture: hooks and control flow

## Design principle: never write workflow status/date changes to the database directly

Every status/date change in `Utils.php` goes through the standard
`Membership.update` API4 action, never a raw DAO write. (Read-only
`MembershipPayment` link lookups use its DAO because CiviCRM 6.16 has no
API4 entity for it.) This matters because CiviCRM core's own
related-membership propagation
(`CRM_Member_BAO_Membership::createRelatedMemberships()`, called
unconditionally at the end of `BAO::create()`) only runs when you go
through `create()`. Writing directly to the `civicrm_membership` table
would silently break organization -> individual membership sync.

## The `$workflowUpdateDepth` guard

`Utils::runWorkflowUpdate()` wraps every `Membership.update` API4 call this
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

## Membership type scoping

`Utils::isMembershipTypeInWorkflow($membershipTypeId)` gates every place
this extension imposes workflow behavior, based on the
`membershipapprovalworkflow_membership_types` setting (a multi-select of
membership types, configured on the settings screen below). An empty
setting means "every type" - this is also the pre-setting behavior, so
upgrading an existing site changes nothing until an administrator narrows
the list.

For a membership type left out of that list, this extension behaves as if
it weren't installed: `forcePendingOnCreate()`,
`preserveWorkflowStatusOnEdit()`, `preserveProtectedStatus()`, and
`handleContributionCompleted()` all skip it, `hook_civicrm_links` never
adds the "Membership Approval" row-action link for it, and
`Form_Approve`/`applyAction()` reject a direct hit on the approval screen
for it (`Utils::assertMembershipTypeInWorkflow()`, mirroring
`assertPrimaryMembership()`) in case the link was already bookmarked
before the type was removed from scope.

## Hook-by-hook reference

All hooks are implemented in `membershipapprovalworkflow.php` and delegate
to `CRM_Membershipapprovalworkflow_Utils`.

### `hook_civicrm_install` / `hook_civicrm_enable` / `hook_civicrm_disable` / `hook_civicrm_uninstall`

Deactivates the core "New" membership status while the extension is enabled,
then restores the active/inactive state that existed before installation on
disable or uninstall. CiviCRM's status calculator only considers `is_active = 1`
statuses, so this removes "New" from the calculator, the status picker,
and the renewal path entirely - anything that would have landed on New
now lands on Current instead (the next-highest-weight matching status).

### `hook_civicrm_links`

Adds the "Membership Approval" row-action link on the contact's Membership
tab (`membership.selector.row`, `membership.tab.row` regions), pointing at
`civicrm/membership/approve`. Suppressed when:

- the membership is inherited (`owner_membership_id` set) - only the
  primary membership gets the link;
- the membership's type is out of scope for this workflow (see "Membership
  type scoping" above);
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
present, **unless** `Utils::canUseStatusOverride($membershipId)` says
otherwise. Membership status is controlled exclusively by the approval
screen and the payment-completion hook above while a membership is still
mid-workflow - never by editing a membership directly.

`canUseStatusOverride()` allows CiviCRM's native Status Override once a
membership is no longer in one of `protectedStatusNames()` (Pending,
Pending Approval/Payment Received, Under Review, Approved/Pending
Payment), or once its type is out of scope for the workflow entirely
(`isMembershipTypeInWorkflow()`). This is the escape hatch for one-off
exceptions (e.g. pinning a VIP member Current past their normal expiry) -
staff use the standard Membership edit screen for it, exactly as they
would without this extension installed. It stays disallowed while a
membership is mid-workflow, because overriding it there would let staff
hand-set any status and skip the approval process the workflow exists to
enforce - use the approval dropdown for those instead. Adding the
`add`-form case (`$form->getVar('_id')` empty) always keeps the fields
frozen, since `forcePendingOnCreate()` resets a brand-new membership to
Pending regardless of what the form submits.

Note that even once override is allowed and set, the *next* action taken
through the approval dropdown (`applyAction()`) still clears it - see
"What 'Approved' actually sets" in `workflow-states.md`. A workflow-driven
transition is a fresh, explicit status decision that supersedes a
standing override, not something the override should block.

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
  allowed actions), a Back link, and **two** submit buttons: "Apply" and
  "Apply and Send Notification" (the latter via `addButtons()`'s
  `subName`, `CRM_Membershipapprovalworkflow_Form_Approve::
  BUTTON_SUBNAME_SEND_NOTIFICATION`).
- `postProcess()` reads which button was clicked
  (`$this->controller->getButtonName()`) to decide `$sendNotification`,
  then calls `Utils::applyAction($membershipId, $values['approval_action'],
  $sendNotification)`, which validates the action is still legal for the
  membership's current status, builds the appropriate `status_id` (and,
  for "Approved", the recalculated dates), and applies it via
  `Membership.update` API4. Only when `$sendNotification` is `TRUE` - i.e.
  "Apply and Send Notification" was clicked - does it then send one of two
  notification emails, and only if the relevant
  `membershipapprovalworkflow_notify_*` setting is also still enabled
  (**Administer > CiviMember > Membership Approval Workflow Settings**) -
  see `email-notifications.md`:
  - moving into Under Review -> `sendUnderReviewNotification()`;
  - Under Review -> (Approved or Approved/Pending Payment) ->
    `sendUnderReviewApprovedNotification()`.
  Plain "Apply" always skips the notification block entirely, regardless
  of the action taken or those settings.

## The settings screen

`civicrm/admin/membershipapprovalworkflow` (also registered in
`xml/Menu/membershipapprovalworkflow.xml`, requires `administer CiviCRM`,
linked from Administer > CiviMember via `hook_civicrm_navigationMenu()`)
is handled by `CRM_Membershipapprovalworkflow_Form_Settings`, which adds no
logic of its own - it extends core's `CRM_Admin_Form_Setting` directly.
Everything (which fields appear, their defaults, saving them) is driven by
the settings in `settings/MembershipApprovalWorkflow.setting.php` tagged
`'settings_pages' => ['membershipapprovalworkflow' => [...]]` - that key
must match the last segment of this page's URL
(`CRM_Admin_Form_SettingTrait::getSettingPageFilter()`), which is how the
base class knows which settings belong on this particular page:
`membershipapprovalworkflow_membership_types` (see "Membership type
scoping" above) plus the five `membershipapprovalworkflow_notify_*`
notification toggles. Adding another setting later is just adding another
entry with the same `settings_pages` key - no form code changes needed.
