<?php

use Civi\Api4\Managed;
use Civi\Api4\Membership;
use Civi\Api4\Setting;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\Invasive;
use CRM_Membershipapprovalworkflow_ExtensionUtil as E;

/**
 * Base class for this extension's headless tests.
 *
 * CiviUnitTestCase drives its setup from native PHPUnit hooks
 * (setUpBeforeClass/setUp/tearDown), so it works on PHPUnit 10+, which
 * dropped <listeners> and with them Civi\Test\CiviTestListener.
 */
abstract class CRM_Membershipapprovalworkflow_Base extends \CiviUnitTestCase {

  /**
   * Membership type that setUp() enables the workflow for.
   *
   * @var int
   */
  protected $workflowMembershipTypeId;

  /**
   * Reinstalls this extension once per class.
   *
   * CiviUnitTestCase::setUpBeforeClass() truncates every table except
   * civicrm_extension, so this extension still looks installed while its
   * install-time state (hook_civicrm_install side effects, settings) is gone
   * - installMe() alone would be a no-op.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    (new CiviEnvBuilder('membershipapprovalworkflow'))
      ->uninstallMe(__DIR__)
      // The manager caches a stale "disabled" status across uninstall, which
      // makes the install below take the re-enable path and do nothing.
      ->callback(function () {
        \CRM_Extension_System::singleton()->getManager()->refresh();
      }, 'refresh-extension-statuses')
      ->installMe(__DIR__)
      ->apply(TRUE);
    static::assertExtensionInstalled();
  }

  /**
   * Fails unless this extension is installed and active in this process.
   *
   * Installed in civicrm_extension isn't enough: hook_civicrm_managed must
   * return this extension's declarations (so its mixins are running here),
   * and every declared entity - membership statuses, message templates -
   * must exist.
   */
  protected static function assertExtensionInstalled(): void {
    $key = E::LONG_NAME;
    self::assertSame(
      CRM_Extension_Manager::STATUS_INSTALLED,
      CRM_Extension_System::singleton()->getManager()->getStatus($key),
      "$key is not installed."
    );

    $declarations = [];
    CRM_Utils_Hook::managed($declarations, [$key]);
    self::assertNotEmpty($declarations, "$key declared no managed entities - its mixins are not active.");

    $created = Managed::get(FALSE)
      ->addSelect('name')
      ->addWhere('module', '=', $key)
      ->addWhere('entity_id', 'IS NOT NULL')
      ->execute()
      ->column('name');
    self::assertEqualsCanonicalizing(
      array_column($declarations, 'name'),
      $created,
      "$key managed entities were not all created."
    );
  }

  /**
   * Runs each test in a rolled-back transaction, with the settings form set.
   *
   * Every field on CRM_Membershipapprovalworkflow_Form_Settings is set
   * explicitly so tests don't depend on metadata defaults: the workflow
   * applies to one membership type, and notifications are off so tests don't
   * send mail. A test covering a notification turns its setting back on.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->useTransaction();
    $this->resetWorkflowCaches();

    $this->workflowMembershipTypeId = $this->createMembershipType('Workflow Membership', 'workflow');
    $this->setWorkflowSettings([
      CRM_Membershipapprovalworkflow_Utils::SETTING_MEMBERSHIP_TYPES => [$this->workflowMembershipTypeId],
      CRM_Membershipapprovalworkflow_Utils::SETTING_NOTIFY_UNDER_REVIEW => FALSE,
      CRM_Membershipapprovalworkflow_Utils::SETTING_NOTIFY_APPROVED_PENDING_PAYMENT => FALSE,
      CRM_Membershipapprovalworkflow_Utils::SETTING_NOTIFY_APPROVED => FALSE,
      CRM_Membershipapprovalworkflow_Utils::SETTING_NOTIFY_DENIED => FALSE,
      CRM_Membershipapprovalworkflow_Utils::SETTING_NOTIFY_NOT_FULFILLED => FALSE,
    ]);
  }

  /**
   * Saves settings the way CRM_Membershipapprovalworkflow_Form_Settings does.
   *
   * The form saves through APIv4 Setting.set, which also rejects unknown
   * setting names, so a typo fails the test instead of being ignored.
   * CiviUnitTestCase::tearDown() restores the original values.
   *
   * @param array $values
   *   Setting name => value.
   */
  protected function setWorkflowSettings(array $values): void {
    Setting::set(FALSE)->setValues($values)->execute();
  }

  /**
   * Clears Utils' per-request caches, as a new HTTP request would.
   *
   * CiviUnitTestCase resets Civi::$statics but not these. Between test
   * classes the IDs they hold get reused after the truncate/reinstall; within
   * a test, the "first observed" status cache would otherwise carry one
   * step's status into the next, which separate requests never see.
   */
  protected function resetWorkflowCaches(): void {
    foreach (['statusIdCache', 'membershipTypeIdCache', 'observedStatusIdCache'] as $cache) {
      Invasive::set([CRM_Membershipapprovalworkflow_Utils::class, $cache], []);
    }
  }

  /**
   * Asserts a membership's saved status.
   *
   * @param int $membershipId
   *   The membership ID.
   * @param string $expectedStatusName
   *   One of the CRM_Membershipapprovalworkflow_Utils::STATUS_* names.
   */
  protected function assertMembershipStatus(int $membershipId, string $expectedStatusName): void {
    $statusName = Membership::get(FALSE)
      ->addSelect('status_id:name')
      ->addWhere('id', '=', $membershipId)
      ->execute()
      ->single()['status_id:name'];
    $this->assertSame($expectedStatusName, $statusName, "Membership $membershipId status");
  }

  /**
   * Creates a membership type the workflow does not apply to.
   *
   * @return int
   *   The membership type ID.
   */
  protected function createMembershipTypeOutsideWorkflow(): int {
    return $this->createMembershipType('Non-workflow Membership', 'non_workflow');
  }

  /**
   * Creates a membership, with its own individual contact.
   *
   * @param int|null $membershipTypeId
   *   Defaults to the type the workflow is enabled for.
   *
   * @return int
   *   The membership ID.
   */
  protected function createTestMembership(?int $membershipTypeId = NULL): int {
    return Membership::create(FALSE)
      ->addValue('contact_id', $this->individualCreate())
      ->addValue('membership_type_id', $membershipTypeId ?? $this->workflowMembershipTypeId)
      ->execute()
      ->first()['id'];
  }

  /**
   * Creates a membership type and the organization it belongs to.
   *
   * Core's membershipTypeCreate() isn't used: it calls
   * CRM_Core_Config::clearDBCache(), whose TRUNCATEs implicitly commit the
   * test transaction, so everything created before it leaks into later tests.
   *
   * @param string $name
   *   Membership type name (unique per domain).
   * @param string $identifier
   *   Key for $this->ids['MembershipType'] and $this->ids['Contact'].
   *
   * @return int
   *   The membership type ID.
   */
  protected function createMembershipType(string $name, string $identifier): int {
    return (int) $this->createTestEntity('MembershipType', [
      'name' => $name,
      'member_of_contact_id' => $this->organizationCreate([], $identifier),
      'financial_type_id:name' => 'Member Dues',
      // Also becomes this type's price field value, used by order line items.
      'minimum_fee' => 100,
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'rolling',
    ], $identifier)['id'];
  }

}
