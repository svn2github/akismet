<?php

class TestAkismetRetry extends UnitTestCase {
	var $comment_id;
	
	function setUp() {
		$this->comment_id = wp_insert_comment( array(
			'comment_post_ID' => 1,
			'comment_author' => 'alex',
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
	}
	
	function test_history_after_retry() {
		akismet_cron_recheck( 0 );

		// history should record a retry
		$history = akismet_get_comment_history( $this->comment_id );
		$this->assertEqual( 'cron-retry', $history[0]['event'] );
		
		// shh don't tell Nikolay I'm running more that one test per function
	}
}


?>