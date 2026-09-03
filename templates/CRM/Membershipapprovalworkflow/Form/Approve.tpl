<div class="crm-block crm-form-block crm-membershipapprovalworkflow-approve-form-block">

  <div class="crm-section">
    <div class="label">{ts}Current status{/ts}</div>
    <div class="content">{$currentStatusLabel}</div>
  </div>

  {if $statusSequence}
    {literal}
      <style type="text/css">
        .crm-membershipapprovalworkflow-sequence { margin: 0.5em 0; }
        .crm-membershipapprovalworkflow-sequence-step { display: inline-block; padding: 2px 10px; border: 1px solid #ccc; border-radius: 3px; background-color: #f7f7f7; }
        .crm-membershipapprovalworkflow-sequence-step-current { font-weight: bold; background-color: #d9edf7; border-color: #7cb9d6; }
        .crm-membershipapprovalworkflow-sequence-arrow { padding: 0 4px; }
        .crm-membershipapprovalworkflow-sequence-or { padding: 0 4px; font-style: italic; }
      </style>
    {/literal}
    <div class="help crm-membershipapprovalworkflow-sequence-help">
      <div>{ts}Workflow sequence{/ts}</div>
      <div class="crm-membershipapprovalworkflow-sequence">
        {foreach from=$statusSequence item=stepAlternatives name=seq}
          {foreach from=$stepAlternatives key=statusName item=statusLabel name=alt}
            {if !$smarty.foreach.alt.first}<span class="crm-membershipapprovalworkflow-sequence-or">{ts}or{/ts}</span>{/if}
            <span class="crm-membershipapprovalworkflow-sequence-step{if $statusName eq $currentStatusName} crm-membershipapprovalworkflow-sequence-step-current{/if}">
              {$statusLabel}
            </span>
          {/foreach}
          {if !$smarty.foreach.seq.last}<span class="crm-membershipapprovalworkflow-sequence-arrow">&rarr;</span>{/if}
        {/foreach}
      </div>
      <div>{ts}Only the action(s) valid from the current status are offered below. From Under Review, only one of "Approved/Pending Payment" or "Approved" is offered, depending on whether payment has already been received.{/ts}</div>
    </div>
  {/if}

  {if $hasActions}
    <table class="form-layout">
      {include file="CRM/common/formButtons.tpl" location="top"}
      <tr class="crm-membershipapprovalworkflow-form-block-approval_action">
        <td class="label">{$form.approval_action.label}</td>
        <td>{$form.approval_action.html}</td>
      </tr>
    </table>
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  {else}
    <div class="messages status no-popup">
      {icon icon="fa-info-circle"}{/icon}
      {ts}No approval action is available for the current membership status.{/ts}
    </div>
    <div class="crm-submit-buttons">
      <a href="{$backUrl}" class="button"><span>{ts}Back{/ts}</span></a>
    </div>
  {/if}

</div>
