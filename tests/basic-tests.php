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

// test the main API calls with a simple comment
class TestAkismetAutoCheckComment extends UnitTestCase {
	var $comment;
	var $comment_id;
	var $old_discard_option;
	
	function setUp() {
		// make sure we don't accidentally die()
		$this->old_discard_option = get_option('akismet_discard_month');
		update_option('akismet_discard_month', 'false');
		
		$this->comment = array(
			'comment_post_ID' => 1,
			'comment_author' => 'alex',
			'comment_author_email' => 'alex@example.com',
			'comment_content' => 'This is a test: '. __CLASS__,
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
	}
	
	function test_auto_comment_check_result() {
		$this->assertEqual( 'approved', wp_get_comment_status( $this->comment_id ) );
	}
	
	function test_auto_comment_check_meta_result() {
	}

}

?>