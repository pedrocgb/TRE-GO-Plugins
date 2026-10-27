# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Security

- Stored XSS through a solution template name in the ITIL category solution form.
- Checklist template/item updates no longer accept `entities_id` outside the user's entities, `version`, or a new parent template from the posted form.
- Checklist bindings require access to the target entity, checklist template and task template.
- OLA Report and its exports only list tickets from the user's active entities.
- Saving a group calendar on the OLA Report needs the OLA module UPDATE right, and the group must be an existing assignable group.
- CSV export neutralizes cells that would run as spreadsheet formulas.

### Fixed

- Checklist binding tie-break picked the highest id instead of the documented lowest id.
- Knowledge base visibility guard garbled accented characters when it removed a row from a page or fragment.
- An assigned group demoted to another actor type on the same ticket left its OLA pass open.
- Legacy solution-template migration used `Glpi\DBAL\QueryExpression`, which does not exist on GLPI 10.
- OLA assignment date derived from `takeintoaccount_delay_stat` added business seconds as wall-clock time.

## [2.5.4] - 2026-08-21

### Fixed

- OLA TTO clock never restarted when a ticket was handed off to another group without using dispatch.

## [2.5.3] - 2026-08-20

### Fixed

- Knowledge base visibility guard crashed on pages where GLPI had already closed the database connection.

## [2.5.2] - 2026-08-20

### Fixed

- OLA TTO progress bar now reads the plugin's own tracked timer instead of native ticket fields.
- OLA TTO progress bar stuck at 0% when the group was attached by a later update.
- OLA TTO progress bar reset on technician self-assign, without affecting the escalation restart.

## [2.5.1] - 2026-08-19

### Added

- Module toggle for OLA TTO tracking and the OLA Report.

### Fixed

- OLA Report page blank because of an unresolved `QueryExpression` namespace.
- OLA config toggle unusable: missing UPDATE right, and a missing table crashed GLPI 11.

## [2.4.1] - 2026-08-04

### Fixed

- Mandatory checklist items now block solving the ticket; clicking the task state always toggles the checklist.
- OLA TTO clock frozen and "Chamado finalizado" label forced on solved/closed tickets.
- Only dispatch/escalation restarts the OLA TTO cycle, not a plain assignment.
- `tickets_id` columns widened to `bigint unsigned`, with self-healing on existing installs.
- OLA TTO progress bar reset to 0% on the ticket-creation group assignment.
- Checklist rights granted to manager profiles by default.
- Icon `<img>` no longer embedded in the TRE-GO profile tab title.
- Missing CSRF token on checklist item/binding mini-forms.
- Checklist checkboxes stripped by the timeline content purifier.
- Checklist fragment placed inside the task card; more robust master-toggle bulk action.

## [2.4.0] - 2026-08-04

### Added

- Task checklist module: checklist templates bound to task templates, materialized into ticket tasks, with track-only, block-done and auto-done completion policies.

### Fixed

- Group-only tickets no longer seed or count as an OLA TTO assignment.
- New tickets stay in "New" status on group-only assignment.

## [2.3.1] - 2026-08-03

### Fixed

- OLA TTO calendar resolved from the OLA itself, not the ticket's SLA.

## [2.3.0] - 2026-08-03

### Added

- "Tempo Excedido" label when the OLA TTO is exceeded, including during a calendar pause.
- Default dispatch group's own OLA TTO applies while a ticket is parked there.

### Fixed

- Dispatch no longer bumps ticket status to "Assigned" automatically.
- Dispatch default-group dropdown reset its selection and ignored the entity-restriction bypass.

## [2.1.0] - 2026-07-29

### Added

- Knowledge base visibility guard module, fixing GLPI's group/profile/user restriction leak.
- Ticket dispatch module: default group on creation and manual rule-replay dispatch button.
- Configurable initial status for dispatched tickets.
- Single "TRE-GO Plugins" Setup page and single TRE-GO profile tab for all module rights.
- OLA calendar-pause label and support for `end_of_working_day`.

### Changed

- Plugin renamed to "TRE-GO Master Plugin".

### Fixed

- Duplicate TRE-GO profile tabs.
- Double CSRF check breaking Setup saves.

## [2.0.9] - 2026-07-28

### Fixed

- GLPI 11 compatibility: version gate, raw SQL, removal of dead skeleton code.
- Solution draft prefill showed the template HTML as literal text.

## [0.1.0] - 2025-09-30

### Added

- GLPI 11 compatibility
