<?php
/**
 * Shown above a story preview when the live version could not be fetched.
 *
 * @package Shorthand Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="theshed-preview-notice" role="status" style="margin: 0; padding: 1rem; font-family: sans-serif; font-size: 0.875rem; line-height: 1.5; color: #1d2327; background-color: #fcf9e8; border-bottom: 1px solid #dba617;">
	<?php esc_html_e( 'This preview could not be refreshed from Shorthand. You are seeing the last version saved to WordPress.', 'the-shorthand-editor' ); ?>
</div>
