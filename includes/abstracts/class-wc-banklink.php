<?php
abstract class WC_Banklink extends WC_Payment_Gateway {

	/**
	 * WC_Banklink
	 */
	function __construct() {
		// Get icon and set notification URL
		$this->icon       = $this->get_option( 'logo', plugins_url( 'assets/img/' . $this->id . '.png', WC_ESTONIAN_GATEWAYS_MAIN_FILE ) );
		$this->has_fields = false;
		$this->notify_url = WC()->api_request_url( get_class( $this ) );

		// Get the settings
		$this->title       = $this->get_option( 'title', $this->method_title );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->description = $this->get_option( 'description' );

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Payment listener/API hook
		add_action( 'woocommerce_api_' . strtolower( esc_attr( get_class( $this ) ) ), array( $this, 'check_bank_response' ) );

		// Actions
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_' . $this->id . '_check_response', array( $this, 'validate_bank_response' ) );
	}

	/**
	 * Set settings fields
	 *
	 * @return void
	 */
	function init_form_fields() {
		// Set fields
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable banklink', 'wc-gateway-estonia-banklink' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'label'   => __( 'Enable this payment gateway', 'wc-gateway-estonia-banklink' ),
			),
			'title'       => array(
				'title'       => __( 'Title', 'wc-gateway-estonia-banklink' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which user sees during checkout.', 'wc-gateway-estonia-banklink' ),
				'default'     => $this->get_title(),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Customer message', 'wc-gateway-estonia-banklink' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'This will be visible when user selects this payment gateway during checkout.', 'wc-gateway-estonia-banklink' ),
				'desc_tip'    => true,
			),
			'logo'        => array(
				'title'       => __( 'Logo', 'wc-gateway-estonia-banklink' ),
				'type'        => 'text',
				'default'     => $this->icon,
				'description' => __( 'Enter full URL to set a custom logo. You could upload the image to your media library first.', 'wc-gateway-estonia-banklink' ),
				'desc_tip'    => true,
			),
			'countries'   => array(
				'title'       => __( 'Country availability', 'wc-gateway-estonia-banklink' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => array_merge(
					array( 'all' => __( 'All countries', 'wc-gateway-estonia-banklink' ) ),
					WC()->countries->get_countries()
				),
				'default'     => array( 'all' ),
				'description' => __( 'Specify countries where this method should be available. Select only "all countries" to sell everywhere.', 'wc-gateway-estonia-banklink' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Generate some kind of reference number
	 *
	 * @param  string $stamp Purchase ID
	 * @return string        Reference number
	 */
	function generate_ref_num( $stamp ) {
		$chcs = array( 7, 3, 1 );
		$sum  = 0;
		$pos  = 0;

		for ( $i = 0; $i < strlen( $stamp ); $i++ ) {
			$x   = (int) ( substr( $stamp, strlen( $stamp ) - 1 - $i, 1 ) );
			$sum = $sum + ( $x * $chcs[ $pos ] );

			if ( $pos == 2 ) {
				$pos = 0;
			} else {
				$pos = $pos + 1;
			}
		}

		$x   = 10 - ( $sum % 10 );
		$sum = ( $x != 10 ) ? $x : 0;

		return $stamp . $sum;
	}

	/**
	 * Payment processing
	 *
	 * @param  integer $order_id Order ID
	 * @return array             Redirect URL and result (success)
	 */
	function process_payment( $order_id ) {
		// Get the order
		$order = wc_get_order( $order_id );

		// Redirect
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Adds form to the receipt page
	 *
	 * @param  integer $order_id Order ID
	 * @return void
	 */
	function receipt_page( $order_id ) {
		// Say thank you :)
		echo apply_filters( 'the_content', sprintf( __( 'Thank you for your order, please click the button below to pay with %s in case automatic redirection does not work.', 'wc-gateway-estonia-banklink' ), $this->get_title() ) );

		// Generate the form
		echo $this->output_gateway_redirection_form( $order_id );
	}

	/**
	 * Is this gateway available?
	 *
	 * @return boolean
	 */
	function is_available() {
		if ( WC()->customer == null ) {
			return false;
		}

		return $this->get_option( 'enabled', 'no' ) != 'no' && array_intersect( array( 'all', wc_estonian_gateways_get_customer_billing_country() ), $this->get_option( 'countries' ) );
	}

	/**
	 * Get default language code
	 *
	 * @return string Language code
	 */
	function get_default_language() {
		$locale = get_locale();

		if ( strlen( $locale ) > 2 ) {
			$locale = substr( $locale, 0, 2 );
		}

		return $locale;
	}

	/**
	 * Easier debugging
	 *
	 * @param  mixed $data Data to be saved
	 * @return void
	 */
	function debug( $data, $level = 'debug' ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === true ) {
			$log_data = is_array( $data ) || is_object( $data ) ? print_r( $data, true ) : var_export( $data, true );

			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				$logger->log( $level, $log_data, array( 'source' => $this->id ) );
			} else {
				$logger = new WC_Logger();
				$logger->add( $this->id, $log_data );
			}
		}
	}

	/**
	 * Creates a filter for altering transaction data
	 *
	 * @param  array    $data  Transaction data
	 * @param  WC_Order $order Order
	 * @return array           Modified transaction data
	 */
	function hookable_transaction_data( $data, $order ) {
		return apply_filters( 'woocommerce_' . $this->id . '_gateway_transaction_fields', $data, $order );
	}

	/**
	 * Generate form that redirects users to bank
	 *
	 * @param  string $url    URL to redirect
	 * @param  array  $fields Fields to include as hidden inputs
	 * @return string         HTML for the form
	 */
	function get_redirect_form( $url, $fields ) {
		// Start form
		$form = sprintf( '<form action="%s" method="post" id="banklink_%s_submit_form">', esc_attr( $url ), $this->id );

		// Add fields to form inputs
		foreach ( $fields as $name => $value ) {
			$form .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), htmlspecialchars( $value ) );
		}

		// Show "Pay" button and end the form
		$form .= sprintf( '<input type="submit" name="send_banklink" class="button" value="%s">', __( 'Pay', 'wc-gateway-estonia-banklink' ) );
		$form .= '</form>';

		// Debug output
		$this->debug( $fields );

		// Add inline JS
		wc_enqueue_js( sprintf( 'jQuery( "#banklink_%s_submit_form" ).submit();', $this->id ) );

		return apply_filters( sprintf( 'woocommerce_%s_gateway_redirect_form_html', $this->id ), $form, $fields );
	}
}
