# Membership Approval Workflow

Technical documentation for `com.skvare.membershipapprovalworkflow`, a CiviCRM
extension that replaces CiviCRM's normal "pay and you're active" membership
flow with a staff-driven approval process: **Pending -> Under Review ->
Approved/Pending Payment -> Approved (Current)**.

This directory documents how the extension works internally - the companion
`README.md` at the extension root is the short, user-facing summary.

## Contents

- [`workflow-diagram.html`](./workflow-diagram.html) - a single-file, no
  dependencies visual diagram of every status and transition (manual
  vs. automatic, which ones email the applicant and under which setting).
  Open it directly in a browser. Start here for a quick visual overview
  before the detailed pages below.
- [`workflow-states.md`](./workflow-states.md) - the four workflow statuses,
  the exact allowed transitions, and where a membership exits the workflow
  entirely.
- [`architecture.md`](./architecture.md) - every hook the extension
  implements, what it does, and why it exists.
- [`renewal-edge-cases.md`](./renewal-edge-cases.md) - CiviCRM core quirks
  this extension has to work around during membership renewal, and the
  request-scoped caching mechanism built to handle them. Read this before
  changing anything in `preserveWorkflowStatusOnEdit()` or
  `preserveProtectedStatus()`.
- [`email-notifications.md`](./email-notifications.md) - the three
  notification message templates: what triggers each, what tokens they
  expose, how to customize them, and how the on/off settings gate each one.
- [`signup-scenarios.md`](./signup-scenarios.md) - walks the concrete status
  outcome for card-paid-immediately vs. pay-later signups, and what happens
  when an already-Expired membership is renewed (where a separate,
  third-party extension takes over before this one gets a say).

## At a glance

| Piece | File |
|---|---|
| All workflow logic | `CRM/Membershipapprovalworkflow/Utils.php` |
| Hook implementations | `membershipapprovalworkflow.php` |
| Approval action screen | `CRM/Membershipapprovalworkflow/Form/Approve.php` + `templates/CRM/Membershipapprovalworkflow/Form/Approve.tpl` |
| Approval screen route | `xml/Menu/membershipapprovalworkflow.xml` (`civicrm/membership/approve`) |
| Notification settings screen | `CRM/Membershipapprovalworkflow/Form/Settings.php` + `templates/CRM/Membershipapprovalworkflow/Form/Settings.tpl` (`civicrm/admin/membershipapprovalworkflow`) |
| Notification on/off settings | `settings/MembershipApprovalWorkflow.setting.php` (`membershipapprovalworkflow_notify_*`) |
| Custom membership statuses | `managed/MembershipStatus.mgd.php` (Under Review, Approved/Pending Payment, Pending Approval/Payment Received) |
| "Under Review" notification email | `managed/MessageTemplate_UnderReview.mgd.php` + `managed/under_review_*.tpl` |
| "Approved" / "Approved/Pending Payment" notification email | `managed/MessageTemplate_UnderReviewApproved.mgd.php` + `managed/under_review_approved_*.tpl` |

## Requirement numbers referenced in code comments

The code comments cite numbered requirements from the original spec for
this extension. Not all numbers are represented in code comments, but the
ones that are:

- **Requirement 1** - every new membership starts Pending, regardless of
  payment method (`Utils::forcePendingOnCreate()`).
- **Requirement 3** - the approval action is a dedicated screen, separate
  from the core Membership edit form (`Form/Approve.php`).
- **Requirement 4** - the core "New" status is never used
  (`membershipapprovalworkflow_civicrm_install()`).
- **Requirements 2D / 5** - a membership only becomes Current after its
  linked contribution completes, and only if it was already
  `Approved/Pending Payment` (`Utils::handleContributionCompleted()`).
- **Requirement 7** - Grace/Expired date-based transitions keep working
  normally once a membership reaches Current.
