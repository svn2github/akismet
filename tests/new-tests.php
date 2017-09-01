<?php

// Use the latest post. Post #1 may not exist. Don't run this on a real site.
$akismet_first_post = get_posts( 'posts_per_page=1');
$akismet_first_post = array_shift( $akismet_first_post );

define( 'AKISMET_TEST_POST_ID', $akismet_first_post->ID );

class TestAkismetVersion extends UnitTestCase {
	function test_version_constant() {
		// make sure the AKISMET_VERSION constant matches the version in the plugin info header
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/akismet/akismet.php' );
		$this->assertEqual( AKISMET_VERSION, $plugin_data['Version'] );
	}
	
	function test_minimum_wp_version() {
		// Note: get_plugin_data() does not return "Requires at least" value.
		$this->assertEqual( AKISMET__MINIMUM_WP_VERSION, '4.0' );
	}
}

class TestAkismetVerifyAPI extends UnitTestCase {
	function test_deactivate_key() {
		$key = Akismet::get_api_key();
		$actual = Akismet::deactivate_key( $key );
		$this->assertEqual( 'deactivated', $actual );
	}

	function test_verify_valid_key() {
		$key = Akismet::get_api_key();
		$actual = Akismet::verify_key( $key );
		$this->assertEqual( 'valid', $actual );
	}
	
	function test_verify_invalid_key() {
		$key = 'antoehud';
		$actual = Akismet::verify_key( $key );
		$this->assertEqual( 'invalid', $actual );
	}
}

class TestAkismetRetry extends UnitTestCase {
	var $comment_id;
	var $comment_author = 'alex';
	var $old_moderation_option;
	var $old_whitelist_option;
	
	function setUp() {
		// make sure the preexisting moderation options don't affect test results
		$this->old_moderation_option = get_option('comment_moderation');
		$this->old_whitelist_option = get_option('comment_whitelist');
		#$this->old_api_key = get_option('wordpress_api_key');
		update_option('comment_moderation', '0');
		update_option('comment_whitelist', '0');
		#update_option('wordpress_api_key', '000000000019');
		$this->old_post = $_POST;
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );

		$this->comment_id = wp_insert_comment( array(
			'comment_post_ID' => AKISMET_TEST_POST_ID,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
			'comment_approved' => 0, // simulate the behaviour of akismet_auto_check_comment() by holding the comment in the pending queue
		));
		
		$comment = get_comment( $this->comment_id );
		
		// hack: make the plugin think that we just checked this comment but haven't yet updated meta.
		$akismet_last_comment = (array) $comment;
		// pretend that checking failed
		$akismet_last_comment[ 'akismet_result' ] = 'error';
		
