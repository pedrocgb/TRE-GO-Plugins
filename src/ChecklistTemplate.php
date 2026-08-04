<?php

/**
 * Admin header of a checklist template: a named, versioned, ordered set of
 * items (PluginTregopluginsChecklistTemplateItem) that gets bound to a
 * native TaskTemplate via PluginTregopluginsChecklistBinding and snapshotted
 * into a PluginTregopluginsTaskChecklist whenever a matching TicketTask is
 * created. Editing a template never touches instances already materialized
 * from it (see PluginTregopluginsChecklistInstantiator): version is bumped
 * on every relevant change so future instances can be told apart from past
 * ones, but the DB rows themselves are only ever snapshotted by copy.
 */
class PluginTregopluginsChecklistTemplate extends CommonDBTM
{
    public static $rightname = PluginTregopluginsChecklistProfile::TEMPLATE_RIGHTNAME;

    public const TABLE = 'glpi_plugin_tregoplugins_checklisttemplates';

    public const POLICY_TRACK_ONLY = 'track_only';
    public const POLICY_BLOCK_DONE = 'block_done';
    public const POLICY_AUTO_DONE  = 'auto_done';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Modelo de checklist', 'Modelos de checklist', $nb, 'tregoplugins');
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

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    /**
     * @return array<string, string>
     */
    public static function getPolicies(): array
    {
        return [
            self::POLICY_TRACK_ONLY => __('Acompanhar (não interfere na tarefa)', 'tregoplugins'),
            self::POLICY_BLOCK_DONE => __('Bloquear conclusão da tarefa enquanto houver obrigatório pendente', 'tregoplugins'),
            self::POLICY_AUTO_DONE  => __('Concluir a tarefa automaticamente ao satisfazer os obrigatórios', 'tregoplugins'),
        ];
    }

    public function prepareInputForAdd($input)
    {
        $input['entities_id']  = (int) ($input['entities_id'] ?? Session::getActiveEntity());
        $input['is_recursive'] = isset($input['is_recursive']) && (int) $input['is_recursive'] === 1 ? 1 : 0;
        $input['is_active']    = isset($input['is_active']) && (int) $input['is_active'] === 1 ? 1 : 0;
        $input['allow_manual_apply'] = isset($input['allow_manual_apply']) && (int) $input['allow_manual_apply'] === 1 ? 1 : 0;
        $input['completion_policy'] = self::normalizePolicy($input['completion_policy'] ?? self::POLICY_TRACK_ONLY);
        $input['version'] = 1;

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['is_recursive'])) {
            $input['is_recursive'] = (int) $input['is_recursive'] === 1 ? 1 : 0;
        }
        if (isset($input['is_active'])) {
            $input['is_active'] = (int) $input['is_active'] === 1 ? 1 : 0;
        }
        if (isset($input['allow_manual_apply'])) {
            $input['allow_manual_apply'] = (int) $input['allow_manual_apply'] === 1 ? 1 : 0;
        }
        if (isset($input['completion_policy'])) {
            $input['completion_policy'] = self::normalizePolicy($input['completion_policy']);
        }

        $bump_fields = ['name', 'completion_policy'];
        foreach ($bump_fields as $field) {
            if (isset($input[$field]) && (string) $input[$field] !== (string) ($this->fields[$field] ?? '')) {
                $input['version'] = ((int) ($this->fields['version'] ?? 1)) + 1;
                break;
            }
        }

        return $input;
    }

    private static function normalizePolicy(string $policy): string
    {
        return array_key_exists($policy, self::getPolicies()) ? $policy : self::POLICY_TRACK_ONLY;
    }

    /**
     * Bump the template's version outside of a form submission (called when
     * an item is added/edited/deleted/reordered, since those changes affect
     * future instances the same way an in-place field edit does).
     */
    public static function bumpVersion(int $checklisttemplates_id): void
    {
        $template = new self();
        if ($template->getFromDB($checklisttemplates_id)) {
            $template->update([
                'id'      => $checklisttemplates_id,
                'version' => ((int) $template->fields['version']) + 1,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>> active items ordered by position
     */
    public function getActiveItems(): array
    {
        if ($this->isNewItem()) {
            return [];
        }

        return PluginTregopluginsChecklistTemplateItem::getActiveItemsForTemplate((int) $this->getID());
    }

    public function hasAtLeastOneActiveItem(): bool
    {
        return count($this->getActiveItems()) > 0;
    }

    public static function install(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

            $query = "CREATE TABLE `" . self::TABLE . "` (
                `id`                 int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `entities_id`        int {$default_key_sign} NOT NULL DEFAULT 0,
                `is_recursive`       tinyint NOT NULL DEFAULT 0,
                `name`               varchar(255) NOT NULL DEFAULT '',
                `description`       text,
                `version`            int NOT NULL DEFAULT 1,
                `completion_policy`  varchar(32) NOT NULL DEFAULT 'track_only',
                `allow_manual_apply` tinyint NOT NULL DEFAULT 0,
                `is_active`          tinyint NOT NULL DEFAULT 1,
                `date_creation`      timestamp NULL DEFAULT NULL,
                `date_mod`           timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
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
