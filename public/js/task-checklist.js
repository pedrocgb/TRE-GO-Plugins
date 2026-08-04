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

    function toggleItem($checkbox, checked) {
        var $item = $checkbox.closest('.plugin-tregoplugins-checklist-item');
        var $checklist = $checkbox.closest('.plugin-tregoplugins-checklist');
        var previousChecked = !checked;

        clearItemError($item);
        setBusy($checkbox, true);

        return $.post(endpoint(), {
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
    }

    $(document).on('change', '.plugin-tregoplugins-checklist-toggle', function () {
        var $checkbox = $(this);
        toggleItem($checkbox, $checkbox.is(':checked'));
    });

    // Marking the task itself done/todo (native GLPI toggle, top-left of the
    // task card) checks/unchecks every checklist item along with it. Reads
    // the pre-click class since change_task_state()'s own class flip happens
    // asynchronously in its ajax .done(), always after this synchronous
    // click handler runs.
    $(document).on('click', '.timeline-item[data-itemtype="TicketTask"] .todo-list-state .state', function () {
        var $item = $(this).closest('.timeline-item');
        var $checklist = $item.find('.plugin-tregoplugins-checklist');
        if (!$checklist.length) {
            return;
        }

        var taskWillBeDone = !$(this).hasClass('state_2');

        $checklist.find('.plugin-tregoplugins-checklist-toggle:not(:disabled)').each(function () {
            var $checkbox = $(this);
            if ($checkbox.is(':checked') !== taskWillBeDone) {
                $checkbox.prop('checked', taskWillBeDone);
                toggleItem($checkbox, taskWillBeDone);
            }
        });
    });
})(jQuery);
