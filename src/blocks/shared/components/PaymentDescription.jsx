import { decodeEntities } from '@wordpress/html-entities';
import { RawHTML } from '@wordpress/element';

/**
 * Payment method description shown below the label in Block checkout.
 *
 * @param {Object} props
 * @param {Object} props.settings  Data from PHP get_payment_method_data().
 */
const PaymentDescription = ( { settings } ) => {
	const description = decodeEntities( settings?.description || '' );

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
