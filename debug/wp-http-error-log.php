<?php
/*
Plugin Name: WP_Http error log
Plugin URI: http://automattic.com/
Description: Simple debug error log for the WP_Http class. Errors are written using the error_log() function to your PHP error log file.
Version: 0.0.1
Author: Automattic
Author URI: http://automattic.com/wordpress-plugins/
License: GPLv2
*/

function wp_http_request_log( $response, $type, $transport=null ) {
	if ( $type == 'response' ) {
		error_log( "$transport: {$response['response']['code']} {$response['response']['message']}" );
		if ( isset($response['headers']['x-akismet-server']) )
			error_log( "Akismet server: {$response['headers']['x-akismet-server']}" );
		if ( isset($response['body']) )
			error_log( "Response body: {$response['body']}" );
	}
}

add_action( 'http_api_debug', 'wp_http_request_log', 10, 3 );

function wp_http_response_log( $response, $r, $url ) {
	
	error_log( "{$response['response']['code']} {$response['response']['message']} for {$url}" );
	return $response;
}

add_filter( 'http_response', 'wp_http_response_log', 10, 3 );

function wp_http_error_log_info() {
	echo "
            <div id='wp-http-error-log-info' class='updated fade'><p>".sprintf(__('Logging HTTP request messages to <code>%s</code>'), ini_get('error_log') ). "</p></div>
            ";
}

add_action('admin_notices', 'wp_http_error_log_info'); 