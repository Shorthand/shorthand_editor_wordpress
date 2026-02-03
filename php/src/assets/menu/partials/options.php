<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h2><?php echo esc_html( $this->page_title ); ?></h2>
	<form method="post" action="options.php">
		<?php
		foreach ( $this->option_groups as $theshed_option_group ) {
			settings_fields( $theshed_option_group );
		}
		do_settings_sections( $this->settings_page_slug );
		submit_button();
		?>
	</form>
</div>