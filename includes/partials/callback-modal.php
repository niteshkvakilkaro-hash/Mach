<?php
/** "Before you go" callback popup. Opened on exit intent or by any [data-bs-target="#callbackModal"] button. */
$callbackServiceId = $callbackServiceId ?? null;
?>
<div class="modal fade" id="callbackModal" tabindex="-1" aria-labelledby="callbackTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content cb-modal">
      <div class="cb-head">
        <div class="cb-beams" aria-hidden="true"></div>
        <span class="cb-icon"><i class="bi bi-telephone-inbound"></i></span>
        <button type="button" class="cb-close" data-bs-dismiss="modal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="cb-body">
        <p class="eyebrow mb-3"><i class="bi bi-stars"></i> Before you go</p>
        <h2 class="cb-title" id="callbackTitle">Still deciding? Get a clear answer first.</h2>
        <p class="text-muted-2 mb-4">Share your number and our expert will call you back with the likely problem, cost and the earliest visit slot.</p>

        <form action="<?= e(url('api/leads/create.php')) ?>" method="post" data-lead-form novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="form_type" value="callback">
          <?php if ($callbackServiceId): ?><input type="hidden" name="service_id" value="<?= (int) $callbackServiceId ?>"><?php endif; ?>
          <?php require ROOT_PATH . '/includes/partials/tracking-fields.php'; ?>
          <div class="form-alert" data-form-alert></div>

          <div class="mb-3">
            <label class="form-label d-flex justify-content-between" for="cbName">Your name <small class="opt">Optional</small></label>
            <input class="form-control" id="cbName" name="customer_name" maxlength="80" placeholder="Your name" autocomplete="name">
          </div>
          <div class="mb-3">
            <label class="form-label" for="cbPhone">Phone / WhatsApp</label>
            <input class="form-control" id="cbPhone" name="phone" type="tel" inputmode="tel" required pattern="[0-9+\s\-]{10,16}" placeholder="10-digit mobile number" autocomplete="tel">
            <div class="invalid-feedback" data-error-for="phone">Enter a valid 10-digit mobile number.</div>
          </div>
          <div class="mb-4">
            <label class="form-label d-flex justify-content-between" for="cbEmail">Email <small class="opt">Optional</small></label>
            <input class="form-control" id="cbEmail" name="email" type="email" maxlength="150" placeholder="you@example.com" autocomplete="email">
            <div class="invalid-feedback" data-error-for="email"></div>
          </div>
          <button class="btn btn-grad btn-lg w-100" type="submit">Request a callback <i class="bi bi-chat-dots"></i></button>
          <p class="cb-note">No obligation. We use these details only to respond to your request.</p>
        </form>
      </div>
    </div>
  </div>
</div>
