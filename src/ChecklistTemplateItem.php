<?php

/**
 * One ordered item of a PluginTregopluginsChecklistTemplate. Managed inline
 * from the template's own form (front/checklisttemplate.form.php); has no
 * tab or right of its own, it always inherits the parent template's right
 * (PluginTregopluginsChecklistProfile::canManageTemplates()).
 */
class PluginTregopluginsChecklistTemplateItem extends CommonDBTM
{
    public const TABLE = 'glpi_plugin_tregoplugins_checklisttemplateitems';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Item de checklist', 'Itens de checklist', $nb, 'tregoplugins');
    }

    public static function canView(): bool
    {
        return PluginTregopluginsChecklistProfile::canViewTemplates();
    }

    public static function canCreate(): bool
    {
        return PluginTregopluginsChecklistProfile::canManageTemplates();
    }

    public static function canUpdate(): bool
    {
        return PluginTregopluginsChecklistProfile::canManageTemplates();
    }

    public static function canPurge(): bool
    {
        return PluginTregopluginsChecklistProfile::canManageTemplates();
    }

    public function prepareInputForAdd($input)
    {
        $templates_id = (int) ($input['checklisttemplates_id'] ?? 0);
        if ($templates_id <= 0) {
            return false;
        }

        $input['is_required'] = isset($input['is_required']) && (int) $input['is_required'] === 1 ? 1 : 0;
        $input['is_active']   = 1;
        $input['position']    = self::nextPosition($templates_id);

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['is_required'])) {
            $input['is_required'] = (int) $input['is_required'] === 1 ? 1 : 0;
        }
        if (isset($input['is_active'])) {
            $input['is_active'] = (int) $input['is_active'] === 1 ? 1 : 0;
        }

        return $input;
    }

    public function post_addItem()
    {
        PluginTregopluginsChecklistTemplate::bumpVersion((int) $this->fields['checklisttemplates_id']);
    }

    public function post_updateItem($history = true)
    {
        PluginTregopluginsChecklistTemplate::bumpVersion((int) $this->fields['checklisttemplates_id']);
    }

    public function post_purgeItem()
    {
        PluginTregopluginsChecklistTemplate::bumpVersion((int) $this->fields['checklisttemplates_id']);
    }

    private static function nextPosition(int $checklisttemplates_id): int
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['MAX' => 'position AS max_position'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['checklisttemplates_id' => $checklisttemplates_id],
        ]);
        $row = $iterator->current();

        return ((int) ($row['max_position'] ?? 0)) + 1;
    }

    /**
     * @return array<int, array<string, mixed>> active items ordered by position
     */
    public static function getActiveItemsForTemplate(int $checklisttemplates_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'checklisttemplates_id' => $checklisttemplates_id,
                'is_active'             => 1,
            ],
            'ORDER'  => 'position ASC',
        ]);

        return iterator_to_array($iterator, false);
    }

    /**
     * @return array<int, array<string, mixed>> every item (active or not) ordered by position
     */
    public static function getAllItemsForTemplate(int $checklisttemplates_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['checklisttemplates_id' => $checklisttemplates_id],
            'ORDER' => 'position ASC',
        ]);

        return iterator_to_array($iterator, false);
    }

    /**
     * Swap this item's position with its immediate neighbor (previous item
     * if $direction < 0, next item if $direction > 0). No-op at either end.
     * Simple neighbor swap, not a full renumbering: cheap and avoids
     * touching every row for a single move.
     */
    public function move(int $direction): void
    {
        global $DB;

        $direction = $direction < 0 ? -1 : 1;
        $templates_id = (int) $this->fields['checklisttemplates_id'];
        $position = (int) $this->fields['position'];

        $iterator = $DB->request([
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'checklisttemplates_id' => $templates_id,
                'position'              => $direction > 0 ? ['>', $position] : ['<', $position],
            ],
            'ORDER'  => $direction > 0 ? 'position ASC' : 'position DESC',
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            return;
        }

        $neighbor = $iterator->current();

        $DB->update(self::TABLE, ['position' => $neighbor['position']], ['id' => $this->getID()]);
        $DB->update(self::TABLE, ['position' => $position], ['id' => $neighbor['id']]);

        PluginTregopluginsChecklistTemplate::bumpVersion($templates_id);
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`                     int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `checklisttemplates_id`  int {$default_key_sign} NOT NULL,
                `name`                   varchar(255) NOT NULL DEFAULT '',
                `instructions`           text,
                `position`               int NOT NULL DEFAULT 0,
                `is_required`            tinyint NOT NULL DEFAULT 0,
                `is_active`              tinyint NOT NULL DEFAULT 1,
                `date_creation`          timestamp NULL DEFAULT NULL,
                `date_mod`               timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `checklisttemplates_id_position` (`checklisttemplates_id`, `position`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . DBConnection::getDefaultCharset() . "
                COLLATE=" . DBConnection::getDefaultCollation() . " ROW_FORMAT=DYNAMIC;";

            $DB->doQueryOrDie($query, 'Create ' . self::TABLE);
        }

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->doQueryOrDie("DROP TABLE IF EXISTS `" . self::TABLE . "`", 'Drop ' . self::TABLE);
        }

        return true;
    }
}
