<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background-color: #f0f0f0; padding: 10px; border-radius: 5px;">
	<div id="theshed-toolbar"></div>
	<?php wp_print_scripts( 'theshed-create-post-toolbar-script' ); ?>
	<iframe id="preview-iframe" style="margin-top: 1rem; min-height: 500px" height="auto" width="100%"
		src="<?php echo esc_attr( $preview_url ); ?>"></iframe>
</div>