(function ($) {
    var SUBMIT_DELAY_MS = 220; // matches the CSS transition duration

    $(document).on('click', '.plugin-tregoplugins-dispatch-btn:not(:disabled)', function (event) {
        event.preventDefault();
        $(this).closest('.plugin-tregoplugins-dispatch-action').addClass('plugin-tregoplugins-confirming');
    });

    $(document).on('click', '.plugin-tregoplugins-dispatch-no', function (event) {
        event.preventDefault();
        $(this).closest('.plugin-tregoplugins-dispatch-action').removeClass('plugin-tregoplugins-confirming');
    });

    $(document).on('click', '.plugin-tregoplugins-dispatch-yes', function (event) {
        event.preventDefault();

        var $action = $(this).closest('.plugin-tregoplugins-dispatch-action');
        var $form = $action.find('form.plugin-tregoplugins-dispatch-form');

        $action.removeClass('plugin-tregoplugins-confirming');

        window.setTimeout(function () {
            $form.trigger('submit');
        }, SUBMIT_DELAY_MS);
    });
})(jQuery);
