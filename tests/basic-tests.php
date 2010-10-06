<?php

class TestAkismetRetry extends UnitTestCase {
	var $comment_id;
	var $comment_author = 'alex';
	
	function setUp() {
		$this->comment_id = wp_insert_comment( array(
			'comment_post_ID' => 1,
			'comment_author' => $this->comment_author,
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
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
		wp_delete_comment( $this->comment_id );
		unset( $GLOBALS['akismet_last_comment'] );

	}
	
	function test_state_before_retry() {
		// make sure the comment is in the correct state
		$this->assertTrue( 0 < get_comment_meta( $this->comment_id, 'akismet_error', true ) );
		$this->assertFalse( get_comment_meta( $this->comment_id, 'akismet_result', true ) );
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
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
	
	function test_history_after_retry() {
		akismet_cron_recheck( 0 );

		// history should record a retry
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'cron-retry', $history[0]['event'] );
		
		// shh don't tell Nikolay I'm running more that one test per function
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
	var $old_post;
	var $comment_author = 'alex';
	
	function setUp() {
		// make sure we don't accidentally die()
		$this->old_discard_option = get_option('akismet_discard_month');
		$this->old_post = $_POST;
		update_option('akismet_discard_month', 'false');
		
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
		$_POST = $this->old_post;
		unset( $GLOBALS['akismet_last_comment'] );
	}
	
	function test_ajax_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['spam'] = 1;
		wp_spam_comment( $this->comment_id );
		
		$this->assertEqual('true', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_ajax_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['unspam'] = 1;
		wp_unspam_comment( $this->comment_id );

		// this is not submitted to Akismet because the status didn't change (transition was from approved to approved)
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_ajax_trash_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['trash'] = 1;
		wp_trash_comment( $this->comment_id );
		
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}

	function test_bulk_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['action'] = 'spam';
		wp_spam_comment( $this->comment_id );
		
		$this->assertEqual('true', get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_bulk_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
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
}

class TestAkismetSubmitActionsSpam extends TestAkismetSubmitActions {
	var $comment_author = 'viagra-test-123';

	function test_ajax_spam_button() {
		global $wp_filter;
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
		$_POST['spam'] = 1;
		wp_spam_comment( $this->comment_id );
	
		// not submitted to Akismet because the status didn't change	
		$this->assertEqual(null, get_comment_meta( $this->comment_id, 'akismet_user_result', true ) );
	}
	
	function test_ajax_unspam_button() {
		// fake an ajax button click - we can't call admin-ajax.php directly because it calls die()
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
}
?>
