<?php

use Civi\Api4\MessageTemplate;
use PHPUnit\Framework\Attributes\Group;

/**
 * @group headless
 */
#[Group('headless')]
class CRM_Membershipapprovalworkflow_UtilsTest extends CRM_Membershipapprovalworkflow_Base {

  public function testPrimaryMembershipIsAccepted(): void {
    CRM_Membershipapprovalworkflow_Utils::assertPrimaryMembership([
      'id' => 1,
      'owner_membership_id' => NULL,
    ]);
    $this->addToAssertionCount(1);
  }

  public function testInheritedMembershipIsRejected(): void {
    $this->expectException(CRM_Core_Exception::class);
    CRM_Membershipapprovalworkflow_Utils::assertPrimaryMembership([
      'id' => 2,
      'owner_membership_id' => 1,
    ]);
  }

  public function testLegacyStatusStateIsSeededOnlyWhenMissing(): void {
    $settings = Civi::settings();
    $settingName = CRM_Membershipapprovalworkflow_Utils::SETTING_NEW_STATUS_WAS_ACTIVE;
    $settings->revert($settingName);

    CRM_Membershipapprovalworkflow_Utils::seedLegacyNewStatusState();
    $this->assertTrue($settings->get($settingName));

    $settings->set($settingName, FALSE);
    CRM_Membershipapprovalworkflow_Utils::seedLegacyNewStatusState();
    $this->assertFalse($settings->get($settingName));
  }

  public function testEditableTemplateLegacyPaymentUrlIsMigrated(): void {
    $templates = MessageTemplate::get(FALSE)
      ->addSelect('id')
      ->addWhere('workflow_name', '=', 'membershipapprovalworkflow_under_review_approved')
      ->addWhere('is_reserved', '=', FALSE)
      ->execute();
    $template = $templates->first();
    $this->assertNotEmpty($template);
    $legacyUrl = 'https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&{contact.checksum}';
    MessageTemplate::update(FALSE)
      ->addWhere('id', '=', $template['id'])
      ->setValues([
        'msg_html' => '<a href="' . $legacyUrl . '">Pay now</a>',
        'msg_text' => $legacyUrl,
      ])
      ->execute();

    $upgrader = new CRM_Membershipapprovalworkflow_Upgrader();
    $this->assertTrue($upgrader->upgrade_1002());

    $updatedTemplate = MessageTemplate::get(FALSE)
      ->addSelect('msg_html', 'msg_text')
      ->addWhere('id', '=', $template['id'])
      ->execute()
      ->first();
    $this->assertStringNotContainsString($legacyUrl, $updatedTemplate['msg_html']);
    $this->assertStringNotContainsString($legacyUrl, $updatedTemplate['msg_text']);
    $this->assertStringContainsString('{crmURL', $updatedTemplate['msg_html']);
    $this->assertStringContainsString('{crmURL', $updatedTemplate['msg_text']);
  }

  public function testPendingMembershipAllowsReviewOrCurrent(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_PENDING
    );
    $this->assertSame(
      [CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW, CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED],
      array_keys($actions)
    );
  }

  public function testUnderReviewActionDependsOnPayment(): void {
    $unpaidActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW,
      FALSE
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED_PENDING_PAYMENT,
        CRM_Membershipapprovalworkflow_Utils::ACTION_DENIED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_NOT_FULFILLED,
      ],
      array_keys($unpaidActions)
    );

    $paidActions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW,
      TRUE
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_DENIED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_NOT_FULFILLED,
      ],
      array_keys($paidActions)
    );
  }

  public function testApprovedPendingPaymentAllowsApprovedNotFulfilledOrUnderReview(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_APPROVED_PENDING_PAYMENT
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_NOT_FULFILLED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW,
      ],
      array_keys($actions)
    );
  }

  public function testCurrentMembershipAllowsSuspendedRemovedExpiredCancelledByMemberOrUnderReview(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_CURRENT
    );
    $this->assertSame(
      [
        CRM_Membershipapprovalworkflow_Utils::ACTION_SUSPENDED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_REMOVED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_EXPIRED,
        CRM_Membershipapprovalworkflow_Utils::ACTION_CANCELLED_BY_MEMBER,
        CRM_Membershipapprovalworkflow_Utils::ACTION_UNDER_REVIEW,
      ],
      array_keys($actions)
    );
  }

  public function testExpiredMembershipAllowsCurrent(): void {
    $actions = CRM_Membershipapprovalworkflow_Utils::getAllowedActions(
      CRM_Membershipapprovalworkflow_Utils::STATUS_EXPIRED
    );
    $this->assertSame(
      [CRM_Membershipapprovalworkflow_Utils::ACTION_APPROVED],
      array_keys($actions)
    );
  }

  public function testMembershipTypeInWorkflowDefaultsToEveryType(): void {
    // An administrator leaves "Membership types using this workflow" blank.
    $this->setWorkflowSettings([CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES => []]);

    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow($this->workflowMembershipTypeId));
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow($this->createMembershipTypeOutsideWorkflow()));
  }

  public function testMembershipTypeInWorkflowRespectsConfiguredList(): void {
    $otherTypeId = $this->createMembershipTypeOutsideWorkflow();

    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow($this->workflowMembershipTypeId));
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow((string) $this->workflowMembershipTypeId));
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow($otherTypeId));
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::isMembershipTypeInWorkflow(NULL));
  }

  public function testAssertMembershipTypeInWorkflowRejectsOutOfScopeType(): void {
    CRM_Membershipapprovalworkflow_Utils::assertMembershipTypeInWorkflow(['membership_type_id' => $this->workflowMembershipTypeId]);
    $this->addToAssertionCount(1);

    $this->expectException(CRM_Core_Exception::class);
    CRM_Membershipapprovalworkflow_Utils::assertMembershipTypeInWorkflow(['membership_type_id' => $this->createMembershipTypeOutsideWorkflow()]);
  }

  public function testCanUseStatusOverrideDependsOnProtectedStatus(): void {
    $membershipId = $this->createTestMembership();

    $this->setMembershipStatus($membershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW);
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($membershipId));

    $this->setMembershipStatus($membershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_CURRENT);
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($membershipId));
  }

  public function testCanUseStatusOverrideAllowedWhenTypeOutOfScope(): void {
    // Workflow type - protected status blocks override.
    $workflowMembershipId = $this->createTestMembership();
    $this->setMembershipStatus($workflowMembershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW);
    $this->assertFalse(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($workflowMembershipId));

    // Same status on a type the workflow doesn't cover - it's out of scope,
    // so override is allowed even in a protected status.
    $otherMembershipId = $this->createTestMembership($this->createMembershipTypeOutsideWorkflow());
    $this->setMembershipStatus($otherMembershipId, CRM_Membershipapprovalworkflow_Utils::STATUS_UNDER_REVIEW);
    $this->assertTrue(CRM_Membershipapprovalworkflow_Utils::canUseStatusOverride($otherMembershipId));
  }

  /**
   * Sets a membership's status_id directly via a raw DAO write - bypassing
   * this extension's own hooks, which is exactly what's needed here: these
   * tests exercise canUseStatusOverride()'s read-only logic against an
   * arbitrary status, not the write-guards that are tested elsewhere.
   */
  private function setMembershipStatus(int $membershipId, string $statusName): void {
    CRM_Core_DAO::setFieldValue(
      'CRM_Member_DAO_Membership',
      $membershipId,
      'status_id',
      CRM_Membershipapprovalworkflow_Utils::getStatusIdByName($statusName)
    );
  }

}
