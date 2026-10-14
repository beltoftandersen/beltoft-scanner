<?php
/**
 * The scanner panel. Rendered once, at the end of the body.
 *
 * The camera is the top third; the rest is the order being built. Scanning is
 * only half of what a partner does with a shelf - the other half is "make that
 * four, not one" and "did that one go in?" - and both of those have to be
 * answerable without hunting for a row in a filtered list behind the panel.
 *
 * @package BeltoftScanner
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bscan-panel" id="bscan-panel" hidden aria-hidden="true" role="dialog" aria-modal="false"
	aria-label="<?php echo esc_attr__( 'Scan barcode', 'beltoft-scanner' ); ?>">

	<div class="bscan-stage">
		<video class="bscan-video" id="bscan-video" playsinline muted autoplay></video>

		<div class="bscan-reticle" aria-hidden="true"></div>

		<div class="bscan-stage-bar">
			<button type="button" class="bscan-icon-btn bscan-torch" id="bscan-torch" hidden>
				<?php echo esc_html__( 'Light', 'beltoft-scanner' ); ?>
			</button>
			<button type="button" class="bscan-icon-btn bscan-close" id="bscan-close">
				<?php echo esc_html__( 'Close', 'beltoft-scanner' ); ?>
			</button>
		</div>

		<p class="bscan-status" id="bscan-status" role="status" aria-live="polite"></p>
	</div>

	<form class="bscan-manual" id="bscan-manual">
		<label class="screen-reader-text" for="bscan-manual-input">
			<?php echo esc_html__( 'Enter code', 'beltoft-scanner' ); ?>
		</label>
		<input type="text" id="bscan-manual-input" class="bscan-manual-input" inputmode="numeric"
			autocomplete="off" autocapitalize="off" spellcheck="false"
			placeholder="<?php echo esc_attr__( 'EAN or SKU', 'beltoft-scanner' ); ?>">
		<button type="submit" class="bscan-manual-add"><?php echo esc_html__( 'Add', 'beltoft-scanner' ); ?></button>
	</form>

	<div class="bscan-order" id="bscan-order">
		<div class="bscan-order__head">
			<span class="bscan-order__count" id="bscan-count"></span>
			<span class="bscan-order__total" id="bscan-subtotal"></span>
		</div>

		<ul class="bscan-lines" id="bscan-lines"></ul>

		<p class="bscan-empty" id="bscan-empty">
			<?php echo esc_html__( 'You have not scanned any codes yet.', 'beltoft-scanner' ); ?>
		</p>
	</div>

	<?php
	/*
	 * One action only.
	 *
	 * There used to be a "Continuar a ler" button beside this. It did not
	 * continue anything: the camera is never stopped between scans - the only
	 * thing guarding against a double read is a short repeat window on the
	 * same barcode - so there was nothing to resume. Its actual effect was to
	 * focus the manual code field, which is on screen directly above it and
	 * can simply be tapped. A button that restates a control next to it, under
	 * a label describing something the reader does anyway, is one more thing
	 * to read on a phone held in one hand in a warehouse.
	 */
	?>
	<div class="bscan-foot">
		<a class="bscan-foot__go" id="bscan-go" href="#">
			<?php echo esc_html__( 'Checkout', 'beltoft-scanner' ); ?>
		</a>
	</div>
</div>

<div class="bscan-toasts" id="bscan-toasts" role="status" aria-live="polite"></div>
