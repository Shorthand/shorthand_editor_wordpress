<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The Featured image box for a story post: the Shorthand cover first, the
 * featured image WordPress holds under a second tab.
 *
 * Expects from Shorthand\Admin\Editor::admin_post_thumbnail_html():
 * $content (string, core's featured image markup), $state (a
 * StoryCover::STATE_* value), $message (string) and $importable (bool,
 * whether "Use story cover now" applies). The image is left for the client,
 * which fetches a fresh signed address; the recorded one may have expired.
 */
?>
<div id="theshed-cover-panel" class="theshed-cover-panel" data-state="<?php echo esc_attr( $state ); ?>" data-view="story">
	<div class="theshed-cover-panel__tabs" role="tablist">
		<button type="button" class="button-link theshed-cover-panel__tab" role="tab" data-theshed-view="story" aria-selected="true">
			<?php esc_html_e( 'Shorthand cover', 'the-shorthand-editor' ); ?>
		</button>
		<button type="button" class="button-link theshed-cover-panel__tab" role="tab" data-theshed-view="featured" aria-selected="false">
			<?php esc_html_e( 'Featured image', 'the-shorthand-editor' ); ?>
		</button>
	</div>
	<div class="theshed-cover-panel__view" data-theshed-view="story">
		<img class="theshed-cover-panel__image" src="" alt="" hidden>
		<p class="theshed-cover-panel__message"><?php echo esc_html( $message ); ?></p>
		<p class="hide-if-no-js">
			<button type="button" class="button theshed-cover-panel__import" <?php echo $importable ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'Use story cover now', 'the-shorthand-editor' ); ?>
			</button>
		</p>
	</div>
	<div class="theshed-cover-panel__view" data-theshed-view="featured" hidden>
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's own featured image markup. ?>
	</div>
</div>
