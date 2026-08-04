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

    function bulkToggle($checklist, checked) {
        $checklist.find('.plugin-tregoplugins-checklist-toggle:not(:disabled)').each(function () {
            var $checkbox = $(this);
            if ($checkbox.is(':checked') !== checked) {
                $checkbox.prop('checked', checked);
                toggleItem($checkbox, checked);
            }
        });
    }

    $(document).on('change', '.plugin-tregoplugins-checklist-toggle', function () {
        var $checkbox = $(this);
        toggleItem($checkbox, $checkbox.is(':checked'));
    });

    // Hooks::POST_SHOW_ITEM (the only version-stable hook that isn't
    // stripped by the timeline content purifier) fires right before the
    // .timeline-item wrapper closes, i.e. as a SIBLING of the task's visual
    // card (.timeline-content), not inside its .card-body. Move it in after
    // render so it visually sits glued to the task instead of floating
    // below the whole card.
    function relocateChecklists() {
        $('.timeline-item[data-itemtype="TicketTask"] > .plugin-tregoplugins-checklist').each(function () {
            var $fragment = $(this);
            var $cardBody = $fragment.closest('.timeline-item').find('.timeline-content .card-body').first();
            if ($cardBody.length) {
                $cardBody.append($fragment);
            }
        });
    }

    $(relocateChecklists);
    // Timeline entries (re)load via GLPI's own ajax calls (tab switch,
    // "reload timeline" button, adding a task); ajaxComplete catches all of
    // those without needing to guess which GLPI event/selector fired it.
    $(document).ajaxComplete(relocateChecklists);

    // Marking the task itself done/todo (native GLPI toggle) checks/unchecks
    // every checklist item on it. Wrapping the global change_task_state()
    // function instead of binding to a CSS selector: the function name is
    // the one stable contract the core template guarantees
    // (onclick="change_task_state(id, this)"), whereas the exact markup
    // around it isn't guaranteed to match between GLPI versions.
    function installChangeTaskStateWrapper() {
        if (typeof window.change_task_state !== 'function' || window.change_task_state.__tregoplugins) {
            return;
        }

        var original = window.change_task_state;
        var wrapped = function (tasks_id, target) {
            var $target = $(target);
            var $item = $target.closest('.timeline-item');
            var $checklist = $item.find('.plugin-tregoplugins-checklist');
            var taskWillBeDone = !$target.hasClass('state_2');

            var result = original.apply(this, arguments);

            if ($checklist.length) {
                bulkToggle($checklist, taskWillBeDone);
            }

            return result;
        };
        wrapped.__tregoplugins = true;
        window.change_task_state = wrapped;
    }

    $(installChangeTaskStateWrapper);
    $(document).ajaxComplete(installChangeTaskStateWrapper);
})(jQuery);
