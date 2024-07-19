jQuery(
	function ($) {
		const { __ } = wp.i18n;

		// Refresh the "Status" box every 10 seconds
		function blcUpdateStatus() {

			$.ajax(
				{
					dataType: "json",
					url: ajaxurl,
					cache: false,
					data: {
						'action': 'blc_full_status',
					},
				}
			).done(
				data => {
                if (data) {
                    let text = data.text || '';

                    $( '.blc_full_status' ).html( text );
                    let broken = data.status && data.status.broken_links || 0;
                    let f      = data.status && data.status.f || 0;

                    if ( broken) {
                        $( '.blc-broken-count' ).html( broken ).show();
                        $( '.filter-broken-link-count' ).html( broken );
                    } else {
                        $( '.blc-broken-count' ).hide();
                        $( '.filter-broken-link-count' ).html( 0 );
                    }

                } else {
						$( '.blc_full_status' ).html( __( '[ Network error ]', 'broken-link-checker' ) );
					}
					}
			).fail(
				() => $( '.blc_full_status' ).html(
					__(
					'[ Network error ]',
                        'broken-link-checker'
				)
				)
			).always(
				// This ensure that the request do not pile up ( versus setInterval )
				() => setTimeout(
					blcUpdateStatus,
					10000
				)
			);

		}
		blcUpdateStatus();
	}
);