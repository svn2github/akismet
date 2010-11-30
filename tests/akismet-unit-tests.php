<?php

/*
Plugin Name: Akismet Unit Tests
Plugin URI: http://akismet.com/
Description: Additional code to help while testing the Akismet plugin.  DO NOT RUN THIS ON A PRODUCTION SITE!
Version: 2.4.0
Author: Automattic
Author URI: http://automattic.com/wordpress-plugins/
License: GPLv2
*/

add_action( 'admin_menu', 'akismet_unit_test_page' );
function akismet_unit_test_page() {
	if ( function_exists('add_submenu_page') )
		add_submenu_page('plugins.php', __('Akismet Unit Tests'), __('Akismet Unit Tests'), 'manage_options', 'akismet-unit-tests', 'akismet_unit_tests');
}

function akismet_unit_tests() {
	
	if ( !WP_DEBUG ) {
?>
<div class="wrap">
<h2><?php _e('Warning'); ?></h2>
<div class="narrow">
<p>You must enable the WP_DEBUG constant in wp-config.php in order to run the Akismet unit tests.</p>
<p><strong>Do not run this on a production site!</strong></p>
<p>There is a risk of data loss or unintentional damage.  The unit tests <em>should</em> return everything to its original state, but the nature of testing means things will sometimes go wrong.</p>
</div>
</div>
<?php
		return;
	}
	
	define('AKISMET_TEST_MODE', true);
	
	require_once('simpletest/autorun.php');

	$suite = new TestSuite('All Akismet tests');

	// add new files here as needed
	$suite->addFile(dirname(__FILE__) . '/basic-tests.php');

?>
<div class="wrap">
<h2><?php _e('Akismet Unit Test Results'); ?></h2>
<div class="narrow">
<?php

	$suite->run(new HtmlReporter());
?>

</div>
</div>

<?php

}
