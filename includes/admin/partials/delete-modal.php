<?php /** Confirmation dialog for deleting a lead. Opened by any [data-delete-lead="LEAD-…"] button. */ ?>
<div class="modal fade" id="deleteLeadModal" tabindex="-1" aria-labelledby="deleteLeadTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content admin-modal">
      <div class="modal-header">
        <h2 class="modal-title h5" id="deleteLeadTitle"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Delete lead?</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">You are about to delete <strong class="mono" data-delete-number></strong>.</p>
        <p class="text-muted-2 small mb-0">It will disappear from lists and reports. The record is kept in the database and the deletion is recorded in the audit log.</p>
        <div class="form-alert mt-3" data-form-alert></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" data-delete-confirm><i class="bi bi-trash"></i> Delete lead</button>
      </div>
    </div>
  </div>
</div>
