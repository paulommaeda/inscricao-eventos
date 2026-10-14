# Inscrição de Eventos — WordPress + K8

Plugin reutilizável para inscrições com lotes, identidade visual própria, cartão no checkout K8 e entrada/complementos por PIX. Código e atualizações em [paulommaeda/inscricao-eventos](https://github.com/paulommaeda/inscricao-eventos), branch `main`.

## Instalação

1. Instale o ZIP em **Plugins → Adicionar plugin → Enviar plugin** e ative.
2. Acesse **Inscrições → Configurações K8**. Cadastre **Client ID, Client Secret e chave AES da API K8 Pay (PagamentosAPI)**. O ambiente inicial é sandbox.
3. Clique em **Testar autenticação K8**. Homologue uma inscrição, o checkout e a confirmação no sandbox antes de usar produção.
4. Em **Eventos e lotes**, edite o modelo do retiro. Preencha a data exata do evento, confira o prazo de quitação e o valor do segundo lote. Publique o evento quando estiver configurado.
5. A ativação cria a página **Inscrições do retiro** e associa o retorno do K8 a ela. Para outros eventos, crie uma página e insira seu shortcode; informe o ID dessa página no cadastro do evento.
6. Para receber dinheiro real, selecione **Produção**, cadastre as credenciais desse ambiente e use HTTPS no site.

Requisitos: WordPress 6.4+, PHP 8.1+, OpenSSL e MySQL/MariaDB com InnoDB. Não depende de WooCommerce nem dos plugins K8 anteriores.

## Modelo inicial do retiro

O modelo nasce como **rascunho**, pois a data exata do evento não foi informada. O título conserva “Carnaval 2028”, e as datas de inscrição conservam o pedido e a imagem. A imagem menciona encerramento uma semana antes de um evento em 2027; confira essa diferença ao publicar.

| Lote | Período inclusive | Valor | Cartão |
| --- | --- | --- | --- |
| Lote 1 | 17/10/2026 a 15/11/2026 | R$ 490,00 | até 10x |
| Lote 2 | 16/11/2026 a 31/12/2026 | R$ 520,00, a confirmar | até 8x |
| Lote 3 | 01/01/2027 a 30/01/2027 | R$ 550,00 | até 6x |

O cartão usa as modalidades à vista e parcelado sem juros do checkout K8. O evento pode aceitar apenas cartão, apenas PIX ou ambos. A entrada mínima do modelo é R$ 1,00, editável; não foi presumida uma entrada obrigatória maior.

## Fluxo do participante

- **Inscrição:** nome, e-mail, telefone com DDD e CPF válido; escolha da forma de pagamento e aceite das condições.
- **Cartão:** checkout K8 pelo valor completo, com o limite de parcelas guardado na inscrição. A aprovação confirma a inscrição inteira; as parcelas seguintes pertencem à relação com o emissor do cartão.
- **PIX:** o participante escolhe a entrada, paga no checkout K8 e acompanha o saldo após a confirmação.
- **Complementos:** em “Minha inscrição”, informa o CPF, consulta o saldo, escolhe quanto pagar naquele momento e recebe um novo checkout exclusivamente PIX.
- O lote, seu preço e o limite de parcelas ficam guardados ao criar a inscrição. Mudanças posteriores não reajustam essa inscrição. Complementos continuam disponíveis depois do encerramento das inscrições, até o prazo de quitação.
- Há uma inscrição por CPF, evento e conta/ambiente K8. O participante consulta um evento por vez.
- A mensagem de quitação aparece no cadastro, no saldo e no rodapé. Use `{data}` para incluir a data limite.

## Shortcodes e marca

```text
[inscricao_eventos evento="1"]
[inscricao_evento id="1"]
[inscricao_eventos]
```

Sem ID, a página permite escolher um evento publicado. Cada evento tem nome, descrição, local, logo, imagem de capa, três cores, política de privacidade e condições de inscrição. O formulário se adapta ao celular e não exige conta WordPress. O checkout mantém a identificação do prestador de pagamento.

Datas incluem todo o dia no fuso configurado no evento, inicialmente `America/Sao_Paulo`. Arquivar impede novas inscrições/pagamentos e mantém o histórico. A exclusão permanente do evento remove também seus lotes e todas as inscrições/registros locais vinculados, de todas as contas e ambientes, mediante confirmação digitada. A página WordPress permanece e as transações externas no K8 não são canceladas ou estornadas por essa exclusão.

## Acompanhamento e conciliação

O painel **Inscrições → Acompanhamento** mostra inscritos, valor total, confirmado, saldo e excedentes. Inclui filtros por evento, situação, método e nome/e-mail/CPF, paginação, detalhe da inscrição e exportação CSV.

Desde a versão 1.1.0, o painel oferece **Editar inscrição**, **Excluir inscrição**, **Restaurar** e ações K8 em cada tentativa. O filtro de inscrições ativas é inicial; escolha **Canceladas**, **Lixeira** ou **Todas as inscrições** para incluir os demais registros nos totais e na exportação.

### Editar e excluir inscrições

- A edição permite corrigir nome, e-mail, telefone, CPF, observações internas, situação da inscrição e forma de futuros pagamentos. A troca de método exige concluir as tentativas em andamento e usar um método habilitado no evento.
- O preço, o lote e as parcelas contratados ficam guardados na inscrição. A edição cadastral preserva o CPF original de cada tentativa já emitida, inclusive nas versões anteriores; pagamentos futuros usam o CPF corrigido. Sessões públicas antigas são encerradas ao editar.
- A situação **Cancelada** bloqueia novos pagamentos da inscrição no site. As transações K8 continuam sendo conciliadas.
- **Excluir inscrição** move para a lixeira, bloqueia novos pagamentos e conserva tentativas/créditos. A inscrição pode ser restaurada. Solicite cancelamento/estorno dos pagamentos pela ação K8 quando necessário; mover para lixeira não envia essa solicitação.
- **Excluir inscrição permanentemente** fica nos detalhes da inscrição, inclusive quando há pagamentos. Digite `EXCLUIR ID` (exemplo: `EXCLUIR 24`) e confirme. Remove o cadastro, tentativas, créditos locais, sessões e histórico administrativo em uma transação; a lixeira continua disponível para exclusão reversível. Não envia cancelamento/estorno ao K8.
- **Excluir evento permanentemente** fica no final da edição do evento. Digite `EXCLUIR EVENTO ID` (exemplo: `EXCLUIR EVENTO 1`) e confirme. Remove o evento, lotes, todas as inscrições e seus registros vinculados, incluindo canceladas, lixeira e outras contas/ambientes. Preserva a página WordPress.
- Exclusões exigem administrador, POST, nonce e confirmação do ID no servidor. Bloqueios serializam a remoção com criação de inscrições, edição, conciliação e estornos; uma falha de banco desfaz toda a operação. IDs não são reutilizados, e notificações de tentativas removidas são recusadas, sem recriar cadastro/crédito.
- Edição, lixeira, restauração e ações K8 registram usuário WordPress, data UTC e observações no histórico administrativo. A exclusão permanente remove também esse histórico.

### Dashboard do evento por shortcode

Crie uma página para a organização e adicione:

```text
[inscricao_eventos_dashboard evento="1"]
```

Troque `1` pelo ID do evento. Também aceita `[dashboard_evento id="1"]`. Sem ID, `[inscricao_eventos_dashboard]` permite escolher um evento ou acompanhar todos. A lista de eventos mostra o shortcode de cada um.

O acesso exige uma conta WordPress com `manage_options` (administrador). Visitantes recebem apenas uma indicação de acesso restrito; a API também verifica permissão, usa o nonce REST autenticado e não permite cache dos dados financeiros. Páginas com esse shortcode sinalizam `DONOTCACHEPAGE`. Se o cache externo ignorar esse sinal, exclua a página do cache para manter login e nonce atualizados.

O painel mostra:

- Inscrições, valor contratado, recebido/confirmado, a receber, percentual de quitação, quitadas, parciais e sem pagamento.
- Dias até o evento, prazo para quitar, situação das inscrições e lote vigente, usando o fuso configurado. Data não configurada aparece como **A definir**; datas passadas são identificadas.
- Financeiro por lote (nome congelado na inscrição), formas de pagamento, evolução dos cadastros e alertas de conferência, erro, cancelamento/estorno em andamento, excedentes e saldo após o prazo.
- Filtros por evento, lote, pagamento, método, situação da inscrição, nome/e-mail/CPF completo e período de cadastro. O período inclui os dias completos no fuso do evento; na visão de todos os eventos usa UTC. O gráfico de cadastros usa UTC e os últimos 30 dias com registros.
- Lista com contatos, valores, saldo, paginação de 25 registros e acesso aos detalhes/edição no WordPress. CSV aplica exatamente os mesmos filtros, incluindo lote, período e contas/ambientes.

Os totais e gráficos correspondem às inscrições filtradas, não apenas à página da tabela. O padrão usa inscrições ativas e a conta/ambiente K8 atual. Cartão aprovado é reconhecido integralmente como confirmado; não é uma demonstração de liquidação bancária ou de taxas do K8. Pagamentos excedentes permanecem destacados e não compensam a dívida de outras pessoas. As datas filtram o cadastro; o financeiro inclui todo o histórico conciliado dessas inscrições. **Atualizar** lê o saldo local conferido, sem emitir cobranças ou solicitar estornos; conciliação permanece nas ações de detalhe, cron e retornos.

### Cancelamento e estorno no K8 Pay

Abra o detalhe da inscrição e a seção **Cancelar transação / estornar pagamento no K8**. Confira o **ID da transação** desejada, selecione a operação, registre o motivo e marque a confirmação. A ação exige administrador, nonce e envio POST. O plugin consulta o K8 e verifica conta/ambiente, ID, CPF original, pedido/referência/checkout, método e valor antes de solicitar qualquer alteração.

O cancelamento usa `/api/CancelaTransacao`. Para PIX já aprovado, escolha **Estornar pagamento aprovado**, que utiliza `/api/EstornaTransacaoPIx`; para cartão, o estorno utiliza o cancelamento da transação. A disponibilidade depende do estado e das permissões do K8. O formulário identifica uma transação por vez; o identificador do checkout não substitui o da transação.

A resposta `Retorno=OK`, inclusive com `Posterior=false`, registra a solicitação sem reduzir o crédito automaticamente. O plugin consulta novamente e aguarda um estado final como `Estornado` ou `Cancelada` para atualizar o saldo. Enquanto houver cancelamento em andamento, uma nova cobrança fica bloqueada. Cron, retorno K8 e botão de conciliação acompanham o resultado; não reenviam o pedido de cancelamento/estorno.

Se o envio tiver timeout, o pedido fica em conferência e outro clique não o repete. Após conferir no painel K8 a inexistência de solicitação em andamento, o administrador pode registrar essa conferência; uma nova consulta ainda precisa confirmar que o K8 não informa processamento de cancelamento/estorno. Uma rejeição explícita do K8 é mostrada com o motivo, sem declarar a transação cancelada.

As rotas estão documentadas em [cancelamento de transação](https://docs.meuk8.com.br/reference/cancelatransacao_cancelatransacao_cancelatransacao_post) e [estorno PIX](https://docs.meuk8.com.br/reference/estornatransacaopix_estornatransacaopix_estornatransacaopix_post). Essas ações só são enviadas ao clicar e confirmar no WordPress.

### Diagnóstico do retorno

Depois de **Consultar e conciliar no K8**, abra **Diagnóstico da última consulta K8**. A tabela compara os campos locais e os devolvidos pela API: ID, referência, pedido, checkout, CPF original da cobrança, método, valor e estado. Divergências são identificadas sem mostrar CPF completo nem credenciais. O diagnóstico é administrativo e não aparece na consulta pública.

Esse registro permite identificar a resposta real da conta K8 quando a conciliação continua falhando. Os testes automatizados não confirmam o pagamento real nem substituem a conferência dessa tabela no WordPress.

O endereço do webhook é enviado automaticamente em cada checkout. Cada endereço tem um token próprio. O estado recebido no webhook e o retorno do navegador não fazem baixa: o servidor consulta a API K8 e confere ID da transação, CPF, método, valor e vínculo com a cobrança (referência, número do pedido ou checkout). Pelo menos um vínculo precisa corresponder, e nenhum vínculo informado pode contradizer a cobrança local.

Desde a versão 1.0.2, o retorno lê `ID_BknBank` na URL, inclusive em POST sem corpo, além de `Identificador` no JSON, IDs em formulário e GET de recuperação. O ID do pagamento é obtido exclusivamente do caminho da URL, e o token exclusivamente da query string. Checkouts antigos conservam o endereço e o token. Após consulta e conferência, a resposta é HTTP 200 com `{"Retorno":"OK","ok":true}`. Falhas de conciliação devolvem HTTP 503 para nova tentativa; token inválido devolve HTTP 403.

O ID recebido é uma pista para consultar a API, sem criar crédito diretamente. Ele fica guardado mesmo se a consulta falhar, para nova conciliação pelo cron, pela consulta do participante ou pelo administrador. Transações já conferidas também são consultadas por seu ID, permitindo acompanhar estornos. O detalhe administrativo mostra a data do último retorno e eventuais erros. As colunas adicionais são criadas automaticamente na primeira carga da versão atualizada, antes de atender um webhook.

A versão 1.0.3 normaliza GUIDs K8 de 32 caracteres ou com hífens, incluindo diferenças de maiúsculas/minúsculas, tanto para a transação quanto para o checkout. O ID financeiro é guardado em formato canônico. Registros antigos em outro formato do mesmo GUID são reutilizados e normalizados na transação de banco. Se houver dois registros históricos do mesmo GUID em formatos diferentes, a conciliação é interrompida para conferência, sem apagar registros ou inventar uma correção de saldo.

Se a consulta por referência devolver especificamente **referência da transação não localizada**, o plugin consulta `/api/ConsultaTransacoes` no período desde a criação da tentativa. Seleciona apenas correspondências exatas de pedido, referência ou checkout e consulta cada ID novamente em `/api/ConsultaTransacao`. A lista nunca confirma crédito diretamente, e CPF/valor/método seguem obrigatórios. CPF e valor iguais, sozinhos, não identificam um pagamento. Se a API não devolver uma correspondência ou o resultado estiver indisponível/incompleto, a tentativa continua pendente ou em conferência, e o administrador pode informar o ID completo da transação. Essa consulta não cria checkout nem libera outra cobrança.

As mensagens indicam qual campo falhou: ID, pedido, referência, checkout, CPF, valor ou método. O CPF completo e a resposta bruta da API não são incluídos no diagnóstico. Uma conciliação bem-sucedida também limpa o erro antigo do retorno para que o painel não continue mostrando uma falha já resolvida.

O histórico usa identificadores únicos de transação e operações com bloqueio/transação de banco. Repetições de callback não duplicam crédito. Estornos confirmados restauram o saldo. Excedentes são registrados e mostrados para conferência, sem descartar pagamentos.

WP-Cron consulta as tentativas a cada cinco minutos, em grupos limitados. A consulta por CPF, o retorno e o botão “Atualizar saldo” também solicitam conciliação. Em sites com pouco tráfego, programe `wp-cron.php` no cron do servidor. O K8 pode confirmar alguns instantes depois do retorno ao site.

Se a criação do checkout sofrer timeout, a tentativa fica **em conferência**. O sistema bloqueia outra cobrança até conferir a anterior. No detalhe administrativo, é possível consultar pela referência/ID, vincular um checkout existente ou liberar uma tentativa depois de conferir no K8 e desativar links antigos. Essas ações administrativas não fazem baixa financeira. Uma transação tardia de tentativa antiga continua sendo conciliada.

## Dados e acesso

A consulta por CPF é o acesso solicitado ao resumo financeiro; CPF é um identificador, e quem o conhece pode consultar esse resumo. Por isso a página pública mostra apenas primeiro nome, lote, total, saldo e pagamentos, omitindo e-mail, telefone e CPF completo. Há limites de tentativas, sessão com cookie HttpOnly/SameSite e proteção CSRF. O cadastro existente não pode ser alterado por essa consulta.

As configurações, CPFs e tokens de API são protegidos por criptografia vinculada às chaves do WordPress. Preserve essas chaves nos backups e nas migrações. As tabelas e inscrições permanecem ao desativar ou remover o plugin. Faça backups do banco para conservar os registros financeiros.

Sandbox e produção são separados por conta/ambiente; mudar as credenciais não atribui pagamentos antigos à conta nova. O painel usa a configuração atual por padrão, com opção para visualizar outros ambientes. Se precisar conciliar uma tentativa antiga, volte às credenciais e ao ambiente correspondentes.

## Atualizações pelo WordPress

A biblioteca **Plugin Update Checker 5.7**, distribuída com sua licença MIT, verifica a branch `main` do repositório público. Não precisa de token GitHub. A instalação inicial é manual; as próximas versões entram no mecanismo de atualização do WordPress. A opção **Instalar automaticamente novas versões do GitHub** está habilitada inicialmente e pode ser desativada nas configurações.

Para novas entregas, aumente a versão no cabeçalho de `inscricao-eventos.php`, em `IE_VERSION` e em `readme.txt`; valide, publique os arquivos em `main` e gere o ZIP. O ZIP contém uma única pasta raiz `inscricao-eventos/`.

## Desenvolvimento e validação

```sh
php tests/financial.php
php tests/authentication.php
php tests/webhook.php
php tests/management.php
php tests/dashboard.php
npm install
npx playwright install chromium
npm test
python3 scripts/build.py
```

Os testes financeiros usam dublês de WordPress/banco/K8 para verificar valores, CPF, limites de lotes, aprovação, estorno, duplicidade, rollback, proteção de origem e separação de ambientes. Os testes de webhook cobrem POST/GET, `ID_BknBank`, JSON/formulário, colisão de parâmetros, tokens, falhas temporárias, correlação e repetição sem duplicar crédito. Incluem GUIDs equivalentes, registros financeiros antigos, divergências por campo, busca exata de pedido após referência não localizada e rejeição de resultados não relacionados. Os testes de gestão verificam edição de CPF sem alterar cobranças antigas, lixeira, autorização, nonces, cancelamento/estorno, timeout e confirmação posterior. Os testes de dashboard executam os SELECTs reais em SQLite com compatibilidade explícita para GREATEST/LIKE, verificando agregações, filtros, fuso, paginação, autorização e remoção completa com rollback. O helper requer Python 3. Os testes de navegador verificam participante, dashboard por shortcode e painel administrativo; o painel usa a renderização e os controladores PHP reais com banco e K8 simulados.

Nenhum teste automatizado cria cobrança real. A versão inicial foi validada localmente com respostas simuladas; instalação no seu WordPress, autorização das credenciais, opções efetivas de parcelamento e callback real precisam de homologação na conta K8.

Contrato utilizado: [checkout hospedado K8](https://docs.meuk8.com.br/reference/criatransacaocheckout_criatransacaocheckout_criatransacaocheckout_post), [consulta por referência](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacaoreferencia_consultatransacaoreferencia_post), [consulta de transações por período](https://docs.meuk8.com.br/reference/consultatransacoes_consultatransacoes_consultatransacoes_post), [callbacks](https://docs.meuk8.com.br/docs/payload-callback) e Swagger oficial `/PagamentosAPI/swagger/docs/v1`, consultado nesta implementação.

## Autenticação recusada / HTTP 400

Na versão 1.0.1, o plugin envia o formulário OAuth explicitamente codificado, conserva símbolos como `+`, `&` e `=` das chaves e limpa espaços/quebras de linha acidentais nas bordas. O teste administrativo consulta novamente a API, mesmo quando existe um token em cache.

O erro agora informa **ambiente**, **API** e o motivo estruturado devolvido pelo K8, sem exibir chaves ou tokens. Se aparecer “As credenciais do usuário não conferem”, confirme Client ID e Client Secret gerados no **K8 Pagamentos**, o ambiente escolhido e a habilitação da API/IP do servidor. Chaves de produção precisam da opção Produção; o padrão inicial do plugin é sandbox. Salve a configuração antes de clicar em testar.

A autenticação foi comparada com a resposta pública do sandbox usando valores fictícios: o endpoint reconhece formulário OAuth e rejeita JSON com `unsupported_grant_type`. Nenhuma credencial real foi usada nessa verificação. A aprovação da sua conta ainda precisa ser testada no WordPress com suas chaves.

## Retorno bloqueado / erro no painel K8

Se o K8 registrar erro no envio da confirmação, confira **Inscrições → Acompanhamento → detalhe da inscrição**. Se a data do último retorno não mudar, a requisição pode ter sido bloqueada antes de chegar ao plugin. Confira os eventos de segurança do Cloudflare e os logs do servidor para a rota `/wp-json/inscricao-eventos/v1/webhook/`.

O Cloudflare Browser Integrity Check pode bloquear chamadas sem um navegador comum e devolver HTTP 403/erro 1010. Quando esse for o bloqueio identificado, crie uma regra limitada ao host do seu site, a esse caminho e aos métodos GET/POST. Use **Skip → Browser Integrity Check**, ou uma regra de configuração que desative essa verificação apenas nesse caminho. Exemplo, substituindo `seu-dominio.com.br` pelo host usado no checkout:

```text
(http.host eq "seu-dominio.com.br" and starts_with(http.request.uri.path, "/wp-json/inscricao-eventos/v1/webhook/") and http.request.method in {"POST" "GET"})
```

Essa configuração está documentada em [Browser Integrity Check — desativação seletiva](https://developers.cloudflare.com/waf/tools/browser-integrity-check/#disable-selectively). A assinatura do webhook e a validação financeira do plugin continuam obrigatórias.

Após atualizar o plugin e ajustar o bloqueio identificado, solicite ao K8 o reenvio da notificação da transação existente. Para conciliar um pagamento já realizado pelo painel WordPress, abra o detalhe da inscrição, informe o **ID completo da transação K8** (não o ID do checkout) e clique em **Consultar e conciliar no K8**. A ação consulta o K8 e não emite cobrança. Não peça outro pagamento para corrigir uma confirmação que falhou.

“Object reference not set to an instance of an object” é uma mensagem de exceção registrada pelo K8; o texto sozinho não identifica a resposta HTTP recebida nem demonstra que o erro nasceu no WordPress. Se persistir após a liberação da rota, solicite ao suporte K8 o status HTTP, o corpo da resposta e o ID completo da transação relativos à tentativa, ocultando tokens e dados pessoais.
