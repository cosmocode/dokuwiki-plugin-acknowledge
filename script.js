jQuery(function () {

    jQuery('.plugin-acknowledge-assign').load(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_acknowledge_assign',
            id: JSINFO.id
        },
        function () {
            jQuery("#ackForm").submit(function(event) {
                event.preventDefault();
                var $form = jQuery( this ),
                    ack = $form.find( "input[name='ack']" )[0];

                jQuery(".plugin-acknowledge-assign").load(
                    DOKU_BASE + "lib/exe/ajax.php",
                    {
                        call: "plugin_acknowledge_assign",
                        id: JSINFO.id,
                        ack: ack.checked
                    }
                );
            });
        }
    );
});
