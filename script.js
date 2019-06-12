jQuery(function () {


    jQuery('.plugin-acknowledge').load(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_acknowledge_html',
            id: JSINFO.id
        }
    );
});
