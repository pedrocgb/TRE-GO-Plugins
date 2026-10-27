# TRE-GO Master Plugin

Plugin para GLPI 10.x e 11.x que reúne as automações do service desk do
TRE-GO sem qualquer alteração no core: tudo é feito por hooks oficiais do
GLPI e pelo reaproveitamento das regras nativas.

## Módulos

| Módulo | Ativação | Resumo |
|---|---|---|
| Automações de Categoria ITIL | sempre ativo | Vínculo automático de artigo da Base de Conhecimento e modelos de solução por categoria/tipo de ticket |
| Correção de Visibilidade da Base de Conhecimento | toggle no Setup | Remove artigos restritos que vazam para usuários sem permissão |
| Tempo para Atribuição com OLA | toggle no Setup | Barra de progresso da OLA TTO na lista de tickets, rastreamento por unidade e "Relatório OLA" |
| Despacho de chamados | toggle no Setup | Força uma unidade padrão na criação e oferece o botão "Despachar chamado para Unidade Responsável" |
| Modelo de Checklist | toggle no Setup | Checklists marcáveis dentro das tarefas dos chamados, vinculados a modelos de tarefa |

Módulos com toggle seguem a regra "desativado = não executado": enquanto
desligados, não registram hooks, abas, menus nem assets.

Todos os toggles ficam em **Configurar > TRE-GO Plugins**. Todos os direitos
ficam em uma única aba **TRE-GO** em **Administração > Perfis > (perfil)**.

## 1. Automações de Categoria ITIL

1. Vincula automaticamente um artigo da Base de Conhecimento ao ticket no momento da criação.
2. Permite ativar ou desativar esse vínculo automático por categoria ITIL.
3. Permite definir um modelo de solução para tickets do tipo **Solicitação** e outro para **Incidente**.
4. Pré-preenche o formulário **Add a solution** com o modelo da categoria e do tipo do ticket.
5. Ao resolver ou fechar um ticket sem solução existente, aplica o modelo usando o mecanismo nativo do GLPI.
6. Nunca sobrescreve solução existente nem conteúdo digitado manualmente.

### Observação sobre a Base de Conhecimento

A categoria ITIL nativa armazena uma **categoria da Base de Conhecimento**, e
não um artigo individual. Por isso o plugin usa o campo nativo
**Knowledge base** da categoria ITIL, busca os artigos dessa categoria e
vincula o **primeiro artigo encontrado** ao ticket.

### Configuração

1. Acesse **Assistência > Configuração > Categorias ITIL** e edite a categoria.
2. No campo nativo **Knowledge base**, selecione a categoria da Base de Conhecimento.
3. Na seção **Automações da categoria ITIL**:
   - deixe ativo **Vincular Base de Conhecimento automaticamente**;
   - escolha o **Modelo de solução para tickets do tipo Solicitação**;
   - escolha o **Modelo de solução para tickets do tipo Incidente**.
4. Salve.

Se a categoria não estiver configurada, o toggle estiver desligado ou não
houver artigo disponível, nenhuma ação é executada.

## 2. Correção de Visibilidade da Base de Conhecimento

O GLPI tem um bug em `KnowbaseItem::getVisibilityCriteriaKB()`: um artigo
restrito apenas por Grupo/Perfil/Usuário (sem alvo de Entidade explícito)
fica visível para qualquer usuário com direito de leitura na Base de
Conhecimento. O core não expõe hook dentro dos métodos afetados.

Este módulo intercepta a saída HTML das telas que listam artigos
(`front/knowbaseitem.php`, `front/helpdesk.faq.php`,
`front/helpdesk.public.php`, `front/report.dynamic.php`,
`ajax/knowbase.php`), reavalia cada artigo com
`KnowbaseItem::canViewItem()` (que não tem o bug) e remove as linhas que o
usuário não deveria ver.

Limitação conhecida: contadores de lista/paginação calculados pelo core
antes do filtro (ex.: "10 resultados") não são ajustados.

Direito: `plugin_tregoplugins_kbvisibility` (**Read** vê o toggle,
**Update** altera).

## 3. Tempo para Atribuição com OLA (TTO)

1. **Lista de tickets:** coluna **Progresso da OLA (TTO)** com prazo e barra
   de progresso, exibida ou ocultada por um toggle acima da tabela. Se a
   coluna nativa equivalente já estiver na busca, ela é reaproveitada.
2. **Rótulos:** "Tempo Excedido" quando o prazo estoura (inclusive durante
   pausa de calendário) e "Chamado finalizado" em tickets solucionados ou
   fechados, cujo relógio fica congelado.
3. **Rastreamento por unidade:** cada passagem do ticket por um grupo
   técnico é registrada (início, prazo, atribuição, técnico, unidade de
   origem do escalonamento). O ciclo da OLA TTO reinicia em despacho ou
   escalonamento para outra unidade, não em uma atribuição simples.
4. **Calendário:** o cálculo usa o calendário da própria OLA, respeita
   cenários 24x7 e `end_of_working_day`, e aceita um calendário por unidade.
5. **Status:** um ticket criado só com grupo técnico (sem técnico) permanece
   **Novo**, e a data de "levado em conta" só é gravada quando um técnico é
   de fato atribuído.
6. **Relatório OLA** (**Gestão > Relatório OLA**): filtro por unidade e
   período, calendário por unidade e exportação CSV/XLSX. Colunas: ID,
   Título, Categoria, Status, Data de Abertura, Solicitante, Técnico
   Responsável, Escalonado Por, Data Escalonamento, Tempo Máximo para
   Atribuição, Horário da Atribuição, Excedeu Tempo de Atribuição, Tempo até
   o atendimento.

