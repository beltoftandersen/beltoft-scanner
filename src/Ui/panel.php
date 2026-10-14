<?php
/**
 * The scanner panel. Rendered once, at the end of the body.
 *
 * The camera is the top third; the rest is the order being built. Scanning is
 * only half of what a partner does with a shelf - the other half is "make that
 * four, not one" and "did that one go in?" - and both of those have to be
 * answerable without hunting for a row in a filtered list behind the panel.
 *
 * @package B2BScanner
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="b2bs-panel" id="b2bs-panel" hidden aria-hidden="true" role="dialog" aria-modal="false"
	aria-label="<?php echo esc_attr__( 'Scan barcode', 'beltoft-scanner' ); ?>">

	<div class="b2bs-stage">
		<video class="b2bs-video" id="b2bs-video" playsinline muted autoplay></video>

		<div class="b2bs-reticle" aria-hidden="true"></div>

		<div class="b2bs-stage-bar">
			<button type="button" class="b2bs-icon-btn b2bs-torch" id="b2bs-torch" hidden>
				<?php echo esc_html__( 'Light', 'beltoft-scanner' ); ?>
			</button>
			<button type="button" class="b2bs-icon-btn b2bs-close" id="b2bs-close">
				<?php echo esc_html__( 'Close', 'beltoft-scanner' ); ?>
			</button>
		</div>

		<p class="b2bs-status" id="b2bs-status" role="status" aria-live="polite"></p>
	</div>

	<form class="b2bs-manual" id="b2bs-manual">
		<label class="screen-reader-text" for="b2bs-manual-input">
			<?php echo esc_html__( 'Enter code', 'beltoft-scanner' ); ?>
		</label>
		<input type="text" id="b2bs-manual-input" class="b2bs-manual-input" inputmode="numeric"
			autocomplete="off" autocapitalize="off" spellcheck="false"
			placeholder="<?php echo esc_attr__( 'EAN or SKU', 'beltoft-scanner' ); ?>">
		<button type="submit" class="b2bs-manual-add"><?php echo esc_html__( 'Add', 'beltoft-scanner' ); ?></button>
	</form>

	<div class="b2bs-order" id="b2bs-order">
		<div class="b2bs-order__head">
			<span class="b2bs-order__count" id="b2bs-count"></span>
			<span class="b2bs-order__total" id="b2bs-subtotal"></span>
		</div>

		<ul class="b2bs-lines" id="b2bs-lines"></ul>

		<p class="b2bs-empty" id="b2bs-empty">
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
	<div class="b2bs-foot">
		<a class="b2bs-foot__go" id="b2bs-go" href="#">
			<?php echo esc_html__( 'Checkout', 'beltoft-scanner' ); ?>
		</a>
	</div>
</div>

<div class="b2bs-toasts" id="b2bs-toasts" role="status" aria-live="polite"></div>
