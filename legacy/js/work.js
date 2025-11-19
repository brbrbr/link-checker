(function ($) {

    //(Re)starts the background worker thread
    function blcDoWork() {

        $.ajax(
            {
                url: blcwork.ajaxurl,
                cache: false,
                data: {
                    action: 'blc_work',
                    _ajax_nonce: blcwork.nonce
                }
            }
        );
    }

    //Call it the first time
    blcDoWork();

    //Then call it periodically every X seconds
    setInterval(blcDoWork, blcwork.interval);

})(jQuery);