A lógica do plugin não altera SLA, OLA, calendário nem prazos do core.

Direitos: `plugin_tregoplugins_olaconfig` (configuração do módulo) e
`plugin_tregoplugins_olareport` (acesso ao relatório).

## 4. Despacho de chamados

1. **Unidade padrão:** todo chamado criado (interface, e-mail, API) é movido
   para a unidade (grupo técnico) configurada, depois que as regras de
   negócio normais rodam. Opcionalmente define o status inicial e uma OLA
   TTO própria enquanto o chamado está parado nessa unidade.
2. **Botão "Despachar chamado para Unidade Responsável"** na timeline
   (ao lado de Responder): reexecuta as regras de negócio de criação
   (`RuleTicket` ONADD) em modo simulação e aplica apenas as mutações
   suportadas, principalmente a unidade responsável, em um único update.
3. A elegibilidade é calculada no servidor; o botão só reflete esse
   resultado e fica oculto quando não há nada a despachar.
4. Cada despacho é registrado em log de auditoria.

O módulo só executa se a unidade padrão configurada estiver ativa e
atribuível.

Direitos: `plugin_tregoplugins_ticketdispatch` (configuração do módulo) e
`plugin_tregoplugins_ticketdispatch_action` (ver o botão de despacho).

## 5. Modelo de Checklist

1. **Modelos de checklist** (**Gestão > Modelos de checklist**) com itens
   obrigatórios ou opcionais.
2. **Vínculos:** aba **Modelo de Checklist** nos modelos de tarefa nativos.
   Um vínculo pode ser condicionado por entidade, tipo de ticket, categoria
   ITIL e prioridade; apenas um vínculo vence por tarefa.
3. **Materialização:** ao criar uma tarefa a partir do modelo de tarefa, o
   checklist é copiado para a tarefa e exibido dentro do card na timeline.
4. **Políticas de conclusão:**
   - **Acompanhar:** só registra o progresso, sem interferir na tarefa.
   - **Bloquear conclusão:** itens obrigatórios pendentes impedem marcar a
     tarefa como feita e solucionar o chamado.
   - **Concluir automaticamente:** a tarefa é concluída quando todos os
     itens obrigatórios são marcados.
5. Toda marcação gera um evento em histórico próprio (somente inclusão).

Direitos: `plugin_tregoplugins_checklisttemplate` (gerenciar modelos e
vínculos) e `plugin_tregoplugins_taskchecklist` (marcar itens nas tarefas).

## Banco de dados

| Tabela | Módulo |
|---|---|
| `glpi_plugin_tregoplugins_itilcategoryconfigs` | Automações de Categoria ITIL |
| `glpi_plugin_tregoplugins_kbvisibilityconfigs` | Visibilidade da Base de Conhecimento |
| `glpi_plugin_tregoplugins_olaconfigs` | OLA TTO |
| `glpi_plugin_tregoplugins_ola_group_passes` | OLA TTO (passagens por unidade) |
| `glpi_plugin_tregoplugins_group_calendars` | OLA TTO (calendário por unidade) |
| `glpi_plugin_tregoplugins_ticketdispatchconfigs` | Despacho de chamados |
| `glpi_plugin_tregoplugins_ticketdispatchlogs` | Despacho de chamados (auditoria) |
| `glpi_plugin_tregoplugins_checklistconfigs` | Modelo de Checklist |
| `glpi_plugin_tregoplugins_checklisttemplates` | Modelo de Checklist |
| `glpi_plugin_tregoplugins_checklisttemplateitems` | Modelo de Checklist |
| `glpi_plugin_tregoplugins_tasktemplatebindings` | Modelo de Checklist (vínculos) |
| `glpi_plugin_tregoplugins_taskchecklists` | Modelo de Checklist (instâncias) |
| `glpi_plugin_tregoplugins_taskchecklistitems` | Modelo de Checklist (itens) |
| `glpi_plugin_tregoplugins_checklistevents` | Modelo de Checklist (histórico) |

## Estrutura

- `setup.php`: registro de hooks, menus, assets, instalação e desinstalação.
- `src/`: uma classe por responsabilidade, agrupadas por módulo
  (`Category*`, `KbVisibility*`, `Ola*`, `TicketDispatch*`, `Checklist*`/`TaskChecklist*`).
- `front/`: páginas do Setup, Relatório OLA, formulários de checklist e endpoint de despacho.
- `ajax/checklist.item.php`: marcação de itens de checklist.
- `public/`: CSS/JS de cada módulo e ícones.

## Instalação

1. Copie a pasta `tregoplugins` para o diretório `/plugins` do GLPI.
2. Garanta que os arquivos estejam acessíveis pelo servidor web.
3. Em **Configurar > Plugins**, localize **TRE-GO Master Plugin**.
4. Clique em **Instalar** e depois em **Ativar**.
5. Em **Configurar > TRE-GO Plugins**, ative os módulos desejados.
6. Em **Administração > Perfis > (perfil) > TRE-GO**, conceda os direitos.

## Compatibilidade

GLPI `>= 10.0.0` e `< 11.9.99`.

## Desinstalação

Ao desinstalar pelo mecanismo padrão do GLPI, todas as tabelas listadas em
**Banco de dados** e todos os direitos `plugin_tregoplugins_*` são
removidos. Nenhum arquivo do core do GLPI é alterado.

## Licença

MIT. Autor: Pedro Henrique Cesar.
