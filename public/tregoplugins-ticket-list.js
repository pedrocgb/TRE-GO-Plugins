(function ($) {
    var STORAGE_KEY = 'tregoplugins:ticket-list:show-ola-progress';
    var CORE_SEARCH_OPTION_ID = '186';
    var PLUGIN_COLUMN_ID = 'plugin-tregoplugins-ola-progress';

    function isTicketListContext() {
        var url = new URL(window.location.href);
        return url.pathname.endsWith('/front/ticket.php') || url.searchParams.get('itemtype') === 'Ticket';
    }

    function readPreference() {
        var storedValue = window.localStorage.getItem(STORAGE_KEY);
        if (storedValue === null) {
            return true;
        }

        return storedValue === '1';
    }

    function writePreference(enabled) {
        window.localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
        $('.tregoplugins-ola-progress-toggle').prop('checked', enabled);
    }

    function enhanceAllTables() {
        if (!isTicketListContext()) {
            return;
        }

        $('.search-results').each(function () {
            enhanceTable($(this));
        });
    }

    function enhanceTable($table) {
        if (!$table.length || $table.data('tregopluginsOlaReady')) {
            return;
        }

        if (!$table.find('thead th').length) {
            return;
        }

        $table.data('tregopluginsOlaReady', true);
        ensureToolbar($table);
        applyState($table, readPreference());
    }

    function ensureToolbar($table) {
        var $container = $table.closest('.table-responsive-lg');
        if (!$container.length || $container.prev('.tregoplugins-ola-progress-toolbar').length) {
            return;
        }

        var checkboxId = 'tregoplugins-ola-progress-toggle-' + Math.floor(Math.random() * 1000000);
        var $toolbar = $(
            "<div class='tregoplugins-ola-progress-toolbar d-flex justify-content-end mb-2'>" +
                "<div class='form-check form-switch'>" +
                    "<input class='form-check-input tregoplugins-ola-progress-toggle' type='checkbox' id='" + checkboxId + "'>" +
                    "<label class='form-check-label' for='" + checkboxId + "'>Exibir progresso da OLA (TTO)</label>" +
                "</div>" +
            "</div>"
        );

        $toolbar.find('input').prop('checked', readPreference()).on('change', function () {
            var enabled = $(this).is(':checked');
            writePreference(enabled);
            $('.search-results').each(function () {
                applyState($(this), enabled);
            });
        });

        $container.before($toolbar);
    }

    function applyState($table, enabled) {
        var coreColumnIndex = findColumnIndex($table, CORE_SEARCH_OPTION_ID);
        if (coreColumnIndex >= 0) {
            localizeCoreColumnHeader($table, coreColumnIndex);
            setColumnVisibility($table, coreColumnIndex, enabled);
            return;
        }

        if (!enabled) {
            removePluginColumn($table);
            return;
        }

        ensurePluginColumn($table);
        fetchPluginColumnData($table);
    }

    function findColumnIndex($table, searchOptionId) {
        var index = -1;
        $table.find('thead th').each(function (currentIndex) {
            if ($(this).data('searchoptId') == searchOptionId) {
                index = currentIndex;
                return false;
            }
        });

        return index;
    }

    function localizeCoreColumnHeader($table, columnIndex) {
        var $header = $table.find('thead th').eq(columnIndex);
        if ($header.data('tregopluginsLocalized')) {
            return;
        }

        var $sortIndicator = $header.find('.sort-indicator').detach();
        $header.text('Progresso da OLA (TTO)');
        if ($sortIndicator.length) {
            $header.append(' ').append($sortIndicator);
        }
        $header.attr('title', 'Tempo para assumir com a OLA');
        $header.data('tregopluginsLocalized', true);
    }

    function setColumnVisibility($table, columnIndex, enabled) {
        $table.find('thead tr, tbody tr').each(function () {
            var $cells = $(this).children();
            if ($cells.length > columnIndex) {
                $cells.eq(columnIndex).toggle(enabled);
            }
        });
    }

    function ensurePluginColumn($table) {
        if (findColumnIndex($table, PLUGIN_COLUMN_ID) >= 0) {
            return;
        }

        $table.find('thead tr').each(function () {
            $(this).append("<th data-searchopt-id='" + PLUGIN_COLUMN_ID + "' title='Tempo para assumir com a OLA'>Progresso da OLA (TTO)</th>");
        });

        $table.find('tbody tr').each(function () {
            var $row = $(this);
            if ($row.children('[colspan]').length) {
                return;
            }

            $row.append("<td class='tregoplugins-ola-progress-cell-wrapper'><span class='text-muted'>Carregando...</span></td>");
        });
    }

    function removePluginColumn($table) {
        var columnIndex = findColumnIndex($table, PLUGIN_COLUMN_ID);
        if (columnIndex < 0) {
            return;
        }

        $table.find('thead tr, tbody tr').each(function () {
            var $cells = $(this).children();
            if ($cells.length > columnIndex) {
                $cells.eq(columnIndex).remove();
            }
        });
    }

    function fetchPluginColumnData($table) {
        var ids = [];
        var rowMap = {};

        $table.find('tbody tr').each(function () {
            var $row = $(this);
            if ($row.children('[colspan]').length) {
                return;
            }

            var ticketId = extractTicketId($row);
            var $cell = $row.children().last();
            if (!ticketId) {
                $cell.html("<span class='text-muted'>-</span>");
                return;
            }

            rowMap[ticketId] = $cell;
            ids.push(ticketId);
        });

        if (!ids.length) {
            return;
        }

        $.ajax({
            url: CFG_GLPI.root_doc + '/plugins/tregoplugins/front/ola_progress.php',
            method: 'GET',
            dataType: 'json',
            traditional: true,
            data: {
                ids: ids
            }
        }).done(function (response) {
            var results = response && response.results ? response.results : {};

            $.each(rowMap, function (ticketId, $cell) {
                var html = results[ticketId] || '';
                $cell.html(html || "<span class='text-muted'>-</span>");
            });
        }).fail(function () {
            $.each(rowMap, function (_, $cell) {
                $cell.html("<span class='text-warning'>Indisponivel</span>");
            });
        });
    }

    function extractTicketId($row) {
        var checkboxName = $row.find('input.massive_action_checkbox').attr('name') || '';
        var checkboxMatch = checkboxName.match(/\[(\d+)\]$/);
        if (checkboxMatch) {
            return parseInt(checkboxMatch[1], 10);
        }

        var href = $row.find("a[href*='ticket.form.php']").first().attr('href') || '';
        if (!href) {
            return 0;
        }

        var url = new URL(href, window.location.origin);
        return parseInt(url.searchParams.get('id') || '0', 10);
    }

    $(function () {
        enhanceAllTables();

        var observer = new MutationObserver(function () {
            window.clearTimeout(window.tregopluginsOlaObserverTimer);
            window.tregopluginsOlaObserverTimer = window.setTimeout(enhanceAllTables, 60);
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });
})(jQuery);
