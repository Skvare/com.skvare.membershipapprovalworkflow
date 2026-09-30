<?php

use Civi\Api4\Membership;
use Civi\Api4\MembershipStatus;
use PHPUnit\Framework\Attributes\Group;

/**
 * Checks the extension behaves as installed, beyond what the base class guards.
 *
 * CRM_Membershipapprovalworkflow_Base::assertExtensionInstalled() already
 * fails every class if the extension or its managed entities are missing.
 * These tests cover the rest: hook_civicrm_install ran, and the extension's
 * runtime hooks fire in this process.
 *
 * @group headless
 */
#[Group('headless')]
class CRM_Membershipapprovalworkflow_InstallTest extends CRM_Membershipapprovalworkflow_Base {

  public function testInstallHookDeactivatedNewStatus(): void {
    $newStatus = MembershipStatus::get(FALSE)
      ->addSelect('is_active')
      ->addWhere('name', '=', 'New')
      ->execute()
      ->single();
    $this->assertFalse($newStatus['is_active']);
    // Its prior state is recorded so disable/uninstall can restore it.
    $this->assertTrue(Civi::settings()->get(CRM_Membershipapprovalworkflow_Utils::SETTING_NEW_STATUS_WAS_ACTIVE));
  }

  public function testPreHookForcesPendingOnlyForWorkflowTypes(): void {
    $pendingId = (int) CRM_Membershipapprovalworkflow_Utils::getStatusIdByName(CRM_Membershipapprovalworkflow_Utils::STATUS_PENDING);

    $workflowMembershipId = $this->createTestMembership();
    $this->assertSame($pendingId, $this->getMembershipStatusId($workflowMembershipId));

    $otherMembershipId = $this->createTestMembership($this->createMembershipTypeOutsideWorkflow());
    $this->assertNotSame($pendingId, $this->getMembershipStatusId($otherMembershipId));
  }

  /**
   * Reads a membership's status back from the database.
   *
   * @param int $membershipId
   *   The membership ID.
   *
   * @return int
   *   The membership's saved status_id.
   */
  private function getMembershipStatusId(int $membershipId): int {
    return Membership::get(FALSE)
      ->addSelect('status_id')
      ->addWhere('id', '=', $membershipId)
      ->execute()
      ->single()['status_id'];
  }

}
