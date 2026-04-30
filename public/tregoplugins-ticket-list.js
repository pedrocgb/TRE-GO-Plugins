(function ($) {
    var STORAGE_KEY = 'tregoplugins:ticket-list:show-ola-progress';
    var CORE_SEARCH_OPTION_ID = '186';
    var PLUGIN_COLUMN_ID = 'plugin-tregoplugins-ola-progress';
    var COLUMN_LABEL = 'Tempo para atribuição';
    var COLUMN_TITLE = 'Tempo para atribuição com a OLA';
    var REFRESH_INTERVAL_MS = 30000;

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

        localizeNativeSearchOptionLabels(document);

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
                    "<label class='form-check-label' for='" + checkboxId + "'>Exibir " + COLUMN_LABEL + "</label>" +
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
            if (enabled) {
                fetchColumnData($table, coreColumnIndex);
            }
            return;
        }

        if (!enabled) {
            removePluginColumn($table);
            return;
        }

        ensurePluginColumn($table);
        fetchColumnData($table, findColumnIndex($table, PLUGIN_COLUMN_ID));
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
        $header.text(COLUMN_LABEL);
        if ($sortIndicator.length) {
            $header.append(' ').append($sortIndicator);
        }
        $header.attr('title', COLUMN_TITLE);
        $header.data('tregopluginsLocalized', true);
    }

    function localizeNativeSearchOptionLabels(root) {
        var $root = $(root);

        $root.find('option').each(function () {
            var $option = $(this);
            var value = String($option.val() || '');
            if (
                value === CORE_SEARCH_OPTION_ID ||
                value.match(new RegExp('\\[' + CORE_SEARCH_OPTION_ID + '\\]$')) ||
                $option.data('searchoptId') == CORE_SEARCH_OPTION_ID
            ) {
                $option.text(COLUMN_LABEL);
            }
        });

        $root.find('[data-searchopt-id="' + CORE_SEARCH_OPTION_ID + '"]').each(function () {
            var $element = $(this);
            if ($element.is('th')) {
                return;
            }

            if ($element.children().length === 0) {
                $element.text(COLUMN_LABEL);
            }
            $element.attr('title', COLUMN_TITLE);
        });
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
            $(this).append("<th data-searchopt-id='" + PLUGIN_COLUMN_ID + "' title='" + COLUMN_TITLE + "'>" + COLUMN_LABEL + "</th>");
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

    function fetchColumnData($table, columnIndex) {
        if (columnIndex < 0) {
            return;
        }

        var ids = [];
        var rowMap = {};

        $table.find('tbody tr').each(function () {
            var $row = $(this);
            if ($row.children('[colspan]').length) {
                return;
            }

            var ticketId = extractTicketId($row);
            var $cells = $row.children();
            if ($cells.length <= columnIndex) {
                return;
            }

            var $cell = $cells.eq(columnIndex);
            if (!ticketId) {
                if ($cell.hasClass('tregoplugins-ola-progress-cell-wrapper')) {
                    $cell.html("<span class='text-muted'>-</span>");
                }
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
            cache: false,
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
        var rowId = parseInt(
            $row.attr('data-id') || $row.attr('data-item-id') || $row.data('id') || '0',
            10
        );
        if (rowId > 0) {
            return rowId;
        }

        var checkboxName = $row.find('input.massive_action_checkbox').attr('name') || '';
        var checkboxMatch = checkboxName.match(/\[(\d+)\]$/);
        if (checkboxMatch) {
            return parseInt(checkboxMatch[1], 10);
        }

        var inputTicketId = 0;
        $row.find('input[name]').each(function () {
            var name = $(this).attr('name') || '';
            var match = name.match(/\[Ticket\]\[(\d+)\]/);
            if (match) {
                inputTicketId = parseInt(match[1], 10);
                return false;
            }
        });
        if (inputTicketId > 0) {
            return inputTicketId;
        }

        var ticketId = 0;
        $row.find('a[href]').each(function () {
            var href = $(this).attr('href') || '';
            if (!href || href.indexOf('ticket.form.php') === -1) {
                return;
            }

            try {
                var url = new URL(href, window.location.origin);
                var id = parseInt(url.searchParams.get('id') || '0', 10);
                if (id > 0) {
                    ticketId = id;
                    return false;
                }
            } catch (error) {
                return;
            }
        });

        return ticketId;
    }

    function refreshEnabledTables() {
        if (!readPreference() || !isTicketListContext()) {
            return;
        }

        $('.search-results').each(function () {
            var $table = $(this);
            var coreColumnIndex = findColumnIndex($table, CORE_SEARCH_OPTION_ID);
            if (coreColumnIndex >= 0) {
                fetchColumnData($table, coreColumnIndex);
                return;
            }

            ensurePluginColumn($table);
            fetchColumnData($table, findColumnIndex($table, PLUGIN_COLUMN_ID));
        });
    }

    $(function () {
        enhanceAllTables();

        var observer = new MutationObserver(function () {
            window.clearTimeout(window.tregopluginsOlaObserverTimer);
            window.tregopluginsOlaObserverTimer = window.setTimeout(function () {
                localizeNativeSearchOptionLabels(document);
                enhanceAllTables();
            }, 60);
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        window.setInterval(refreshEnabledTables, REFRESH_INTERVAL_MS);
    });
})(jQuery);