		Akismet::set_last_comment( $akismet_last_comment );
		// and update commentmeta accordingly
		Akismet::auto_check_update_meta( $this->comment_id, $comment );
		Akismet::set_last_comment( null );

	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id, true );
		Akismet::set_last_comment( null );
		update_option('comment_moderation', $this->old_moderation_option);
		update_option('comment_whitelist', $this->old_whitelist_option);
		#update_option('wordpress_api_key', $this->old_api_key);
		$_POST = $this->old_post;
	}

	function test_schedule() {
		// Checking a test assumption: make sure nothing is scheduled
		$this->assertFalse( wp_next_scheduled('akismet_schedule_cron_recheck') );
	}
	
	function test_state_before_retry() {
		// make sure the comment is in the correct state
		$this->assertTrue( 0 < get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_history_before_retry() {

		// history should record a check-error
		$history = Akismet::get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-error', $history[0]['event'] );
	}
	
	function test_state_after_retry() {
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		Akismet::cron_recheck();

		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}

	function test_state_after_retry_too_old() {
		// comment is too old for retrying
		wp_update_comment( array(
				'comment_ID' => $this->comment_id,
				'comment_date' => strftime( '%Y-%m-%d %H:%M:%S', strtotime( '-20 days' ) ),
				) );
		// trigger a cron event.  The error flag will be removed, but the status unchanged.
		Akismet::cron_recheck();
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		global $wpdb;
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}

	function test_spawn_cron() {
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_error', true ) );

		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		
		// same as test_state_after_retry(), but this time trigger wp-cron.php itself
		wp_schedule_single_event( time() - 30, 'akismet_schedule_cron_recheck' );

		$cron_url = get_option( 'siteurl' ) . '/wp-cron.php?doing_wp_cron';
		$r = wp_remote_post( $cron_url, array( 'timeout' => 10, 'blocking' => true, 'sslverify' => apply_filters('https_local_ssl_verify', true)) );
		
		wp_cache_init();
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
		
	}

	function test_state_after_retry_moderation() {
		// turn on moderation
		update_option('comment_moderation', '1');
		
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		Akismet::cron_recheck();
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should be in the pending queue now, since moderation is enabled
		$this->assertEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}
	
	function test_history_after_retry() {
		Akismet::cron_recheck();

		// history should record a retry
		$history = Akismet::get_comment_history( $this->comment_id );
		
		if ( get_called_class() == 'TestAkismetRetry' ) {
			$this->assertEqual( 'cron-retry-ham', $history[0]['event'] );
		}
		else {
			$this->assertEqual( 'cron-retry-spam', $history[0]['event'] );
		}
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		// shh don't tell Nikolay I'm running more that one test per function
	}
	
	// if the user clicks the Spam button on a comment that is awaiting retry, we still should re-check
	// it and add the akismet_result meta, but leave the comment in the Spam folder 
	function test_no_retry_after_user_intervention() {
		$_POST['action'] = 'spam'; // simulate a user button click
		wp_spam_comment( $this->comment_id );

		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		Akismet::cron_recheck();


		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should stay in the spam queue, because that's where the user put it
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );

		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}
	
	function test_stuck_queue() {
		// test for a bug: if a comment is deleted but the meta value akismet_error remains, the retry queue would get stuck
		global $wpdb;
		$wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->comments WHERE comment_ID = %d", $this->comment_id) );
		clean_comment_cache( $this->comment_id );
		
		// the meta value is still there
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		
		Akismet::cron_recheck();
		
		// the meta value should be gone now
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		
		// Clean up anything else in commentmeta, like akismet_history
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->commentmeta WHERE comment_id = %d", $this->comment_id ) );
	}
	
	function test_retry_invalid_key() {
		global $wpcom_api_key;
		
		// make sure nothing is scheduled first
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		
		$old_key = get_option('wordpress_api_key');
		
		update_option( 'wordpress_api_key', '000000000000' ); // invalid key
		
		Akismet::cron_recheck();

		// no change to the comment
		$this->assertTrue( 0 < get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
		
		// the next recheck should be scheduled for ~6 hours
		$this->assertTrue( wp_next_scheduled('akismet_schedule_cron_recheck') - time() > 20000 );
		update_option( 'wordpress_api_key', $old_key );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}
}

class TestAkismetRetrySpam extends TestAkismetRetry {
	var $comment_author = 'viagra-test-123';
	
	function test_state_after_retry() {
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		Akismet::cron_recheck();
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}
	
	function test_state_after_retry_moderation() {
		// turn on moderation
		update_option( 'comment_moderation', '1' );
		
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		Akismet::cron_recheck();
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should be in the pending queue now, since moderation is enabled
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}

	// if the user clicks the Spam button on a comment that is awaiting retry, we still should re-check
	// it and add the akismet_result meta, but leave the comment in the Spam folder 
	function test_no_retry_after_user_intervention() {
		// move the comment from pending to approved
		wp_set_comment_status( $this->comment_id, '1', true );

		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		Akismet::cron_recheck();
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should stay in the approved queue, because that's where the user put it
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
		
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
	}
	
	function test_spawn_cron() {
		// no need to do this again
	}

}

// this is a slow test, so don't run it unless we need to
if ( ( defined( 'AKISMET_TEST_RETRY_QUEUE' ) && AKISMET_TEST_RETRY_QUEUE ) || isset( $_GET['akismet_test_retry_queue'] ) ) {
	class TestAkismetRetryQueue extends UnitTestCase {
		var $comment_ids = array();
		var $comment_author = 'alex';
		var $old_moderation_option;
		var $old_whitelist_option;
	
		function setUp() {
			// make sure the preexisting moderation options don't affect test results
			$this->old_moderation_option = get_option('comment_moderation');
			$this->old_whitelist_option = get_option('comment_whitelist');
			update_option('comment_moderation', '0');
			update_option('comment_whitelist', '0');
			$this->old_post = $_POST;
		
			// add 101 comments to the queue
			for ( $i=0; $i < 101; $i++ ) {
				$id = wp_insert_comment( array(
					'comment_post_ID' => AKISMET_TEST_POST_ID,
					'comment_author' => $this->comment_author,
					'comment_author_email' => 'alex@example.com',
					'comment_content' => 'This is a test: '. __CLASS__,
					'comment_approved' => 0, // simulate the behaviour of akismet_auto_check_comment() by holding the comment in the pending queue
				));
				$this->comment_ids[] = $id;
				$comment = get_comment( $id );

				// hack: make the plugin think that we just checked this comment but haven't yet updated meta.
				$akismet_last_comment = (array) $comment;
				// pretend that checking failed
				$akismet_last_comment[ 'akismet_result' ] = 'error';

				Akismet::set_last_comment( $akismet_last_comment );

				// and update commentmeta accordingly
				Akismet::auto_check_update_meta( $id, $comment );
			}
		
			Akismet::set_last_comment( null );

			// make sure there are no jobs scheduled
			$j = 0;
			while ( $j++ < 1000 && wp_next_scheduled('akismet_schedule_cron_recheck') )
				wp_unschedule_event( wp_next_scheduled('akismet_schedule_cron_recheck'), 'akismet_schedule_cron_recheck' );
		}
	
		function tearDown() {
			foreach ( $this->comment_ids as $id )
				wp_delete_comment( $id, true );
			Akismet::set_last_comment( null );
			update_option('comment_moderation', $this->old_moderation_option);
			update_option('comment_whitelist', $this->old_whitelist_option);
			$_POST = $this->old_post;

			// make sure there are no jobs scheduled
			$j = 0;
			while ( $j++ < 1000 && wp_next_scheduled('akismet_schedule_cron_recheck') )
				wp_unschedule_event( wp_next_scheduled('akismet_schedule_cron_recheck'), 'akismet_schedule_cron_recheck' );
		}
	
		function test_queue_reschedule() {
			// after running akismet_cron_recheck(), there will still be 1 comment left to recheck.
			// make sure that the job is rescheduled.
		
			// first make sure there's no job to confuse the test
			$this->assertFalse( wp_next_scheduled('akismet_schedule_cron_recheck') );
		
			Akismet::cron_recheck();
		
			// now make sure the job is rescheduled
			$this->assertTrue( wp_next_scheduled('akismet_schedule_cron_recheck') );
		
			// remove it to pretend that the job has started
			wp_unschedule_event( wp_next_scheduled('akismet_schedule_cron_recheck'), 'akismet_schedule_cron_recheck' );
		
			// do the remaining comment
			Akismet::cron_recheck();
		
			// and make sure another job was not scheduled
			$this->assertFalse( wp_next_scheduled('akismet_schedule_cron_recheck') );

			// double check that all comments were processed
			global $wpdb;
			$waiting = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->commentmeta WHERE meta_key = 'akismet_error'" );
			$this->assertEqual( 0, $waiting );
		
			wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		}
	}
}

// make sure the initial comment check is triggered, and the correct result stored, when wp_new_comment() is called
class TestAkismetAutoCheckCommentBase extends UnitTestCase {
	var $comment;
	var $comment_id;
	var $old_discard_option;
	var $old_moderation_option;
	var $old_whitelist_option;
	var $comment_author = 'alex';
	var $comment_content = 'This is a test';
	var $comment_extra = array();
	var $db_comment;
	
	function setUp() {
		// make sure we don't accidentally die()
		$this->old_discard_option = get_option('akismet_discard_month');
		$this->old_moderation_option = get_option('comment_moderation');
		$this->old_whitelist_option = get_option('comment_whitelist');
		update_option('akismet_discard_month', 'false');
		update_option('comment_moderation', '0');
		update_option('comment_whitelist', '0');
		
		$this->comment = array(
			'comment_post_ID' => AKISMET_TEST_POST_ID,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => $this->comment_content . ': '. __CLASS__,
		);
		
		if ( $this->comment_extra ) {
			$this->comment = array_merge( $this->comment, $this->comment_extra );
		}
		
		// make sure we don't trigger the dupe filter
		global $wpdb;
		if ( $dupe_comment_id = $wpdb->get_var( $wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_id = %d AND comment_author = %s AND comment_author_email = %s AND comment_content = %s", $this->comment['comment_post_ID'], $this->comment['comment_author'], $this->comment['comment_author_email'], $this->comment['comment_content']) ) ) {
			wp_delete_comment( $dupe_comment_id, true );
		}

		$this->comment_id = @wp_new_comment( $this->comment );
		$this->db_comment = get_comment( $this->comment_id );
	}
	
	function test_that_this_class_actually_has_a_test_so_that_the_results_dont_appear_misleading() {
		$this->assertTrue( true );
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id, true );
		update_option('akismet_discard_month', $this->old_discard_option);
		update_option('comment_moderation', $this->old_moderation_option);
		update_option('comment_whitelist', $this->old_whitelist_option);
		Akismet::set_last_comment( null );
	}
}

class TestAkismetAutoCheckComment extends TestAkismetAutoCheckCommentBase {
	function test_auto_comment_check_result() {
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = Akismet::get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-ham', $history[0]['event'] );
	}

	// make sure the main items in the akismet_as_submitted array match the comment
	function test_auto_comment_as_submitted_matches() {
		$as_submitted = get_comment_meta( $this->comment_id, 'akismet_as_submitted', true );
		
		$this->assertEqual( $this->db_comment->comment_author_email, $as_submitted['comment_author_email'] );
		$this->assertEqual( $this->db_comment->comment_author, $as_submitted['comment_author'] );
		$this->assertEqual( $this->db_comment->comment_content, $as_submitted['comment_content'] );
		$this->assertEqual( $this->db_comment->comment_agent, $as_submitted['user_agent'] );
	}
	
	function test_auto_comment_as_submitted_fields() {
		$as_submitted = get_comment_meta( $this->comment_id, 'akismet_as_submitted', true );
		
		// Check that these fields are saved in as_submitted; they're important for spam/ham reports.
		foreach ( array( 'blog' , 'blog_charset' , 'blog_lang' , 'comment_author', 'comment_author_email', 'comment_content', 'is_test', 'permalink', 'user_agent' ) as $keep_this_key ) {
			$this->assertTrue( isset( $as_submitted[ $keep_this_key ] ), $keep_this_key . " is not set." );
		}
		
		// There are a number of fields that we opt not to store in akismet_as_submitted.
		foreach ( array( 'comment_post_ID', 'comment_parent', 'akismet_comment_nonce', 'SERVER_SOFTWARE', 'REQUEST_URI', 'PATH', 'SCRIPT_URI' ) as $discard_this_key ) {
			$this->assertFalse( isset( $as_submitted[ $discard_this_key ] ), $discard_this_key . " is set." );
		}
		
		// We don't want any of the POST_* fields.
		foreach ( $as_submitted as $key => $val ) {
			$this->assertFalse( substr( $key, 0, 5 ) === 'POST_', $key . " should not have been saved in as_submitted." );
		}
	}

}

// same as for TestAkismetAutoCheckComment, but with a spam comment
class TestAkismetAutoCheckCommentSpam extends TestAkismetAutoCheckCommentBase {
	var $comment_author = 'viagra-test-123';
	
	function test_auto_comment_check_result() {
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = Akismet::get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-spam', $history[0]['event'] );
	}
}

// same as for TestAkismetAutoCheckComment, but with a spam comment that should be discarded
class TestAkismetAutoCheckCommentSpamDiscard extends TestAkismetAutoCheckCommentBase {
	var $comment_author = 'viagra-test-123';
	var $old_strictness = null;
	var $comment_extra = array(
		'test_discard' => '1',
		);
	
	function setUp() {
		$this->old_strictness = get_option('akismet_strictness');
		update_option( 'akismet_strictness', '1');
		
		parent::setUp();
	}
	
	function tearDown() {
		parent::tearDown();
		
		update_option( 'akismet_strictness', $this->old_strictness );
	}
	
	function test_discard() {
		$last_comment = Akismet::get_last_comment();
		$this->assertEqual( 'discard', $last_comment['akismet_pro_tip'] );
	}

}

// No discard header = not discarded
class TestAkismetAutoCheckCommentSpamNoDiscard extends TestAkismetAutoCheckCommentSpamDiscard {
	var $comment_extra = array(
		);

	function test_discard() {
		$last_comment = Akismet::get_last_comment();
		$this->assertFalse( isset( $last_comment['akismet_pro_tip'] ) );
	}
}

class TestAkismetHamAlert extends TestAkismetAutoCheckCommentBase {
	var $comment_extra = array(
		'test_alert_code' => '123',
		);
		
	function setUp() {
		delete_option( 'akismet_alert_code' );
		delete_option( 'akismet_alert_msg' );
		parent::setUp();
	}
	
	function tearDown() {
		parent::tearDown();
		delete_option( 'akismet_alert_code' );
		delete_option( 'akismet_alert_msg' );
	}
	
	function test_alert() {
		$this->assertEqual( get_option( 'akismet_alert_code' ), '123' );
		$this->assertEqual( get_option( 'akismet_alert_msg' ), 'Test alert 123' );
	}

}

class TestAkismetSpamAlert extends TestAkismetAutoCheckCommentBase {
	var $comment_author = 'viagra-test-123';
	var $comment_extra = array(
		'test_alert_code' => '123',
		);
		
	function setUp() {
		delete_option( 'akismet_alert_code' );
		delete_option( 'akismet_alert_msg' );
		parent::setUp();
	}
	
	function tearDown() {
		parent::tearDown();
		delete_option( 'akismet_alert_code' );
		delete_option( 'akismet_alert_msg' );
	}
	
	function test_auto_comment_check_result() {
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = Akismet::get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-spam', $history[0]['event'] );
	}

	function test_alert() {
		$this->assertEqual( get_option( 'akismet_alert_code' ), '123' );
		$this->assertEqual( get_option( 'akismet_alert_msg' ), 'Test alert 123' );
	}
}

class TestAkismetClearAlert extends TestAkismetAutoCheckCommentBase {
	function setUp() {
		update_option( 'akismet_alert_code', '123' );
		update_option( 'akismet_alert_msg', 'Test alert 123' );
		parent::setUp();
	}
	
	function tearDown() {
		parent::tearDown();
		delete_option( 'akismet_alert_code' );
		delete_option( 'akismet_alert_msg' );
	}
	
	function test_alert() {
		$this->assertEqual( get_option( 'akismet_alert_code' ), '' );
		$this->assertEqual( get_option( 'akismet_alert_msg' ), '' );
	}

}

// test a comment that Akismet says is not spam, but the WP Comment Blacklist feature blocks
class TestAkismetAutoCheckCommentWPBlacklist extends TestAkismetAutoCheckCommentBase {
	var $comment_author = 'alex';
	var $comment_content = 'Comment containing akismet-special-wp-blacklist-test string';
	var $old_blacklist_setting;

	function setUp() {
		$this->old_blacklist_setting = get_option('blacklist_keys');
		update_option('blacklist_keys', 'akismet-special-wp-blacklist-test');
		parent::setUp();
	}

	function tearDown() {
		parent::tearDown();

		update_option('blacklist_keys', $this->old_blacklist_setting);
	}

	function test_auto_comment_check_result() {
		// comment status will be spam because of the WP blacklist feature
		// As of https://core.trac.wordpress.org/changeset/34726, blacklisted comments go into trash by default.
		// Just check that it's not approved or pending.
		$this->assertNotEqual( 'approved', wp_get_comment_status( $this->comment_id ) )
			&& $this->assertNotEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		// but Akismet says Not Spam
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = Akismet::get_comment_history( $this->comment_id );
		$this->assertEqual( 'wp-blacklisted', $history[0]['event'] );
		$this->assertEqual( 'check-ham', $history[1]['event'] );
	}
}

class TestAkismetAutoCheckLocalIP extends TestAkismetAutoCheckCommentBase {
	var $old_server;
	
	function setUp() {
		$this->old_server = $_SERVER;
		
		// Replicate a bug where the only available IP address is a LAN IP
		foreach( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}
		
		$_SERVER[ 'REMOTE_ADDR' ] = '127.0.0.1';
		
		parent::setUp();
	}
	
	function tearDown() {
		parent::tearDown();
		
		$_SERVER = $this->old_server;
	}
}

// check that Akismet does the right thing when spam-related actions are triggered (clicking the Spam button etc)
class TestAkismetSubmitActions extends UnitTestCase {
	var $comment;
	var $comment_id;
	var $old_discard_option;
	var $old_moderation_option;
	var $old_whitelist_option;
	var $old_post;
	var $old_get;
	var $comment_author = 'alex';
	
	function setUp() {
		// make sure we don't accidentally die()
		$this->old_post = $_POST;
		$this->old_get = $_GET;
		$this->old_discard_option = get_option('akismet_discard_month');
		$this->old_moderation_option = get_option('comment_moderation');
		$this->old_whitelist_option = get_option('comment_whitelist');
		update_option('akismet_discard_month', 'false');
		update_option('comment_moderation', '0');
		update_option('comment_whitelist', '0');
		
		$this->comment = array(
			'comment_post_ID' => AKISMET_TEST_POST_ID,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is another test: blah',
		);
		
		// make sure we don't trigger the dupe filter
		global $wpdb;
		if ( $dupe_comment_id = $wpdb->get_var( $wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_id = %d AND comment_author = %s AND comment_author_email = %s AND comment_content = %s", $this->comment['comment_post_ID'], $this->comment['comment_author'], $this->comment['comment_author_email'], $this->comment['comment_content']) ) ) {
			wp_delete_comment( $dupe_comment_id, true );
		}

		Akismet::set_last_comment( null );
		
		$this->comment_id = @wp_new_comment( $this->comment );
		
		#$this->db_comment = get_comment( $this->comment_id );
		
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id, true );
		update_option('akismet_discard_month', $this->old_discard_option);
		update_option('comment_moderation', $this->old_moderation_option);
		update_option('comment_whitelist', $this->old_whitelist_option);
		$_POST = $this->old_post;
		$_GET = $this->old_get;
		Akismet::set_last_comment( null );
	}
	
	function test_ajax_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'delete-comment';
		$_POST['spam'] = 1;
		wp_spam_comment( $this->comment_id );
		
		$this->assertEqual('true', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_ajax_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'delete-comment';
		$_POST['unspam'] = 1;
		wp_unspam_comment( $this->comment_id );

		// this is not submitted to Akismet because the status didn't change (transition was from approved to approved)
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_ajax_trash_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'delete-comment';
		$_POST['trash'] = 1;
		wp_trash_comment( $this->comment_id );
		
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_bulk_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_GET['action'] = 'spam';
		
		wp_spam_comment( $this->comment_id );
		
		$user_result = get_comment_meta( $this->comment_id, 'akismet_user_result', true );
		
		$this->assertEqual('true', $user_result, "akismet_user_result = " . var_export( $user_result, true ) );
	}
	
	function test_bulk_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_GET['action'] = 'unspam';
		
		wp_unspam_comment( $this->comment_id );

		$user_result = get_comment_meta( $this->comment_id, 'akismet_user_result', true );

		// this is not submitted to Akismet because the status didn't change (transition was from approved to approved)
		$this->assertEqual(null, $user_result, "akismet_user_result = " . var_export( $user_result, true ) );
	}
	
	function test_edit_comment_spam() {
		$_POST['action'] = 'editedcomment';
		$comment = (array) get_comment( $this->comment_id );
		$comment['comment_approved'] = 'spam';
		wp_update_comment( $comment );

		$this->assertEqual('true', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_edit_comment_ham() {
		$_POST['action'] = 'editedcomment';
		$comment = (array) get_comment( $this->comment_id );
		$comment['comment_approved'] = '1';
		wp_update_comment( $comment );

		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_mystery_spam() {
		// comment status changes to spam for unknown reason - another spam plugin for example
		wp_spam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_mystery_spam_history() {
		// comment status changes to spam for unknown reason - another spam plugin for example
		wp_spam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		$history = Akismet::get_comment_history( $this->comment_id );
		// mystery status change should be recorded
		$this->assertEqual( 'status-spam', $history[0]['event'] );
	}

	function test_mystery_unspam() {
		// comment status changes to ham for unknown reason - another spam plugin for example
		wp_unspam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		// not mentioned in history, so the most recent entry is the auto comment check
		$history = Akismet::get_comment_history( $this->comment_id );
		$this->assertEqual( 'status-unapproved', $history[0]['event'] );
		$this->assertEqual( 'check-ham', $history[1]['event'] );
	}

}

class TestAkismetSubmitActionsSpam extends TestAkismetSubmitActions {
	var $comment_author = 'viagra-test-123';

	function test_ajax_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'delete-comment';
		$_POST['spam'] = 1;
		wp_spam_comment( $this->comment_id );
	
		// not submitted to Akismet because the status didn't change	
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_ajax_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'delete-comment';
		$_POST['unspam'] = 1;
		wp_unspam_comment( $this->comment_id );

		$this->assertEqual('false', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_edit_comment_spam() {
		$_POST['action'] = 'editedcomment';
		$comment = (array) get_comment( $this->comment_id );
		$comment['comment_approved'] = 'spam';
		wp_update_comment( $comment );

		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_edit_comment_ham() {
		$_POST['action'] = 'editedcomment';
		$comment = (array) get_comment( $this->comment_id );
		$comment['comment_approved'] = '1';
		wp_update_comment( $comment );

		$this->assertEqual('false', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_bulk_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'spam';
		wp_spam_comment( $this->comment_id );
		
		// this is not submitted to Akismet because the status didn't change (transition was from approved to approved)
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_bulk_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_GET['action'] = 'unspam';
		wp_unspam_comment( $this->comment_id );

		$this->assertEqual('false', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_mystery_spam_history() {
		// comment status changes to spam for unknown reason - another spam plugin for example
		wp_spam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		$history = Akismet::get_comment_history( $this->comment_id );
		// not mentioned in history, so the most recent entry is the auto comment check
		$this->assertEqual( 'check-spam', $history[0]['event'] );
	}

	function test_mystery_unspam() {
		// comment status changes to ham for unknown reason - another spam plugin for example
		wp_unspam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		$history = Akismet::get_comment_history( $this->comment_id );
		// mystery status change should be recorded
		$this->assertEqual( 'status-unapproved', $history[0]['event'] );
	}

}

class TestDeleteOldSpam extends UnitTestCase {
	var $comment_id;
	var $comment_author = 'alex';

	function setUp() {
		$when = strtotime( '-21 days' );
		
		$this->comment_id = wp_insert_comment( array(
			'comment_post_ID' => AKISMET_TEST_POST_ID,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
			'comment_approved' => 'spam',
			'comment_date' => date( 'Y-m-d H:i:s', $when ),
			'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
		));
		
		$comment = get_comment( $this->comment_id );
		
		$akismet_last_comment = (array) $comment;
		$akismet_last_comment[ 'akismet_result' ] = 'true';
		$akismet_last_comment[ 'comment_as_submitted' ] = (array) $comment;

		// hack: make the plugin think that we just checked this comment but haven't yet updated meta.
		Akismet::set_last_comment( $akismet_last_comment );
		
		// and update commentmeta accordingly
		Akismet::auto_check_update_meta( $this->comment_id, $comment );
		
		Akismet::set_last_comment( null );
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id, true );
	}
	
	function test_akismet_delete_old() {
		Akismet::delete_old_comments();

		// make sure it's not cached
		clean_comment_cache( $this->comment_id );
		
		// comment should be gone now
		$comment = get_comment( $this->comment_id );
		$this->assertFalse( $comment );
	}
	
	function test_spawn_cron() {
		// same as test_akismet_delete_old(), but trigger wp-cron.php instead of calling akismet_delete_old() directly
		
		// schedule an overdue delete
		wp_schedule_single_event( time() - 30, 'akismet_scheduled_delete' );
		// run wp-cron.php
		$cron_url = get_option( 'siteurl' ) . '/wp-cron.php?doing_wp_cron';
		// NB using @ here to suppress a warning in class-http.php that's unrelated to what we're testing
		@wp_remote_post( $cron_url, array('timeout' => 10, 'blocking' => true, 'sslverify' => apply_filters('https_local_ssl_verify', true)) );
		
		wp_cache_init();
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		
		// comment should be gone now
		$comment = get_comment( $this->comment_id );
		$this->assertFalse( $comment );
	}
	
	function test_meta_deleted() {
		// confirm that the meta values are there, so we know the test is valid
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_as_submitted' ) );
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_result' ) );
		$this->assertTrue( Akismet::get_comment_history( $this->comment_id ) );
		
		do_action('akismet_scheduled_delete');
		// lame that clean_comment_cache doesn't do this
		wp_cache_delete( $this->comment_id, 'comment_meta' );
		
		// make sure the meta values are removed also
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_result' ) );
		$this->assertFalse( Akismet::get_comment_history( $this->comment_id ) );
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_as_submitted' ) );

		global $wpdb;
		$this->assertFalse( $wpdb->get_results( $wpdb->prepare("SELECT * FROM $wpdb->commentmeta WHERE comment_id = %d", $this->comment_id ) ) );
	}
}


class TestNoDeleteOldHam extends UnitTestCase {
	var $comment_id;
	var $comment_author = 'alex';

	function setUp() {
		$when = strtotime( '-21 days' );
		
		// a non-spam comment will not be deleted
		$this->comment_id = wp_insert_comment( array(
			'comment_post_ID' => AKISMET_TEST_POST_ID,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
			'comment_approved' => '1',
			'comment_date' => date( 'Y-m-d H:i:s', $when ),
			'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
		));
		
		$this->comment = $comment = get_comment( $this->comment_id );
		
		$akismet_last_comment = (array) $comment;
		$akismet_last_comment[ 'akismet_result' ] = 'false';
		$akismet_last_comment[ 'comment_as_submitted' ] = (array) $comment;

		// hack: make the plugin think that we just checked this comment but haven't yet updated meta.
		Akismet::set_last_comment( $akismet_last_comment );

		// and update commentmeta accordingly
		Akismet::auto_check_update_meta( $this->comment_id, $comment );
		
		Akismet::set_last_comment( null );
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id, true );
	}
	
	function test_akismet_delete_old() {
		Akismet::delete_old_comments();

		// make sure it's not cached
		clean_comment_cache( $this->comment_id );
		
		// comment should still be there
		$comment = get_comment( $this->comment_id );
		$this->assertEqual( $comment, $this->comment );
	}
	
	function test_spawn_cron() {
		// same as test_akismet_delete_old(), but trigger wp-cron.php instead of calling akismet_delete_old() directly
		
		// schedule an overdue delete
		wp_schedule_single_event( time() - 30, 'akismet_scheduled_delete' );
		// run wp-cron.php
		$cron_url = get_option( 'siteurl' ) . '/wp-cron.php?doing_wp_cron';
		// NB using @ here to suppress a warning in class-http.php that's unrelated to what we're testing
		@wp_remote_post( $cron_url, array('timeout' => 0.01, 'blocking' => true, 'sslverify' => apply_filters('https_local_ssl_verify', true)) );
		
		wp_cache_init();
		wp_clear_scheduled_hook( 'akismet_schedule_cron_recheck' );
		
		// comment should still be there
		$comment = get_comment( $this->comment_id );
		$this->assertEqual( $comment, $this->comment );
	}
	
	function test_meta_deleted() {
		// confirm that the meta values are there, so we know the test is valid
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_as_submitted' ) );
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_result' ) );
		$this->assertTrue( Akismet::get_comment_history( $this->comment_id ) );
		
		do_action('akismet_scheduled_delete');
		// lame that clean_comment_cache doesn't do this
		wp_cache_delete( $this->comment_id, 'comment_meta' );
		
		// make sure the regula meta values stay, but akismet_as_submitted is removed
		$this->assertTrue( get_comment_meta( $this->comment_id, 'akismet_result' ) );
		$this->assertTrue( Akismet::get_comment_history( $this->comment_id ) );
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_as_submitted' ) );
		
	}
}

function akismet_tests_rest_api_request( $endpoint, $method, $auth = false, $params = "" ) {
	$rest_url = get_rest_url( null, 'akismet/v1/' . $endpoint );

	$curl = curl_init();
	
	$params = json_encode( $params );
	
	$headers = array(
		"cache-control: no-cache",
		"content-type: application/json",
	);
	
	if ( $auth ) {
		$headers[] = "authorization: Basic " . base64_encode( AKISMET_UNIT_TESTS_USERNAME . ":" . AKISMET_UNIT_TESTS_PASSWORD );
	}
	
	curl_setopt_array( $curl, array(
		CURLOPT_URL => $rest_url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 120,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => $params,
	) );

	$response = curl_exec( $curl );
	
	$err = curl_error( $curl );
	curl_close( $curl );

	if ( $err ) {
		return new WP_Error( $err );
	}

	$json_response = json_decode( $response );

	if ( $json_response === null ) {
		return new WP_Error( "unexpected_response", $response );
	}

	return $json_response;
}


class TestRESTAPIUnprivileged extends UnitTestCase {
	function test_key_get() {
		$api_response = akismet_tests_rest_api_request( "key", "GET", false );

		$this->assertEqual( $api_response->code, "rest_forbidden" );
	}

	function test_key_set() {
		$api_response = akismet_tests_rest_api_request( "key", "POST", false, array( 'key' => 'abcdef012345' ) );

		$this->assertEqual( $api_response->code, "rest_forbidden" );
	}
	
	function test_key_delete() {
		$api_response = akismet_tests_rest_api_request( "key", "DELETE", false );
		
		$this->assertEqual( $api_response->code, "rest_forbidden" );
	}
	
	function test_settings_get() {
		$api_response = akismet_tests_rest_api_request( "settings", "GET", false );
		
		$this->assertEqual( $api_response->code, "rest_forbidden" );
	}

	function test_settings_set() {
		$api_response = akismet_tests_rest_api_request( "settings", "POST", false, array( 'akismet_strictness' => true, 'akismet_show_user_comments_approved' => true ) );
		
		$this->assertEqual( $api_response->code, "rest_forbidden" );
	}
	
	function test_stats_get() {
		$api_response = akismet_tests_rest_api_request( "stats", "GET", false );
		
		$this->assertEqual( $api_response->code, "rest_forbidden" );
	}
}

class TestRESTAPIPrivileged extends UnitTestCase {
	function setUp() {
		update_option( 'wordpress_api_key', AKISMET_UNIT_TESTS_API_KEY );
	}

	function tearDown() {
		update_option( 'wordpress_api_key', AKISMET_UNIT_TESTS_API_KEY );
	}

	function test_key() {
		$api_response = akismet_tests_rest_api_request( "key", "GET", true );

		$this->assertEqual( $api_response, Akismet::get_api_key() );

		$this->assertEqual( Akismet::get_api_key(), AKISMET_UNIT_TESTS_API_KEY );

		// Try setting to an invalid key.
		$api_response = akismet_tests_rest_api_request( "key", "POST", true, array( 'key' => 'abc' ) );

		$this->assertEqual( $api_response->code, "invalid_key" );

		$this->assertEqual( Akismet::get_api_key(), AKISMET_UNIT_TESTS_API_KEY );

		// Set to a different valid key.
		$api_response = akismet_tests_rest_api_request( "key", "POST", true, array( 'key' => AKISMET_UNIT_TESTS_ALTERNATE_API_KEY ) );
		$this->assertEqual( $api_response, AKISMET_UNIT_TESTS_ALTERNATE_API_KEY );
		
		$api_response = akismet_tests_rest_api_request( "key", "GET", true );
		$this->assertEqual( $api_response, AKISMET_UNIT_TESTS_ALTERNATE_API_KEY );

		// Delete the key.
		$api_response = akismet_tests_rest_api_request( "key", "DELETE", true );
		$this->assertTrue( $api_response === true );
		
		$api_response = akismet_tests_rest_api_request( "key", "GET", true );
		$this->assertTrue( false === $api_response );

		// Set the the original key.
		$api_response = akismet_tests_rest_api_request( "key", "POST", true, array( 'key' => AKISMET_UNIT_TESTS_API_KEY ) );
		$this->assertEqual( $api_response, AKISMET_UNIT_TESTS_API_KEY );
		
		$api_response = akismet_tests_rest_api_request( "key", "GET", true );
		$this->assertEqual( $api_response, AKISMET_UNIT_TESTS_API_KEY );
	}
	
	function test_settings() {
		$api_response = akismet_tests_rest_api_request( "settings", "GET", true );
		
		$this->assertTrue( isset( $api_response->akismet_strictness ) );
		$this->assertTrue( isset( $api_response->akismet_show_user_comments_approved ) );

		$api_response = akismet_tests_rest_api_request( "settings", "POST", true, array( 'akismet_strictness' => true, 'akismet_show_user_comments_approved' => true ) );
		
		$this->assertEqual( $api_response->akismet_strictness, true );
		$this->assertEqual( $api_response->akismet_show_user_comments_approved, true );

		$api_response = akismet_tests_rest_api_request( "settings", "POST", true, array( 'akismet_strictness' => false, 'akismet_show_user_comments_approved' => false ) );
		
		$this->assertEqual( $api_response->akismet_strictness, false );
		$this->assertEqual( $api_response->akismet_show_user_comments_approved, false );

		$api_response = akismet_tests_rest_api_request( "settings", "GET", true );
		
		$this->assertEqual( $api_response->akismet_strictness, false );
		$this->assertEqual( $api_response->akismet_show_user_comments_approved, false );
	}
	
	function test_stats_get() {
		$api_response = akismet_tests_rest_api_request( "stats", "GET", true, array( 'interval' => 'all' ) );

		$this->assertTrue( property_exists( $api_response, 'all' ) );

		$api_response = akismet_tests_rest_api_request( "stats", "GET", true, array( 'interval' => '60-days' ) );

		$this->assertTrue( property_exists( $api_response, '60-days' ) );

		$api_response = akismet_tests_rest_api_request( "stats", "GET", true, array( 'interval' => '6-months' ) );

		$this->assertTrue( property_exists( $api_response, '6-months' ) );

		// Defaults to all stats if no valid interval.
		$api_response = akismet_tests_rest_api_request( "stats", "GET", true, array( 'interval' => 'five seconds' ) );

		$this->assertTrue( property_exists( $api_response, 'all' ) );
	}
}