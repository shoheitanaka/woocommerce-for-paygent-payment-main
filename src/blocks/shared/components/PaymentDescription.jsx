import { RawHTML } from '@wordpress/element';

/**
 * Payment method description shown below the label in Block checkout.
 *
 * The description is sanitized with wp_kses_post() on the PHP side and is
 * rendered via RawHTML (innerHTML), which parses entities natively — do NOT
 * decodeEntities() first, or kses-passed entity text would become live markup.
 *
 * @param {Object} props
 * @param {Object} props.settings  Data from PHP get_payment_method_data().
 */
const PaymentDescription = ( { settings } ) => {
	const description = settings?.description || '';

	if ( ! description ) {
		return null;
	}

	return (
		<div className="wc-paygent-payment-description">
			<RawHTML>{ description }</RawHTML>
		</div>
	);
};

export default PaymentDescription;
