<?php

/*
Plugin Name: Akismet Unit Tests
Plugin URI: http://akismet.com/
Description: Additional code to help while testing the Akismet plugin
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
