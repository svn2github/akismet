<?php

class TestAkismetRetry extends UnitTestCase {
	var $comment_id;
	var $comment_author = 'alex';
	var $old_moderation_option;
	var $old_whitelist_option;
	
	function setUp() {
		// make sure the preexisting moderation options don't affect test results
		$this->old_moderation_option = get_option('comment_moderation');
		$this->old_whitelist_option = get_option('comment_whitelist');
		update_option('comment_moderation', 0);
		update_option('comment_whitelist', 0);
		$this->old_post = $_POST;

		$this->comment_id = wp_insert_comment( array(
			'comment_post_ID' => 1,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
			'comment_approved' => 0, // simulate the behaviour of akismet_auto_check_comment() by holding the comment in the pending queue
		));
		
		$comment = get_comment( $this->comment_id );
		
		// hack: make the plugin think that we just checked this comment but haven't yet updated meta.
		global $akismet_last_comment;
		$akismet_last_comment = (array) $comment;
		// pretend that checking failed
		$akismet_last_comment[ 'akismet_result' ] = 'error';
		// and update commentmeta accordingly
		akismet_auto_check_update_meta( $this->comment_id, $comment );
		

		$akismet_last_comment = null;
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id, true );
		unset( $GLOBALS['akismet_last_comment'] );
		update_option('comment_moderation', $this->old_moderation_option);
		update_option('comment_whitelist', $this->old_whitelist_option);
		$_POST = $this->old_post;
	}
	
	function test_state_before_retry() {
		// make sure the comment is in the correct state
		$this->assertTrue( 0 < get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_history_before_retry() {

		// history should record a check-error
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-error', $history[0]['event'] );
	}
	
	function test_state_after_retry() {
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		akismet_cron_recheck( 0 );
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_state_after_retry_moderation() {
		// turn on moderation
		update_option('comment_moderation', 1);
		
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		akismet_cron_recheck( 0 );
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should be in the pending queue now, since moderation is enabled
		$this->assertEqual( 'unapproved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_history_after_retry() {
		akismet_cron_recheck( 0 );

		// history should record a retry
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'cron-retry', $history[0]['event'] );
		
		// shh don't tell Nikolay I'm running more that one test per function
	}
	
	// if the user clicks the Spam button on a comment that is awaiting retry, we still should re-check
	// it and add the akismet_result meta, but leave the comment in the Spam folder 
	function test_no_retry_after_user_intervention() {
		$_POST['action'] = 'spam'; // simulate a user button click
		wp_spam_comment( $this->comment_id );

		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		akismet_cron_recheck( 0 );


		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should stay in the spam queue, because that's where the user put it
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );

	}
}

class TestAkismetRetrySpam extends TestAkismetRetry {
	var $comment_author = 'viagra-test-123';
	
	function test_state_after_retry() {
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		akismet_cron_recheck( 0 );
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_state_after_retry_moderation() {
		// turn on moderation
		update_option('comment_moderation', 1);
		
		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		akismet_cron_recheck( 0 );
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should be in the pending queue now, since moderation is enabled
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
	}

	// if the user clicks the Spam button on a comment that is awaiting retry, we still should re-check
	// it and add the akismet_result meta, but leave the comment in the Spam folder 
	function test_no_retry_after_user_intervention() {
		// move the comment from pending to approved
		wp_set_comment_status( $this->comment_id, '1', true );

		// trigger a cron event and make sure the error status is replaced with 'false' (not spam)
		akismet_cron_recheck( 0 );
		
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		// this should stay in the approved queue, because that's where the user put it
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
		
	}

}

// make sure the initial comment check is triggered, and the correct result stored, when wp_new_comment() is called
class TestAkismetAutoCheckComment extends UnitTestCase {
	var $comment;
	var $comment_id;
	var $old_discard_option;
	var $old_moderation_option;
	var $old_whitelist_option;
	var $comment_author = 'alex';
	var $comment_content = 'This is a test';
	var $db_comment;
	
	function setUp() {
		// make sure we don't accidentally die()
		$this->old_discard_option = get_option('akismet_discard_month');
		$this->old_moderation_option = get_option('comment_moderation');
		$this->old_whitelist_option = get_option('comment_whitelist');
		update_option('akismet_discard_month', 'false');
		update_option('comment_moderation', 0);
		update_option('comment_whitelist', 0);
		
		$this->comment = array(
			'comment_post_ID' => 1,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => $this->comment_content . ': '. __CLASS__,
		);
		
		// make sure we don't trigger the dupe filter
		global $wpdb;
		if ( $dupe_comment_id = $wpdb->get_var( $wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_id = %d AND comment_author = %s AND comment_author_email = %s AND comment_content = %s", $this->comment['comment_post_ID'], $this->comment['comment_author'], $this->comment['comment_author_email'], $this->comment['comment_content']) ) ) {
			wp_delete_comment( $dupe_comment_id );
		}

			
		$this->comment_id = @wp_new_comment( $this->comment );
		$this->db_comment = get_comment( $this->comment_id );
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id );
		update_option('akismet_discard_month', $this->old_discard_option);
		update_option('comment_moderation', $this->old_moderation_option);
		update_option('comment_whitelist', $this->old_whitelist_option);
		unset( $GLOBALS['akismet_last_comment'] );
		
	}
	
	function test_auto_comment_check_result() {
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-ham', $history[0]['event'] );
	}

	// make sure the main items in the akismet_as_submitted array match the comment
	function test_auto_comment_as_submitted_matches() {
		$as_submitted = get_comment_meta( $this->comment_id, 'akismet_as_submitted', true );
		
		$this->assertEqual( $this->db_comment->comment_author_email, $as_submitted['comment_author_email'] );
		$this->assertEqual( $this->db_comment->comment_author, $as_submitted['comment_author'] );
		$this->assertEqual( $this->db_comment->comment_content, $as_submitted['comment_content'] );
		$this->assertEqual( $this->db_comment->comment_post_ID, $as_submitted['comment_post_ID'] );
		$this->assertEqual( $this->db_comment->comment_agent, $as_submitted['user_agent'] );
	}

}

// same as for TestAkismetAutoCheckComment, but with a spam comment
class TestAkismetAutoCheckCommentSpam extends TestAkismetAutoCheckComment {
	var $comment_author = 'viagra-test-123';
	
	function test_auto_comment_check_result() {
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		$this->assertEqual( 'true', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'check-spam', $history[0]['event'] );
	}
}

// test a comment that Akismet says is not spam, but the WP Comment Blacklist feature blocks
class TestAkismetAutoCheckCommentWPBlacklist extends TestAkismetAutoCheckComment {
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
		$this->assertEqual( 'spam', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
		// but Akismet says Not Spam
		$this->assertEqual( 'false', get_comment_meta( $this->comment_id, 'akismet_result', true ) );
	}
	
	function test_auto_comment_check_history() {
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'wp-blacklisted', $history[0]['event'] );
		$this->assertEqual( 'check-ham', $history[1]['event'] );
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
	var $comment_author = 'alex';
	
	function setUp() {
		// make sure we don't accidentally die()
		$this->old_post = $_POST;
		$this->old_discard_option = get_option('akismet_discard_month');
		$this->old_moderation_option = get_option('comment_moderation');
		$this->old_whitelist_option = get_option('comment_whitelist');
		update_option('akismet_discard_month', 'false');
		update_option('comment_moderation', 0);
		update_option('comment_whitelist', 0);
		
		$this->comment = array(
			'comment_post_ID' => 1,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is another test: blah',
		);
		
		// make sure we don't trigger the dupe filter
		global $wpdb;
		if ( $dupe_comment_id = $wpdb->get_var( $wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_id = %d AND comment_author = %s AND comment_author_email = %s AND comment_content = %s", $this->comment['comment_post_ID'], $this->comment['comment_author'], $this->comment['comment_author_email'], $this->comment['comment_content']) ) ) {
			wp_delete_comment( $dupe_comment_id );
		}

		unset( $GLOBALS['akismet_last_comment'] );
		
		$this->comment_id = @wp_new_comment( $this->comment );
		
		#$this->db_comment = get_comment( $this->comment_id );
		
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id );
		update_option('akismet_discard_month', $this->old_discard_option);
		update_option('comment_moderation', $this->old_moderation_option);
		update_option('comment_whitelist', $this->old_whitelist_option);
		$_POST = $this->old_post;
		unset( $GLOBALS['akismet_last_comment'] );
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
		$_POST['action'] = 'delete-comment';
		$_POST['action'] = 'spam';
		wp_spam_comment( $this->comment_id );
		
		$this->assertEqual('true', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_bulk_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'delete-comment';
		$_POST['action'] = 'unspam';
		wp_unspam_comment( $this->comment_id );

		// this is not submitted to Akismet because the status didn't change (transition was from approved to approved)
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
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

		$history = akismet_get_comment_history( $this->comment_id );
		// mystery status change should be recorded
		$this->assertEqual( 'status-spam', $history[0]['event'] );
	}

	function test_mystery_unspam() {
		// comment status changes to ham for unknown reason - another spam plugin for example
		wp_unspam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		// not mentioned in history, so the most recent entry is the auto comment check
		$history = akismet_get_comment_history( $this->comment_id );
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
		$_POST['action'] = 'unspam';
		wp_unspam_comment( $this->comment_id );

		$this->assertEqual('false', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_mystery_spam_history() {
		// comment status changes to spam for unknown reason - another spam plugin for example
		wp_spam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		$history = akismet_get_comment_history( $this->comment_id );
		// not mentioned in history, so the most recent entry is the auto comment check
		$this->assertEqual( 'check-spam', $history[0]['event'] );
	}

	function test_mystery_unspam() {
		// comment status changes to ham for unknown reason - another spam plugin for example
		wp_unspam_comment( $this->comment_id );

		// not submitted to Akismet
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );

		$history = akismet_get_comment_history( $this->comment_id );
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
			'comment_post_ID' => 1,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
			'comment_approved' => 'spam',
			'comment_date' => date( 'Y-m-d H:i:s', $when ),
			'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
		));
	}
	
	function tearDown() {
		wp_delete_comment( $this->comment_id );
	}
	
	function test_akismet_delete_old() {
		akismet_delete_old();

		// make sure it's not cached
		clean_comment_cache( $this->comment_id );
		
		// comment should be gone now
		$comment = get_comment( $this->comment_id );
		$this->assertFalse( $comment );
	}
}


?>
