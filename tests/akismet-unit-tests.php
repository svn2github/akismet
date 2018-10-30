<?php

/*
Plugin Name: Akismet Unit Tests
Plugin URI: http://akismet.com/
Description: Additional code to help while testing the Akismet plugin.  DO NOT RUN THIS ON A PRODUCTION SITE!
Version: 4.1a2
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
	
	if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
		?>
		<p>Disable the WP cron job (<code>define( 'DISABLE_WP_CRON', true );</code> in <code>wp-config.php</code>) or it might run during the tests and give you unexpected results.</p>
		<?php
		
		return;
	}
	
	if ( version_compare( AKISMET_VERSION, '3.0' ) < 0 ) {
		?>
		<p>These tests are intended to be run with Akismet 3.0+.</p>
		<?php
		
		return;

	}
	
	define('AKISMET_TEST_MODE', true);
	
	require_once('simpletest/unit_tester.php');

	$suite = new TestSuite('All Akismet tests');

	$suite->addFile(dirname(__FILE__) . '/new-tests.php');

?>
<div class="wrap">
<h2><?php _e('Akismet Unit Test Results'); ?></h2>
<div class="narrow">
<pre>
<?php

	$suite->run(new HtmlReporter());
?>
</pre>
</div>
</div>

<?php

}
