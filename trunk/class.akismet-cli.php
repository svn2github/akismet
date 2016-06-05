<?php

WP_CLI::add_command( 'akismet', 'Akismet_CLI' );

class Akismet_CLI extends WP_CLI_Command {
	/**
	 * Checks one or more comments against the Akismet API.
	 *
	 * ## OPTIONS
	 * <comment_id>...
	 * : The ID(s) of the comment(s) to check.
	 *
	 * [--noaction]
	 * : Don't change the status of the comment. Just check what Akismet thinks it is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp akismet check 12345
	 *
	 * @alias comment-check
	 */
	public function check( $args, $assoc_args ) {
		foreach ( $args as $comment_id ) {
			if ( isset( $assoc_args['noaction'] ) ) {
				// Check the comment, but don't reclassify it.
				$is_spam = Akismet::check_db_comment( $comment_id, 'wp-cli' );
			}
			else {
				$is_spam = Akismet::recheck_comment( $comment_id, 'wp-cli' );
			}
			
			if ( 'true' === $is_spam ) {
				WP_CLI::success( sprintf( __( "Comment #%d is spam.", 'akismet' ), $comment_id ) );
			}
			else if ( 'false' === $is_spam ) {
				WP_CLI::success( sprintf( __( "Comment #%d is not spam.", 'akismet' ), $comment_id ) );
			}
			else {
				WP_CLI::warning( sprintf( __( "Comment #%d could not be checked.", 'akismet' ), $comment_id ) );
			}
		}
	}
}