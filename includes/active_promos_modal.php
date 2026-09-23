<?php
// Shared "Active promotions" modal (POS and Recommendations).
// Logic: assets/js/active_strategies.js · Styles: assets/css/promos.css
$promo_css = __DIR__ . '/../assets/css/promos.css';
$promo_confirm = __DIR__ . '/../assets/js/confirm-dialog.js';
$promo_can_cancel = in_array($_SESSION['role'] ?? '', ['owner', 'admin'], true);
?>
<link rel="stylesheet" href="assets/css/promos.css?v=<?php echo @filemtime($promo_css); ?>">
<script src="assets/js/confirm-dialog.js?v=<?php echo @filemtime($promo_confirm); ?>"></script>
<script>window.activePromosCanCancel = <?php echo $promo_can_cancel ? 'true' : 'false'; ?>;</script>

<div class="modal fade promo-modal" id="activeStrategiesModal" tabindex="-1" aria-labelledby="activePromosTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="promo-head">
                <div>
                    <h2 class="promo-title" id="activePromosTitle">Active promotions</h2>
                    <p class="promo-sub" id="activePromosSummary">Discounts currently applied at the POS.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="activeStrategiesTableBody" class="promo-list" aria-live="polite">
                    <!-- Loaded via JS -->
                </div>
            </div>
            <div class="promo-foot">
                <button type="button" class="promo-btn" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
