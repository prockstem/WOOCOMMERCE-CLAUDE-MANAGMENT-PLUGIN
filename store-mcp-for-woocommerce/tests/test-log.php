<?php
/**
 * The action log masks personal data and never stores secrets.
 *
 * @package WCMCP
 */

/**
 * Log tests.
 */
class Test_WCMCP_Log extends WCMCP_Test_Case {

	/**
	 * Emails, phones and addresses are masked; dates and secrets handled correctly.
	 */
	public function test_masking() {
		$masked = WCMCP_Log::mask(
			array(
				'billing'            => array(
					'email'     => 'jane.doe@example.com',
					'phone'     => '+1 (555) 123-4567',
					'address_1' => '12 Secret Lane',
					'postcode'  => 'SW1A 1AA',
					'country'   => 'GB',
				),
				'note'               => 'Call 555 987 6543 or mail bob@example.org by 2026-11-02',
				'confirmation_token' => '123.abc',
				'consumer_secret'    => 'cs_secret',
				'email_restrictions' => array( '*@example.com' ),
				'changes'            => array(
					array(
						'field' => 'billing.phone',
						'from'  => '+44 7700 900123',
						'to'    => '+44 7700 900456',
					),
				),
			)
		);

		$this->assertSame( 'j***@example.com', $masked['billing']['email'] );
		$this->assertSame( '***67', $masked['billing']['phone'] );
		$this->assertSame( '[masked]', $masked['billing']['address_1'] );
		$this->assertSame( '[masked]', $masked['billing']['postcode'] );
		$this->assertSame( 'GB', $masked['billing']['country'] );
		$this->assertSame( 'Call ***43 or mail b***@example.org by 2026-11-02', $masked['note'] );
		$this->assertArrayNotHasKey( 'confirmation_token', $masked );
		$this->assertArrayNotHasKey( 'consumer_secret', $masked );
		$this->assertSame( array( '*@example.com' ), $masked['email_restrictions'] );
		$this->assertSame( '***23', $masked['changes'][0]['from'] );
		$this->assertSame( '***56', $masked['changes'][0]['to'] );
	}

	/**
	 * A logged write stores masked data only.
	 */
	public function test_logged_call_contains_no_raw_personal_data() {
		global $wpdb;
		WCMCP_Log::clear();
		$this->use_key( $this->create_key( 'read_write' ) );

		WCMCP_Log::record(
			'wcmcp/customers-update',
			WCMCP_Permissions::OP_WRITE,
			array(
				'customer_id'        => 5,
				'email'              => 'private.person@example.net',
				'billing'            => array( 'phone' => '07700 900999' ),
				'confirmation_token' => 'secret-token',
			),
			array( 'changes' => array() )
		);

		$details = (string) $wpdb->get_var( 'SELECT details FROM ' . WCMCP_Log::table() . ' ORDER BY id DESC LIMIT 1' );
		$this->assertStringNotContainsString( 'private.person@example.net', $details );
		$this->assertStringNotContainsString( '900999', $details );
		$this->assertStringNotContainsString( 'secret-token', $details );
		$this->assertStringContainsString( 'p***@example.net', $details );
	}

	/**
	 * Retention removes old rows and keeps recent ones.
	 */
	public function test_retention_cleanup() {
		global $wpdb;
		WCMCP_Log::clear();
		update_option( 'wcmcp_log_retention_days', 7 );

		foreach ( array( 'old' => 8, 'new' => 6 ) as $tool => $days ) {
			$wpdb->insert(
				WCMCP_Log::table(),
				array(
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
					'tool'       => $tool,
					'operation'  => 'write',
					'result'     => 'success',
				)
			);
		}

		$this->assertSame( 1, WCMCP_Log::cleanup() );
		$this->assertSame( array( 'new' ), $wpdb->get_col( 'SELECT tool FROM ' . WCMCP_Log::table() ) );
	}
}
