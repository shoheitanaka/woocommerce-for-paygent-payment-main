import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { RawHTML } from '@wordpress/element';
import { PaymentLabel, PaymentDescription } from '../shared/components';

const settings = getSetting( 'paygent_paidy_data', null );
if ( ! settings ) {
	throw new Error( 'paygent_paidy_data not found' );
}

/**
 * Paidy checkout content.
 *
 * Paidy is a redirect-type gateway: no payment form is needed.
 * We show the standard description plus the Paidy-specific description
 * (both are optional in admin settings).
 */
const PaidyContent = () => {
	// No decodeEntities here: RawHTML (innerHTML) already parses entities.
	// Decoding first would turn kses-passed entity text (e.g. &lt;img …&gt;)
	// back into live markup.
	const description      = settings?.description || '';
	const paidyDescription = settings?.paidyDescription || '';

	return (
		<div className="wc-paygent-paidy-content">
			{ description && (
				<div className="wc-paygent-payment-description">
					<RawHTML>{ description }</RawHTML>
				</div>
			) }
			{ paidyDescription && (
				<div className="wc-paygent-paidy-description">
					<RawHTML>{ paidyDescription }</RawHTML>
				</div>
			) }
		</div>
	);
};

registerPaymentMethod( {
	name:    'paygent_paidy',
	label:   <PaymentLabel settings={ settings } />,
	content: <PaidyContent />,
	edit:    <PaymentDescription settings={ settings } />,
	canMakePayment: () => true,
	ariaLabel: settings.title || 'Paidy',
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
