jQuery(function () {

    let $aContainer = jQuery('.plugin-acknowledge-assign');

    // if no container is found, create one in the last section
    if ($aContainer.length === 0) {
        const section = jQuery('.dokuwiki.mode_show')
            .find('div.level1, div.level2, div.level3, div.level4, div.level5')
            .filter(function (idx, el) {
                return jQuery(el).parents('ul, ol, aside, nav, footer, header').length === 0;
            })
            .last();
        if (section.length === 0) {
            return;
        }
        $aContainer = jQuery('<div class="plugin-acknowledge-assign"></div>');
        section.append($aContainer);
    }

    $aContainer.on('submit', function (event) {
        event.preventDefault();
        var $form = jQuery(event.target),
            ack = $form.find("input[name='ack']")[0];

        $aContainer.load(
            DOKU_BASE + "lib/exe/ajax.php",
            {
                call: "plugin_acknowledge_assign",
                id: JSINFO.id,
                ack: ack.checked === true ? 1 : 0
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
