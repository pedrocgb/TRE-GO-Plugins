(function ($) {
    $(document).on('submit', 'form:has(> button.plugin-tregoplugins-dispatch-btn)', function (event) {
        var $button = $(this).find('button.plugin-tregoplugins-dispatch-btn');
        var message = $button.data('plugin-tregoplugins-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})(jQuery);
