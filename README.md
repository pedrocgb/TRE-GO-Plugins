# TRE-GO ITIL Category Automation

Plugin para GLPI 10.x que amplia o comportamento das categorias ITIL sem qualquer modificação no core.

## O que o plugin faz

1. Ao criar um ticket com uma categoria ITIL configurada com base de conhecimento, o plugin vincula automaticamente um artigo na aba **Knowledge Base** do ticket.
2. Ao abrir o formulário **Add a solution**, o plugin já pré-carrega o template de solução conforme a categoria ITIL e o tipo do ticket.
3. Ao resolver ou fechar um ticket cuja categoria ITIL possua um modelo de solução configurado, o plugin preenche automaticamente a solução usando o template nativo do GLPI.
4. O preenchimento automático da solução só acontece quando ainda não existe solução e quando o técnico não informou conteúdo manualmente.

## Observação importante sobre a Base de Conhecimento

No GLPI 10.x, a categoria ITIL nativa armazena uma **categoria da base de conhecimento**, não um artigo individual.

Por isso, para manter o comportamento sem alterar o core, o plugin:

1. Usa a configuração nativa do campo **Knowledge base** da categoria ITIL.
2. Procura os artigos vinculados àquela categoria da base.
3. Vincula automaticamente o **primeiro artigo encontrado**, seguindo a mesma lógica do atalho nativo do GLPI, que usa o primeiro artigo disponível como seleção inicial.

## Estrutura criada

O plugin usa os seguintes arquivos principais:

1. `setup.php`
2. `hook.php`
3. `src/CategoryConfig.php`
4. `src/CategoryForm.php`
5. `src/SolutionForm.php`
6. `src/TicketAutomation.php`

## Alterações de banco de dados

O plugin cria a tabela:

`glpi_plugin_tregoplugins_itilcategoryconfigs`

Campos:

1. `id`
2. `itilcategories_id`
3. `solutiontemplates_id_request`
4. `solutiontemplates_id_incident`
5. `date_creation`
6. `date_mod`

Essa tabela guarda os modelos de solução por categoria ITIL, separados entre tickets do tipo **Request** e **Incident**.

## Instalação

1. Copie a pasta `tregoplugins` para o diretório `/plugins` da sua instalação GLPI.
2. Garanta que os arquivos estejam acessíveis pelo servidor web.
3. No GLPI, acesse **Configurar > Plugins**.
4. Localize o plugin **TRE-GO ITIL Category Automation**.
5. Clique em **Instalar**.
6. Depois clique em **Ativar**.

## Configuração

### 1. Configurar vínculo automático de Base de Conhecimento

1. Acesse o cadastro de **Categorias ITIL** no GLPI.
2. Edite a categoria desejada.
3. No campo nativo **Knowledge base**, selecione a categoria da base de conhecimento desejada.
4. Salve.

### 2. Configurar modelos de solução por categoria

1. Ainda na mesma tela da categoria ITIL, localize a seção adicionada pelo plugin.
2. No campo de **Request**, selecione o template desejado para tickets de solicitação.
3. No campo de **Incident**, selecione o template desejado para tickets de incidente.
4. Salve.

## Como usar

### Ticket criado com categoria ITIL

Quando um ticket for criado por formulário, API, automação ou interface padrão:

1. O plugin identifica a categoria ITIL do ticket.
2. Verifica se essa categoria possui uma categoria de base de conhecimento configurada.
3. Se existir pelo menos um artigo nessa categoria da base, o primeiro artigo encontrado é vinculado automaticamente ao ticket.
4. Se não houver configuração ou artigo disponível, nada é feito.

### Formulário de solução

Ao clicar em **Add a solution**:

1. O plugin identifica a categoria ITIL e o tipo do ticket.
2. Busca o template compatível com **Request** ou **Incident**.
3. Pré-preenche o conteúdo e o tipo de solução antes do envio.

### Ticket resolvido ou fechado

Quando um técnico resolver ou fechar um ticket:

1. O plugin verifica a categoria ITIL do ticket.
2. Busca o modelo de solução configurado para essa categoria e para o tipo do ticket.
3. Se houver template e ainda não existir solução preenchida, o plugin cria a solução usando o mecanismo nativo do GLPI.
4. Se já houver solução ou se o técnico tiver digitado conteúdo manualmente, o plugin não sobrescreve nada.

## Regras de negócio implementadas

1. Não altera arquivos do core do GLPI.
2. Usa apenas hooks do sistema de plugins do GLPI.
3. Mantém a solução no formato nativo do GLPI, criando `ITILSolution`.
4. Não sobrescreve solução manual.
5. Não faz nada quando a categoria não estiver configurada para o tipo correspondente.

## Compatibilidade

Compatível com:

1. GLPI `>= 10.0.0`
2. GLPI `< 11.0.0`

## Desinstalação

Ao desinstalar o plugin pelo mecanismo padrão do GLPI:

1. A tabela `glpi_plugin_tregoplugins_itilcategoryconfigs` é removida.
2. Nenhum arquivo do core é alterado.
