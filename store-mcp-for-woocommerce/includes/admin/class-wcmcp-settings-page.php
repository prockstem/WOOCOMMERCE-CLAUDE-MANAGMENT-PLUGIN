<?php
/**
 * WooCommerce → Settings → MCP.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab with Connection and Tool packs sections.
 */
class WCMCP_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = WCMCP_Admin::TAB;
		$this->label = __( 'MCP', 'store-mcp-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Sections of the tab.
	 *
	 * @return array<string, string>
	 */
	protected function get_own_sections() {
		return array(
			''      => __( 'Connection', 'store-mcp-for-woocommerce' ),
			'packs' => __( 'Tool packs', 'store-mcp-for-woocommerce' ),
			'logs'  => __( 'Logs', 'store-mcp-for-woocommerce' ),
		);
	}

	/**
	 * Fields for the Logs section.
	 *
	 * @return array
	 */
	protected function get_settings_for_logs_section() {
		return array(
			array(
				'title' => __( 'Action log', 'store-mcp-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Records each tool call: date, tool, key, owner and result. Emails, phone numbers and street addresses are masked before saving; API keys and confirmation tokens are never stored.', 'store-mcp-for-woocommerce' ),
				'id'    => 'wcmcp_logs',
			),
			array(
				'title'   => __( 'Enable logging', 'store-mcp-for-woocommerce' ),
				'desc'    => __( 'Log changes, refunds, deletes, previews, errors and refused calls.', 'store-mcp-for-woocommerce' ),
				'id'      => 'wcmcp_log_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Log read-only calls', 'store-mcp-for-woocommerce' ),
				'desc'    => __( 'Also log successful lookups (lists, details, reports). Makes the log much longer.', 'store-mcp-for-woocommerce' ),
				'id'      => 'wcmcp_log_reads',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'             => __( 'Keep entries for', 'store-mcp-for-woocommerce' ),
				'desc'              => __( 'days (1–365). Older entries are deleted daily.', 'store-mcp-for-woocommerce' ),
				'id'                => 'wcmcp_log_retention_days',
				'type'              => 'number',
				'default'           => WCMCP_Log::DEFAULT_RETENTION,
				'css'               => 'width:80px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 365,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcmcp_logs',
			),
		);
	}

	/**
	 * Fields for the Tool packs section, saved by WooCommerce with its own nonce and capability check.
	 *
	 * @return array
	 */
	protected function get_settings_for_packs_section() {
		$settings = array(
			array(
				'title' => __( 'Tool packs', 'store-mcp-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Switched-off packs are removed from the tool list and refused if called anyway. Store info is always available. Packs that can change money or remove data start switched off.', 'store-mcp-for-woocommerce' ),
				'id'    => 'wcmcp_packs',
			),
		);

		foreach ( WCMCP_Packs::all() as $id => $pack ) {
			$settings[] = array(
				'title'   => $pack['label'],
				'desc'    => $pack['description'],
				'id'      => WCMCP_Packs::option_name( $id ),
				'type'    => 'checkbox',
				'default' => $pack['default'] ? 'yes' : 'no',
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wcmcp_packs',
		);

		return $settings;
	}

	/**
	 * Renders the current section.
	 *
	 * @return void
	 */
	public function output() {
		global $current_section;

		if ( 'packs' === $current_section ) {
			parent::output();
			return;
		}

		if ( 'logs' === $current_section ) {
			parent::output();

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
			$page    = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;
			$notice  = WCMCP_Admin::pull_notice();
			$log     = WCMCP_Log::query( $page, 50 );
			$pages   = max( 1, (int) ceil( $log['total'] / 50 ) );
			include WCMCP_DIR . 'includes/admin/views/html-logs.php';
			return;
		}

		// The Connection section has its own buttons; WooCommerce's Save button would do nothing there.
		$GLOBALS['hide_save_button'] = true;

		$notice   = WCMCP_Admin::pull_notice();
		$new_key  = WCMCP_Admin::pull_new_key();
		$keys     = WCMCP_Api_Keys::all();
		$levels   = WCMCP_Api_Keys::permission_levels();
		$problems = WCMCP_Requirements::problems();
		$configs  = $new_key
			? WCMCP_Admin::client_configs( $new_key['consumer_key'], $new_key['consumer_secret'] )
			: WCMCP_Admin::client_configs();

		include WCMCP_DIR . 'includes/admin/views/html-connection.php';
	}
}
