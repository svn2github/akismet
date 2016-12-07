<div id="akismet-plugin-container">
	<div class="akismet-masthead">
		<div class="akismet-masthead__inside-container">
			<div class="akismet-masthead__logo-container">
				<img class="akismet-masthead__logo" src="../wp-content/plugins/akismet/_inc/img/logo-full-2x.png" alt="Akismet" />
			</div>
		</div>
	</div>
	<div class="akismet-lower">
		<?php Akismet_Admin::display_status(); ?>
		<p><?php esc_html_e( 'Akismet eliminates spam from your site. To set up Akismet, select one of the options below.', 'akismet' ); ?></p>
		<?php if ( $akismet_user && in_array( $akismet_user->status, array( 'active', 'active-dunning', 'no-sub', 'missing', 'cancelled', 'suspended' ) ) ) { ?>
			<?php if ( in_array( $akismet_user->status, array( 'no-sub', 'missing' ) ) ) { ?>
				<div class="akismet-card">
					<h3><?php esc_html_e( 'Connect via Jetpack', 'akismet' ); ?></h3>
					<div class="inside">
						<form name="akismet_activate" id="akismet_activate" action="https://akismet.com/get/" method="post" class="akismet-right" target="_blank">
							<input type="hidden" name="passback_url" value="<?php echo esc_url( Akismet_Admin::get_page_url() ); ?>"/>
							<input type="hidden" name="blog" value="<?php echo esc_url( get_option( 'home' ) ); ?>"/>
							<input type="hidden" name="auto-connect" value="<?php echo esc_attr( $akismet_user->ID ); ?>"/>
							<input type="hidden" name="redirect" value="plugin-signup"/>
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Register for Akismet' , 'akismet' ); ?>"/>
						</form>
						<p><?php echo esc_html( $akismet_user->user_email ); ?></p>
					</div>
				</div>
			<?php } elseif ( $akismet_user->status == 'cancelled' ) { ?>
				<div class="akismet-card">
					<h3><?php esc_html_e( 'Connect via Jetpack', 'akismet' ); ?></h3>
					<div class="inside">
						<form name="akismet_activate" id="akismet_activate" action="https://akismet.com/get/" method="post" class="akismet-right" target="_blank">
							<input type="hidden" name="passback_url" value="<?php echo esc_url( Akismet_Admin::get_page_url() ); ?>"/>
							<input type="hidden" name="blog" value="<?php echo esc_url( get_option( 'home' ) ); ?>"/>
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $akismet_user->ID ); ?>"/>
							<input type="hidden" name="redirect" value="upgrade"/>
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Reactivate Akismet' , 'akismet' ); ?>"/>
						</form>
						<p><?php echo esc_html( sprintf( __( 'Your subscription for %s is cancelled.' , 'akismet' ), $akismet_user->user_email ) ); ?></p>
					</div>
				</div>
			<?php } elseif ( $akismet_user->status == 'suspended' ) { ?>
				<div class="centered akismet-card">
					<h3><?php esc_html_e( 'Connected via Jetpack' , 'akismet' ); ?></h3>
					<div class="inside">
						<p class="akismet-alert-text"><?php echo esc_html( sprintf( __( 'Your subscription for %s is suspended.' , 'akismet' ), $akismet_user->user_email ) ); ?></p>
						<p><?php esc_html_e( 'No worries! Get in touch and we&#8217;ll sort this out.', 'akismet' ); ?></p>
						<a href="https://akismet.com/contact" class="button button-primary"><?php esc_html_e( 'Contact Akismet support' , 'akismet' ); ?></a>
					</div>
				</div>
			<?php } else { // ask do they want to use akismet account found using jetpack wpcom connection ?>
				<div class="akismet-card">
					<h3><?php esc_html_e( 'Connect via Jetpack', 'akismet' ); ?></h3>
					<div class="inside">
						<form name="akismet_use_wpcom_key" action="<?php echo esc_url( Akismet_Admin::get_page_url() ); ?>" method="post" id="akismet-activate" class="akismet-right">
							<input type="hidden" name="key" value="<?php echo esc_attr( $akismet_user->api_key );?>"/>
							<input type="hidden" name="action" value="enter-key">
							<?php wp_nonce_field( Akismet_Admin::NONCE ) ?>
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Use this account' , 'akismet' ); ?>"/>
						</form>
						<p><?php echo esc_html( $akismet_user->user_email ); ?></p>
					</div>
				</div>
			<?php } ?>
			<div class="akismet-card">
				<h3><?php esc_html_e( 'Sign up for a plan with a different email address', 'akismet' ); ?></h3>
				<div class="inside">
					<?php Akismet::view( 'get', array( 'text' => __( 'Sign up with a different email address' , 'akismet' ), 'classes' => array( 'akismet-right', 'button', 'button-secondary' ) ) ); ?>
					<p><?php esc_html_e( 'Use this option to use Akismet independently of your Jetpack connection.', 'akismet' ); ?></p>
				</div>
			</div>
			<div class="akismet-card">
				<h3><?php esc_html_e( 'Enter an API key', 'akismet' ); ?></h3>
				<div class="inside">
					<form action="<?php echo esc_url( Akismet_Admin::get_page_url() ); ?>" method="post" class="akismet-right">
						<input id="key" name="key" type="text" size="15" value="" class="regular-text code">
						<input type="hidden" name="action" value="enter-key">
						<?php wp_nonce_field( Akismet_Admin::NONCE ) ?>
						<input type="submit" name="submit" id="submit" class="button button-secondary" value="<?php esc_attr_e( 'Use this key', 'akismet' );?>">
					</form>
					<p><?php esc_html_e( 'Already have your key? Enter it here.', 'akismet' ); ?></p>
				</div>
			</div>
		<?php } else { ?>
			<div class="akismet-card">
				<h3><?php esc_html_e( 'Activate Akismet' , 'akismet' );?></h3>
				<div class="inside">
					<?php Akismet::view( 'get', array( 'text' => __( 'Get your API key' , 'akismet' ), 'classes' => array( 'akismet-right', 'button', 'button-primary' ) ) ); ?>
					<p><?php esc_html_e( 'Log in or sign up now.', 'akismet' ); ?></p>
				</div>
			</div>
			<div class="akismet-card">
				<h3><?php esc_html_e( 'Manually enter an API key', 'akismet' ); ?></h3>
				<div class="inside">
					<form action="<?php echo esc_url( Akismet_Admin::get_page_url() ); ?>" method="post">
						<input type="hidden" name="action" value="enter-key">
						<p>
							<?php esc_html_e( 'If you already know your API key:', 'akismet' ); ?>
							<input id="key" name="key" type="text" size="15" value="<?php echo esc_attr( Akismet::get_api_key() ); ?>" class="regular-text code" size="15" />
							<?php wp_nonce_field( Akismet_Admin::NONCE ); ?>
							<input type="submit" name="submit" id="submit" class="button button-secondary" value="<?php esc_attr_e( 'Use this key', 'akismet' );?>">
						</p>
					</form>
				</div>
			</div>
		<?php } ?>
	</div>
</div>