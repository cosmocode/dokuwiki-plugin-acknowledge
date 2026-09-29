jQuery(function () {

    /**
     * admin interface: autocomplete users
     */
    function adminAutocomplete($form) {

        $form.find('input[name="user"]')
            .autocomplete({
                source: function (request, response) {
                    jQuery.getJSON(DOKU_BASE + 'lib/exe/ajax.php?call=plugin_acknowledge_autocomplete', {
                        user: request.term,
                        sectok: $form.find('input[name="sectok"]').val()
                    }, response);
                },
                minLength: 1
            });
        $form.find('input[name="pg"]')
            .autocomplete({
                source: function (request, response) {
                    jQuery.getJSON(DOKU_BASE + 'lib/exe/ajax.php?call=plugin_acknowledge_autocomplete', {
                        pg: request.term,
                        sectok: $form.find('input[name="sectok"]').val()
                    }, response);
                },
                minLength: 3
            });
    }

    const $form = jQuery('.dokuwiki.mode_admin div.plugin_acknowledgement_admin form#acknowledge__user-autocomplete');
    if ($form.length) {
        adminAutocomplete($form);
    }

    /*
     * Handle assignments banner
     */

    // the container comes from the ~~ACK:...~~ syntax or from action/banner.php
    const $aContainer = jQuery('.plugin-acknowledge-banner').first();
    if ($aContainer.length === 0) {
        return;
    }

    // the revision currently viewed, 0 for the latest one
    const ackRev = (JSINFO.plugins && JSINFO.plugins.acknowledge)
        ? JSINFO.plugins.acknowledge.rev : 0;

    // on-page report: load the full user list when a count is clicked
    $aContainer.on('click', 'a.plugin-acknowledge-loadusers', function (event) {
        event.preventDefault();
        const $link = jQuery(this);
        const $target = jQuery('<div class="plugin-acknowledge-userlist"></div>');
        $link.replaceWith($target);
        $target.load(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_acknowledge_userlist',
                id: $link.data('id'),
                status: $link.data('status')
            }
        );
    });

    $aContainer.on('submit', function (event) {
        event.preventDefault();
        const $form = jQuery(event.target),
            ack = $form.find("input[name='ack']")[0];

        $aContainer.load(
            DOKU_BASE + "lib/exe/ajax.php",
            {
                call: "plugin_acknowledge_acknowledge",
                id: JSINFO.id,
                rev: ackRev,
                ack: ack.checked === true ? 1 : 0
            }
        );
    });
    $aContainer.load(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_acknowledge_acknowledge',
            id: JSINFO.id,
            rev: ackRev
        },
        response => {
            // remove container if no data to show
            if (response === '') {
                $aContainer.remove();
            }
        }
    );
});
