<?php
/** Hidden attribution fields, filled by site.js from the first-touch visit. Includes the honeypot. */
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ref', 'referrer', 'landing_page'] as $field): ?>
<input type="hidden" name="<?= $field ?>" data-track="<?= $field ?>">
<?php endforeach; ?>
<div class="hp-field" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
