# Signup and renewal scenarios: which status ends up where

This walks through the concrete membership-status outcome for the scenarios
that actually come up in practice: a brand-new signup paid immediately by
card, a brand-new signup with "pay later", and renewing a membership that has
already **Expired**. The third case involves a site-specific CiviCRM core
customization (renewing an Expired membership creates a brand-new membership
row) and a separate, already-installed third-party extension
(`nz.co.fuzion.membershiprenewalcontrol`) that independently affects the
same kind of renewal on a different code path; the extension's behavior is
documented here from reading its code, **not modified** (per request - that
part is analysis only).

## Scenario 1: New signup, online payment by card, paid immediately

No pre-existing membership row - this is a plain `Membership.create` with
`$op === 'create'`.

1. `hook_civicrm_pre` (Membership, `create`) fires ->
   `Utils::forcePendingOnCreate()` forces `status_id` = **Pending**,
   `skipStatusCal = TRUE`, `start_date`/`end_date` blanked - *regardless* of
   how it will be paid (Requirement 1). The membership row is created as
   Pending no matter what.
2. The contribution linked via `MembershipPayment` completes synchronously
   (card processor confirms immediately, in the same request) ->
   `hook_civicrm_post` (Contribution) sees a fresh transition to
   `Completed` -> `Utils::handleContributionCompleted()` finds the
   membership still sitting in `Pending` -> calls
   `Utils::markPendingApprovalPaymentReceived()`.
3. **Final status: `Pending Approval/Payment Received`.** Still no
   `start_date`/`end_date` - the membership still hasn't been reviewed, but
   staff can see payment is already in.
4. From there, staff move it through the normal dropdown: `Pending Approval/
   Payment Received` -> `Under Review` -> (payment already received, so
   `getAllowedActions()` offers **only** "Approved") -> `Current`.

## Scenario 2: New signup, "pay later"

Same creation step as Scenario 1:

