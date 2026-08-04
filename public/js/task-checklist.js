(function ($) {
    function endpoint() {
        return (window.CFG_GLPI && CFG_GLPI.root_doc ? CFG_GLPI.root_doc : '')
            + '/plugins/tregoplugins/ajax/checklist.item.php';
    }

    function setBusy($checkbox, busy) {
        $checkbox.prop('disabled', busy);
    }

    function updateProgress($checklist, progress) {
        $checklist.find('[data-role="progress-count"]').text(progress.done + '/' + progress.total);
        var pct = progress.total > 0 ? Math.round((progress.done / progress.total) * 100) : 0;
        $checklist.find('.plugin-tregoplugins-checklist-progress .progress-bar').css('width', pct + '%');
        $checklist.find('.plugin-tregoplugins-checklist-progress').attr('aria-valuenow', pct);
    }

    function showItemError($item, message) {
        var $error = $item.find('.plugin-tregoplugins-checklist-item-error');
        if (!$error.length) {
            $error = $('<div class="plugin-tregoplugins-checklist-item-error text-danger small" aria-live="assertive"></div>');
            $item.append($error);
        }
        $error.text(message);
    }

    function clearItemError($item) {
        $item.find('.plugin-tregoplugins-checklist-item-error').remove();
    }

    $(document).on('change', '.plugin-tregoplugins-checklist-toggle', function () {
        var $checkbox = $(this);
        var $item = $checkbox.closest('.plugin-tregoplugins-checklist-item');
        var $checklist = $checkbox.closest('.plugin-tregoplugins-checklist');
        var previousChecked = !$checkbox.is(':checked');
        var checked = $checkbox.is(':checked');

        clearItemError($item);
        setBusy($checkbox, true);

        $.post(endpoint(), {
            items_id: $checkbox.data('item-id'),
            lock_version: $checkbox.data('lock-version'),
            checked: checked ? '1' : '0'
        })
            .done(function (result) {
                if (!result || !result.ok) {
                    $checkbox.prop('checked', previousChecked);
                    showItemError($item, result && result.error === 'conflict'
                        ? 'Outra pessoa já alterou este item. Recarregue a página.'
                        : 'Não foi possível salvar. Tente novamente.');
                    return;
                }

                $checkbox.data('lock-version', result.item.lock_version);
                updateProgress($checklist, result.progress);
            })
            .fail(function () {
                $checkbox.prop('checked', previousChecked);
                showItemError($item, 'Erro de comunicação com o servidor.');
            })
            .always(function () {
                setBusy($checkbox, false);
            });
    });
})(jQuery);
