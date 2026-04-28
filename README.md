# Automação TRE-GO para Categorias ITIL

Plugin para GLPI 10.x que amplia o comportamento de categorias ITIL sem qualquer alteração no core.

## Funcionalidades

1. Vincula automaticamente um artigo da Base de Conhecimento ao ticket no momento da criação.
2. Permite ativar ou desativar esse vínculo automático por categoria ITIL.
3. Permite definir um modelo de solução para tickets do tipo **Solicitação**.
4. Permite definir um modelo de solução para tickets do tipo **Incidente**.
5. Pré-preenche o formulário **Add a solution** com o modelo configurado para a categoria e para o tipo do ticket.
6. Ao resolver ou fechar um ticket sem solução existente, reaproveita o modelo configurado usando o mecanismo nativo do GLPI.
7. Adiciona uma visualização de **Progresso da OLA (TTO)** na lista de tickets, com opção de exibir ou ocultar.

## Observação importante sobre a Base de Conhecimento

No GLPI 10.x, a categoria ITIL nativa armazena uma **categoria da Base de Conhecimento**, e não um artigo individual.

Para manter a compatibilidade com o comportamento nativo do GLPI, o plugin:

1. Usa o campo nativo **Knowledge base** da categoria ITIL.
2. Busca os artigos disponíveis dentro dessa categoria da Base de Conhecimento.
3. Vincula automaticamente o **primeiro artigo encontrado** ao ticket.

## Estrutura principal

Arquivos principais do plugin:

1. `setup.php`
2. `hook.php`
3. `front/ola_progress.php`
4. `public/tregoplugins.css`
5. `public/tregoplugins-ticket-list.js`
6. `src/CategoryConfig.php`
7. `src/CategoryForm.php`
8. `src/OlaProgressService.php`
9. `src/SolutionForm.php`
10. `src/TicketAutomation.php`

## Alterações de banco de dados

O plugin cria e mantém a tabela:

`glpi_plugin_tregoplugins_itilcategoryconfigs`

Campos usados pelo plugin:

1. `id`
2. `itilcategories_id`
3. `solutiontemplates_id_request`
4. `solutiontemplates_id_incident`
5. `auto_link_knowbase`
6. `date_creation`
7. `date_mod`

Essa tabela armazena:

1. O modelo de solução para tickets do tipo **Solicitação**.
2. O modelo de solução para tickets do tipo **Incidente**.
3. O toggle **Vincular Base de Conhecimento automaticamente**.

## Instalação

1. Copie a pasta `tregoplugins` para o diretório `/plugins` da sua instalação GLPI.
2. Garanta que os arquivos estejam acessíveis pelo servidor web.
3. No GLPI, acesse **Configurar > Plugins**.
4. Localize o plugin **Automação TRE-GO para Categorias ITIL**.
5. Clique em **Instalar**.
6. Depois clique em **Ativar**.

## Configuração

### 1. Configurar a Base de Conhecimento automática

1. Acesse **Assistência > Configuração > Categorias ITIL**.
2. Crie ou edite a categoria desejada.
3. No campo nativo **Knowledge base**, selecione a categoria da Base de Conhecimento desejada.
4. Na seção **Automações da categoria ITIL**, deixe ativo o toggle **Vincular Base de Conhecimento automaticamente**.
5. Salve.

### 2. Configurar os modelos de solução por tipo de ticket

1. Na mesma tela da categoria ITIL, localize a seção **Automações da categoria ITIL**.
2. Em **Modelo de solução para tickets do tipo Solicitação**, selecione o template desejado.
3. Em **Modelo de solução para tickets do tipo Incidente**, selecione o template desejado.
4. Salve.

### 3. Exibir a coluna de progresso da OLA na lista de tickets

1. Acesse a lista de tickets do GLPI.
2. Use o toggle **Exibir progresso da OLA (TTO)** acima da tabela.
3. Quando ativo, a lista passa a mostrar a coluna com prazo e barra de progresso.
4. Quando desativado, a coluna fica oculta.

## Como usar

### Criação do ticket

Quando um ticket é criado por formulário, API, automação ou interface padrão:

1. O plugin identifica a categoria ITIL selecionada.
2. Verifica se o vínculo automático de Base de Conhecimento está ativo para a categoria.
3. Se o campo nativo **Knowledge base** estiver configurado e houver artigo disponível, vincula o primeiro artigo encontrado ao ticket.
4. Se a categoria não estiver configurada, se o toggle estiver desligado ou se não houver artigo disponível, nenhuma ação é executada.

### Adição de solução

Ao clicar em **Add a solution**:

1. O plugin identifica a categoria ITIL e o tipo do ticket.
2. Busca o modelo compatível com **Solicitação** ou **Incidente**.
3. Pré-preenche o conteúdo e o tipo de solução antes do envio.

### Resolução ou fechamento

Quando um técnico resolve ou fecha um ticket:

1. O plugin verifica se já existe solução registrada.
2. Verifica se o técnico já digitou conteúdo manualmente.
3. Se ainda não houver solução, aplica o template correspondente ao tipo do ticket.
4. Se já houver solução ou conteúdo manual, nada é sobrescrito.

### Lista de tickets com OLA

Na lista de tickets:

1. A coluna **Progresso da OLA (TTO)** usa a OLA de **Time to Own**.
2. O cálculo respeita calendários de negócio e cenários 24x7, conforme a configuração do próprio GLPI.
3. O visual segue o padrão de barra de progresso do GLPI.
4. Se a coluna nativa equivalente do GLPI já estiver presente na busca, o plugin reaproveita essa coluna em vez de duplicar a informação.

## Regras de negócio

1. Nenhum arquivo do core do GLPI é alterado.
2. O plugin usa apenas hooks, UI própria do plugin e reaproveitamento das regras nativas do GLPI.
3. A lógica de OLA não altera SLA, OLA, calendário nem prazos do core.
4. A solução nunca sobrescreve conteúdo manual.
5. O vínculo automático da Base de Conhecimento pode ser desligado por categoria ITIL.

## Compatibilidade

Compatível com:

1. GLPI `>= 10.0.0`
2. GLPI `< 11.0.0`

## Desinstalação

Ao desinstalar o plugin pelo mecanismo padrão do GLPI:

1. A tabela `glpi_plugin_tregoplugins_itilcategoryconfigs` é removida.
2. Nenhum arquivo do core do GLPI é alterado.