1. `forcePendingOnCreate()` forces **Pending**, no dates - identical to
   Scenario 1 at this point; the extension can't tell the two apart yet
   (Requirement 1 is explicit that it shouldn't).
2. The linked contribution is created `Pending` (or "In Progress") and stays
   that way - no synchronous completion, so `handleContributionCompleted()`
   never fires.
3. **Final status: stays `Pending`** for as long as the contribution remains
   unpaid.
4. Staff move it: `Pending` -> `Under Review` -> (no completed payment yet,
   so `getAllowedActions()` offers **only** "Approved/Pending Payment") ->
   `Approved/Pending Payment`.
5. Whenever the pay-later contribution is eventually completed (online
   payment, check clearing, or a back-office "Record Payment") -
   `handleContributionCompleted()` sees the membership is `Approved/Pending
   Payment` -> `Utils::markCurrentOnPayment()` -> **Current**, `start_date` =
   the contribution's `receive_date`.

   If staff instead complete the pay-later payment *before* ever touching
   the membership (it's still sitting in plain `Pending`), the same hook
   takes the Scenario-1 branch instead and bumps it to `Pending
   Approval/Payment Received` - the two scenarios converge at that point.

## Scenario 3: Returning member with an Expired same-type membership

**Confirmed current behavior (2026-09-04):** CiviCRM core on this site has
been customized so that renewing an Expired membership creates a **brand
new membership row** via a genuine `Membership.create` with `$op ===
'create'` - not an in-place edit of the old (Expired) row. That means it
reaches this extension exactly like Scenarios 1-2 above:
`Utils::forcePendingOnCreate()` runs, forces `status_id` = Pending, and (if
paid immediately) `handleContributionCompleted()` would - prior to the fix
below - promote it straight to `Pending Approval/Payment Received`, exactly
as if it were a first-time application. That's wrong: the contact already
held this membership type before, so there's nothing new for staff to
review.

### Policy: skip the review queue for a returning same-type member

`Utils::handleContributionCompleted()`'s `Pending` branch now calls
`Utils::isRenewalOfExpiredMembership($membershipId)` before deciding what
  to do: it checks whether the same contact holds *another* membership record
  of the *same* `membership_type_id` currently sitting in `Expired`. This is
  deliberately a returning-member policy, not an attempt to prove a direct
  renewal relationship: no matching contribution, date window, or renewal
  link is required. If the historical Expired row exists, the application is
  treated as a renewal rather than a first-time application:

- **Renewal of an Expired membership, paid (immediately or once a
  pay-later contribution eventually completes while still Pending):** goes
  straight to **`Current`** via `Utils::markCurrentOnPayment()` - the same
  helper used for the "Approved/Pending Payment -> Current" auto-transition
  - **never** `Pending Approval/Payment Received`, and never seen on the
  approval screen.
- **First-time application, paid** (no other same-type Expired row):
  unchanged from Scenario 1 - goes to `Pending Approval/Payment Received`
  and still needs staff review.
- **Renewal of an Expired membership, pay-later, not yet paid:** still
  starts `Pending` like any other pay-later signup (Scenario 2) - the check
  only runs once a payment actually completes. If staff move it to `Under
  Review` before payment arrives, it's indistinguishable from a normal
  pay-later application from that point on and follows the normal dropdown
  (`Approved/Pending Payment` -> auto-`Current` on payment, same as
  Scenario 2's ending - it doesn't skip anything, since by then it's no
  longer sitting in plain `Pending` for the check to catch).

This does **not** touch `forcePendingOnCreate()` - the membership still
starts life as plain `Pending` either way; the only change is which status
it's promoted *to* once payment is confirmed while it's still unreviewed.

### Open question: how does this relate to `nz.co.fuzion.membershiprenewalcontrol`?

A prior version of this document analyzed a *different* mechanism for this
same scenario, based on reading the already-installed
`nz.co.fuzion.membershiprenewalcontrol` extension's code (below) under
**default, unmodified** core renewal behavior (an in-place `edit` of the
Expired row). That analysis is kept below for reference since it's an
accurate reading of that extension's code, but it evidently does not
describe what this site's core customization now does for the online
renewal path being discussed here (a real `create`, not an edit converted
to an insert). The two may be covering genuinely different entry points
(e.g. front-end online renewal vs. the back-office "Renew Membership" admin
form), or the core customization may have superseded the extension's role
for this path entirely - that hasn't been verified. **Before relying on
both being simultaneously accurate, confirm which code path a real
back-office renewal actually takes** (the extension's own
`CRM_Core_Error::debug_var()` calls are the fastest way to check whether
its `hook_civicrm_pre` still fires at all for that flow).

### `nz.co.fuzion.membershiprenewalcontrol` - what it actually does

Read directly from
`.../extensions/contrib/nz.co.fuzion.membershiprenewalcontrol/membershiprenewalcontrol.php`
(unmodified, per request). Its `hook_civicrm_pre` only acts on `Membership`
`edit` calls, and only when the membership's *existing* `status_id` name is
one of: `Expired`, `Cancelled`, `Deceased`, `Not Fullfilled`, `Denied`,
`Suspended`, `Cancelled by Member`, `Removed` (its own hardcoded
"non-renewable" list - none of this extension's workflow statuses are in
it). Given that:

```php
if (existing status is non-renewable
    && !empty($params['end_date'])
    && new end_date > existing end_date) {
  // Branch A
  unset($params['id'], $params['membership_id']);
  $id = NULL;
  $params['join_date'] = $params['membership_start_date'] = $params['start_date'];
  $params['status_id'] = <id of status literally named 'current'>;
}
elseif (!empty($params['contribution'])
        && $params['contribution']->contribution_status_id == <Pending contribution status>
        && existing status is non-renewable) {
  // Branch B
  $params['start_date'] = $params['join_date'] = today;
  $params['contact_id'] = <existing contact_id>;
  $params['status_id'] = <id of status with label 'Pending'>;
  unset($params['id'], $params['membership_id'], $params['end_date']);
  $id = NULL;
}
```

Both branches **strip `id`/`membership_id` and null out `$id`**, which turns
what CiviCRM dispatched as an "edit" into an **INSERT of a brand-new
membership row** at the DAO layer - while the hook dispatch itself is still
labeled `edit` for every other listener in the same call. That distinction
matters a lot for how this extension reacts (next section).

- **Branch A** fires when the renewal call already carries a real
  `end_date` later than the expired membership's old one - the normal shape
  of a renewal whose payment has already been confirmed (online card
  payment completing synchronously, or a back-office renewal after payment
  was already recorded). It forces the new row straight to **Current**,
  looked up by status **name** `current`.
- **Branch B** fires when the call instead carries a `$params['contribution']`
  object whose `contribution_status_id` is `Pending` - this is the shape
  produced by the back-office "Renew Membership" admin form
  (`CRM_Member_Form_Membership`, which builds and attaches that pending
  contribution object to `$params['contribution']` itself before calling
  `Membership.create`) when staff pick "Pay Later" for a manual renewal. It
  forces the new row to whichever status has **label** `Pending`.

### Why our own workflow doesn't get a say

`civicrm_extension` on this site (checked directly, read-only, not
modified) shows load order by `id`:

| id | extension |
|---|---|
| 5 | `nz.co.fuzion.membershiprenewalcontrol` |
| 40 | `contactmembershiplog` |
| 48 | `com.skvare.fixrelatedmembershipstatus` |
| 59 | `com.skvare.membershipapprovalworkflow` |

CiviCRM builds its module-hook list from `civicrm_extension` with no
`ORDER BY` (`CRM_Extension_Mapper::getActiveModuleFiles()`), so in practice
modules are visited in that same `id` order - meaning
`membershiprenewalcontrol`'s `hook_civicrm_pre` runs **before** ours in the
same dispatch. Both extensions' legacy hook functions are invoked through
the same shared, by-reference `$id`/`$params` (`CRM_Utils_Hook::runHooks()`),
so by the time our `membershipapprovalworkflow_civicrm_pre()` runs:

- `$id` has already been set to `NULL` by the other extension.
- `Utils::preserveWorkflowStatusOnEdit($id, $params)` is called with that
  `NULL` `$id`, hits its very first guard (`if (!$membershipId ...) return;`),
  and does **nothing**.
- The hook dispatch's `$op` is still `'edit'` (neither extension mutates it),
  so `Utils::forcePendingOnCreate()` - which only runs when `$op ===
  'create'` - is **never invoked either**, even though the actual database
  write ends up being an INSERT.

Net effect, **if this extension's `hook_civicrm_pre` is actually the one
that fires for a given renewal** (see the open question above - confirmed
NOT the case for the online renewal path covered by Scenario 3's fix
above): this extension's entire approval workflow would be bypassed for
that call.

- **Renewed with a completed/immediate payment (Branch A):** the member
  ends up **Current** immediately - never Pending, never Under Review,
  never seen on the "Membership Approval" screen at all.
- **Renewed with "Pay Later" via the back-office admin form (Branch B):**
  the member ends up **Pending** (by label) - which, by coincidence, is the
  same status name this extension itself uses, so it *looks* consistent
  with Scenario 2 above and staff can drive it through the normal dropdown
  from there. But note this Pending row was created directly by the other
  extension's INSERT, bypassing `forcePendingOnCreate()` - functionally
  equivalent here only because the resulting status name happens to match.

None of this needs `Pending Approval/Payment Received` at all: Branch A
skips straight to Current, and Branch B (pay-later) can only ever recreate
plain Pending, never the "already paid" variant, since it only triggers
when the attached contribution is itself `Pending`.

### Practical implications

- For the online renewal path, the confirmed behavior (Scenario 3's fix
  above) is now the intended one: payment already received on a same-type
  renewal skips straight to Current, no staff review. If some *other*
  renewal path (e.g. the back-office admin form) still routes through
  `nz.co.fuzion.membershiprenewalcontrol`'s edit-to-insert conversion
  instead, that path would bypass this extension entirely rather than
  going through `isRenewalOfExpiredMembership()` - worth confirming
  whether that's acceptable or whether that path also needs to route
  through a real `create` the way the online path now does.
- This nz.co.fuzion analysis is read from source, not from a live-traced
  request. The extension
  itself calls `CRM_Core_Error::debug_var()` at every branch - enabling
  debug logging and renewing a real Expired membership (both by card and by
  Pay Later) is the fastest way to confirm the exact `$params` shape on
  this site's actual CiviCRM version before relying on this document for a
  behavior change.
- `com.skvare.fixrelatedmembershipstatus` (also active, id 48) is **not**
  part of this automatic flow - it's an admin-triggered batch tool
  (`civicrm/admin/member/fixrelatedstatus`) for reconciling an inherited
  membership's status against its owner after the fact. It doesn't hook
  into `create`/`edit` at all, so it has no bearing on any scenario above
  unless someone runs it manually.
