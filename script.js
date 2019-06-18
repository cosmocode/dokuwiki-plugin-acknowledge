jQuery(function () {

    var $aContainer = jQuery('.plugin-acknowledge-assign');

    $aContainer.on('submit', function (event) {
        event.preventDefault();
        var $form = jQuery( event.target ),
            ack = $form.find( "input[name='ack']" )[0];

        $aContainer.load(
            DOKU_BASE + "lib/exe/ajax.php",
            {
                call: "plugin_acknowledge_assign",
                id: JSINFO.id,
                ack: ack.checked
            }
        );
    });
    $aContainer.load(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_acknowledge_assign',
            id: JSINFO.id
        }
    );
});
