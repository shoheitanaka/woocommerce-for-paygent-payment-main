import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
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
	const description      = decodeEntities( settings?.description || '' );
	const paidyDescription = decodeEntities( settings?.paidyDescription || '' );

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
