jQuery( function( $ ) {
    // Function to handle form submission (using document.body for broader compatibility).
    $( document.body ).on( 'checkout_place_order_transactworld', function() { // Use our gateway's ID
        $.blockUI({
            message: wc_checkout_params.i18n_checkout_place_order, // Use WC provided message,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            },
            css: {
                padding:        '20px',
                zIndex:         '9999999',
                textAlign:      'center',
                color:          '#555',
                border:         '3px solid #aaa',
                backgroundColor:'#fff',
                cursor:         'wait',
                lineHeight:     '24px'
            }
        });
    });
});