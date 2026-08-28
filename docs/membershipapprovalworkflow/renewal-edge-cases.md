# Renewal edge cases and the observed-status cache

This extension's core invariant is: **a membership already Current or
Grace must never be touched by the workflow, and a membership that is
genuinely Pending/Under Review/Approved-Pending-Payment must never be
knocked out of that status by anything except the approval screen or the
payment-completion hook.** Enforcing that turned out to require more than
the obvious `hook_civicrm_pre` guard, because of how CiviCRM core itself
processes a membership renewal. This page documents why.

## The bug: a fresh DB read isn't safe mid-renewal

`preserveWorkflowStatusOnEdit()` and `preserveProtectedStatus()` both need
to know a membership's status *as it was before whatever core operation is
currently running*. The obvious implementation is a fresh
`CRM_Core_DAO::getFieldValue()` read each time. That's wrong, for two
distinct reasons found while debugging real renewal-completion traffic.

### Reason 1: core does two saves on one membership row per request

A renewal payment completion routes through
`Civi\Membership\OrderCompleteSubscriber::updateMembershipBasedOnCompletionOfContribution()`.
For a membership not already Pending, this calls
`CRM_Member_BAO_Membership::fixMembershipStatusBeforeRenew()` and *then*
calls `Membership.create` to finalize the renewal - two separate writes to
the same row, in the same request. If the guard hooks did a fresh DB read
each time, the second call would see whatever the first call already
wrote, not the membership's true status when the request started.

### Reason 2: `fixMembershipStatusBeforeRenew()` bypasses `hook_civicrm_pre` entirely

This is the sharper problem. Traced from a real backtrace:

```
CRM_Contribute_Form_Contribution_Confirm::processPaymentOnExistingContribution()
  -> CRM_Financial_BAO_Payment::create()
  -> CRM_Contribute_BAO_Contribution::completeOrder()
  -> Civi\Membership\OrderCompleteSubscriber::onOrderComplete()
  -> ::updateMembershipBasedOnCompletionOfContribution()
  -> CRM_Member_BAO_Membership::fixMembershipStatusBeforeRenew()   <-- here
```

`fixMembershipStatusBeforeRenew()` (`CRM/Member/BAO/Membership.php`)
recalculates what the membership's status *should* be, given its
pre-renewal start/end dates, via
`CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate()` - and then,
if that differs from the current `status_id`, writes it with:

```php
$memberDAO = new CRM_Member_DAO_Membership();
$memberDAO->id = $currentMembership['id'];
$memberDAO->find(TRUE);
$memberDAO->status_id = $status['id'];
$memberDAO->save();
```

`CRM_Core_DAO::save()` only dispatches the low-level `civi.dao.preUpdate`
/ `civi.dao.postUpdate` Symfony events - **it never calls
`CRM_Utils_Hook::pre()`**. So `hook_civicrm_pre` - and therefore
`preserveWorkflowStatusOnEdit()` - never runs for this write. Setting
`skipStatusCal` on a later call doesn't help either, because that later
call isn't the one making this particular write.

The **only** interception point available is
`hook_civicrm_alterCalculatedMembershipStatus`, because
`getMembershipStatusByDate()` calls
`CRM_Utils_Hook::alterCalculatedMembershipStatus($membershipDetails,
$arguments, $membership)` right before returning - with the membership's
`id` (and its pre-write `status_id`) available in `$membership`. This is
why `preserveProtectedStatus()` has a second job beyond pinning the three
workflow statuses: it also blocks the calculator from ever handing back
`Pending` for a membership that wasn't already Pending, since a
genuinely-Current/Grace membership should never date-calculate to Pending
in the first place (`Pending` is an `is_admin` status, and this code path
calls `getMembershipStatusByDate()` with `excludeIsAdmin = TRUE`, which
excludes `is_admin` rows from consideration). If it shows up anyway,
that's a bogus target, not a real transition - so we override it back to
the membership's observed status.

## The fix: cache "observed" status per membership, per request

`Utils::$observedStatusIdCache` (`array<int,int>`, membership ID -> status
ID) is a plain in-memory static array, so it's naturally scoped to a
single PHP request/process - nothing to clear between requests.

- **`getObservedStatusId($membershipId)`** - lazily reads and caches from
  the database on first call for a given membership ID. Every subsequent
  call for the same ID within the same request returns the cached value,
  never re-reading the database.
- **`cacheObservedStatusId($membershipId, $statusId)`** - seeds the cache
  from a `status_id` the caller already has in hand (no DB read), *only
  if nothing is cached yet* for that membership. First observation wins;
  it will never overwrite an existing cache entry.

`preserveProtectedStatus()` (the `alterCalculatedMembershipStatus` hook)
calls `cacheObservedStatusId($membershipId, $membership['status_id'])`
using the `status_id` the hook itself receives - which, in the
`fixMembershipStatusBeforeRenew()` renewal-completion path, fires **before**
that function's own DAO write mutates the row. That's what makes the
sequence work correctly:

1. `alterCalculatedMembershipStatus` fires first (inside
   `fixMembershipStatusBeforeRenew()`) with the membership's true
   pre-renewal status (e.g. Current) in `$membership['status_id']`.
   `preserveProtectedStatus()` seeds the cache with it.
2. `fixMembershipStatusBeforeRenew()`'s raw DAO save may write a
   different (possibly bogus) status to the database - but
   `preserveProtectedStatus()` already blocked a bogus Pending target in
   step 1, so this write is now correct.
3. The subsequent `Membership.create` call (finalizing the renewal) fires
   `hook_civicrm_pre`. `preserveWorkflowStatusOnEdit()` calls
   `getObservedStatusId()`, which finds the cache already populated from
   step 1 - so it correctly sees "Current", not whatever the database
   might show after step 2's write, and leaves the renewal's params
   untouched.

If, for some other code path, `preserveWorkflowStatusOnEdit()` runs first
(no prior `alterCalculatedMembershipStatus` call happened for this
membership yet in this request), `getObservedStatusId()` falls back to a
plain database read - which is correct in that case, since nothing has
written to the row yet.

## Practical implications for future changes

- **Never replace `getObservedStatusId()`/`cacheObservedStatusId()` calls
  in these two hook handlers with a direct `CRM_Core_DAO::getFieldValue()`
  read.** That reintroduces both bugs above.
- **If CiviCRM core adds another code path that writes to
  `civicrm_membership` via a raw DAO save (bypassing `Membership.create`),
  it will bypass `preserveWorkflowStatusOnEdit()` the same way
  `fixMembershipStatusBeforeRenew()` does.** The calculator hook
  (`preserveProtectedStatus()`) is the only backstop for such cases -
  keep it in sync with any new failure mode discovered.
- **`skipStatusCal` is not a general-purpose fix for status-stomping
  bugs.** It only affects the specific `Membership.create` call it's
  passed to; it does nothing for a raw DAO write elsewhere in the same
  request.
